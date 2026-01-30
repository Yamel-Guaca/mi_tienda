<?php
// includes/image_upload.php

function ensure_upload_dir() {
    $dir = __DIR__ . '/../uploads/products/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return realpath($dir) . DIRECTORY_SEPARATOR;
}

function save_product_images($product_id, $files_array, PDO $pdo) {
    $upload_dir = ensure_upload_dir();
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

    $count = isset($files_array['name']) ? count($files_array['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        if (!isset($files_array['error'][$i]) || $files_array['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp_name = $files_array['tmp_name'][$i];
        $name     = $files_array['name'][$i];
        $size     = $files_array['size'][$i];

        // Validar extensión
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            continue;
        }

        // Validar tamaño (máx 5 MB)
        if ($size > 5 * 1024 * 1024) {
            continue;
        }

        // Validar MIME real con finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp_name) : mime_content_type($tmp_name);
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($mime, $allowedMime)) {
            continue;
        }

        // Generar nombre seguro y único
        $safe_name = 'p' . $product_id . '_' . time() . '_' . $i . '.' . $ext;
        $dest = $upload_dir . $safe_name;

        if (!move_uploaded_file($tmp_name, $dest)) {
            continue;
        }

        // posición: la última + 1
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM product_images WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $position = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO product_images (product_id, filename, position, is_main)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$product_id, $safe_name, $position]);
    }

    // Si no hay imagen principal, marcar la primera como principal
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_main = 1");
    $stmt->execute([$product_id]);
    $has_main = (int)$stmt->fetchColumn();

    if ($has_main === 0) {
        $stmt = $pdo->prepare("
            UPDATE product_images 
            SET is_main = 1 
            WHERE product_id = ? 
            ORDER BY position ASC 
            LIMIT 1
        ");
        $stmt->execute([$product_id]);
    }
}

function get_product_images($product_id, PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT id, filename, position, alt_text, is_main
        FROM product_images
        WHERE product_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function set_main_image($image_id, $product_id, PDO $pdo) {
    // quitar principal a todas
    $stmt = $pdo->prepare("UPDATE product_images SET is_main = 0 WHERE product_id = ?");
    $stmt->execute([$product_id]);

    // marcar principal a una
    $stmt = $pdo->prepare("UPDATE product_images SET is_main = 1 WHERE id = ? AND product_id = ?");
    $stmt->execute([$image_id, $product_id]);

    // actualizar columna image en products (para acceso rápido)
    $stmt = $pdo->prepare("SELECT filename FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $filename = $stmt->fetchColumn();

    if ($filename) {
        $stmt = $pdo->prepare("UPDATE products SET image = ? WHERE id = ?");
        $stmt->execute([$filename, $product_id]);
    }
}

function delete_product_image($image_id, $product_id, PDO $pdo) {
    // obtener filename e is_main antes de borrar
    $stmt = $pdo->prepare("SELECT filename, is_main FROM product_images WHERE id = ? AND product_id = ?");
    $stmt->execute([$image_id, $product_id]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$img) return;

    $file = __DIR__ . '/../uploads/products/' . $img['filename'];
    if (is_file($file)) {
        @unlink($file);
    }

    $stmt = $pdo->prepare("DELETE FROM product_images WHERE id = ? AND product_id = ?");
    $stmt->execute([$image_id, $product_id]);

    // Si borramos la principal, elegir otra como principal
    if ((int)$img['is_main'] === 1) {
        $stmt = $pdo->prepare("
            SELECT id, filename 
            FROM product_images 
            WHERE product_id = ? 
            ORDER BY position ASC 
            LIMIT 1
        ");
        $stmt->execute([$product_id]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($next) {
            $stmt = $pdo->prepare("UPDATE product_images SET is_main = 1 WHERE id = ?");
            $stmt->execute([$next['id']]);

            $stmt = $pdo->prepare("UPDATE products SET image = ? WHERE id = ?");
            $stmt->execute([$next['filename'], $product_id]);
        } else {
            // ya no hay imágenes
            $stmt = $pdo->prepare("UPDATE products SET image = NULL WHERE id = ?");
            $stmt->execute([$product_id]);
        }
    }
}

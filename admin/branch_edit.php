<?php
// admin/branch_edit.php
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1]); // Solo administradores

$pdo = DB::getConnection();

// Obtener ID de sucursal (si existe)
$branchId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Mensaje para mostrar al usuario
$message = '';

// Si se envía el formulario (crear o actualizar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $nit     = trim($_POST['nit'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $legend  = trim($_POST['invoice_legend'] ?? '');
    $logo    = trim($_POST['company_logo'] ?? '');
    $taxRate = isset($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : 0.0;

    // Manejo de archivo subido
    if (!empty($_FILES['logo_file']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        // Generar nombre seguro y único para evitar colisiones
        $ext = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($_FILES['logo_file']['name'], PATHINFO_FILENAME));
        $fileName = $safeName . '_' . time() . ($ext ? '.' . $ext : '');
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
            // Guardar ruta relativa para usar en la factura
            $logo = 'uploads/' . $fileName;
        }
    }

    if ($branchId > 0) {
        // Actualizar sucursal existente
        $stmt = $pdo->prepare("
            UPDATE branches 
            SET name=?, nit=?, address=?, phone=?, invoice_legend=?, company_logo=?, tax_rate=? 
            WHERE id=?
        ");
        $stmt->execute([$name, $nit, $address, $phone, $legend, $logo, $taxRate, $branchId]);
        $message = "Datos actualizados correctamente.";
        // Recargar datos
        $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch) {
            $message .= " (Advertencia: no se pudo recargar la sucursal.)";
        }
    } else {
        // Insertar nueva sucursal
        $stmt = $pdo->prepare("
            INSERT INTO branches (name, nit, address, phone, invoice_legend, company_logo, tax_rate)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $nit, $address, $phone, $legend, $logo, $taxRate]);
        $newId = $pdo->lastInsertId();
        $branchId = intval($newId);
        $message = "Sucursal creada correctamente.";
        // Redirigir a la página de edición de la nueva sucursal para evitar reenvío de formulario
        header('Location: branch_edit.php?id=' . $branchId);
        exit;
    }
} else {
    // Si no es POST y no hay id, mostramos formulario vacío (crear) o redirigimos al listado
    if ($branchId <= 0) {
        // Mostrar formulario vacío para crear nueva sucursal
        $branch = null;
    } else {
        // Cargar datos actuales para edición
        $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch) {
            die("Sucursal no encontrada.");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $branchId > 0 ? 'Editar sucursal' : 'Crear nueva sucursal' ?></title>
    <link rel="stylesheet" href="../public/css/admin.css">
    <style>
        body { font-family: Arial, sans-serif; padding: 18px; color:#222; }
        label { font-weight:600; }
        input[type="text"], input[type="file"] { width: 100%; max-width:480px; padding:8px; margin-top:4px; }
        .form-row { margin-bottom:14px; }
        .message { padding:10px; margin-bottom:12px; border-radius:4px; }
        .success { background:#e6ffed; border:1px solid #b7f0c6; color:#0a6b2f; }
        .info { background:#eef6ff; border:1px solid #cfe8ff; color:#0b4f8a; }
    </style>
</head>
<body>
    <h2><?= $branchId > 0 ? 'Editar sucursal: ' . htmlspecialchars($branch['name'] ?? '') : 'Crear nueva sucursal' ?></h2>

    <?php if (!empty($message)): ?>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <label>Nombre:</label><br>
            <input type="text" name="name" value="<?= htmlspecialchars($branch['name'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>NIT:</label><br>
            <input type="text" name="nit" value="<?= htmlspecialchars($branch['nit'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>Dirección:</label><br>
            <input type="text" name="address" value="<?= htmlspecialchars($branch['address'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>Teléfono:</label><br>
            <input type="text" name="phone" value="<?= htmlspecialchars($branch['phone'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>Leyenda factura:</label><br>
            <input type="text" name="invoice_legend" value="<?= htmlspecialchars($branch['invoice_legend'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>Logo (URL o ruta):</label><br>
            <input type="text" name="company_logo" value="<?= htmlspecialchars($branch['company_logo'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label>Subir logo desde el computador:</label><br>
            <input type="file" name="logo_file" accept="image/*">
            <?php if (!empty($branch['company_logo'])): ?>
                <div style="margin-top:8px;">
                    <strong>Logo actual:</strong><br>
                    <img src="<?= htmlspecialchars($branch['company_logo']) ?>" alt="Logo" style="max-width:200px; max-height:80px; margin-top:6px;">
                </div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <label>Tasa de impuesto (ej. 0 para ninguno, 0.19 para 19%):</label><br>
            <input type="text" name="tax_rate" value="<?= htmlspecialchars($branch['tax_rate'] ?? '0') ?>">
        </div>

        <div class="form-row">
            <button type="submit"><?= $branchId > 0 ? 'Guardar cambios' : 'Crear sucursal' ?></button>
            <?php if ($branchId > 0): ?>
                <a href="branch_list.php" style="margin-left:12px;">Volver al listado</a>
            <?php else: ?>
                <a href="branch_list.php" style="margin-left:12px;">Ver listado de sucursales</a>
            <?php endif; ?>
        </div>
    </form>
</body>
</html>

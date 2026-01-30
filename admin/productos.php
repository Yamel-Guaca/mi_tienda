<?php
// admin/productos.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_upload.php';

// ✅ Solo administrador
require_role([1]);

$pdo = DB::getConnection();

$msg = "";
$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ============================================================
// 1. SUCURSAL SELECCIONADA
// ============================================================

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ============================================================
// 2. CARGAR CATEGORÍAS
// ============================================================

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CARGAR SUBCATEGORÍAS (para mostrar nombres en la tabla de presentaciones)
// ============================================================
$subcategories = $pdo->query("SELECT id, name, category_id FROM subcategories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Preparar mapas id => nombre para JS
$categoriesMapJs = json_encode(array_column($categories, 'name', 'id'), JSON_UNESCAPED_UNICODE);
$subcategoriesMapJs = json_encode(array_column($subcategories, 'name', 'id'), JSON_UNESCAPED_UNICODE);

// ============================================================
// 3. ACCIONES SOBRE IMÁGENES
// ============================================================

$image_action = $_GET['image_action'] ?? null;
$image_id     = isset($_GET['image_id']) ? (int)$_GET['image_id'] : null;

if ($image_action && $id) {
    if ($image_action === 'set_main' && $image_id) {
        set_main_image($image_id, $id, $pdo);
        $msg = "Imagen marcada como principal.";
    }
    if ($image_action === 'delete' && $image_id) {
        delete_product_image($image_id, $id, $pdo);
        $msg = "Imagen eliminada.";
    }
}

// ============================================================
// Helper: calcular precio desde costo, IVA y margen
// ============================================================
function calculate_price_from_margin(
    float $cost_initial,
    int $packaging_qty,
    float $iva_percent,
    float $margin_percent,
    bool $rounding_enabled = true
): float {
    if ($packaging_qty <= 0) $packaging_qty = 1;
    $cost_unit = $cost_initial / $packaging_qty;
    $iva_amount = $cost_unit * ($iva_percent / 100.0);
    $total_cost_iva = $cost_unit + $iva_amount;
    $value_percent = $total_cost_iva * ($margin_percent / 100.0);
    $price_unit = $total_cost_iva + $value_percent;

    if ($rounding_enabled) {
        $nextHundred = ceil($price_unit / 100.0) * 100.0;
        $price_unit = $nextHundred;
    }

    return round($price_unit, 2);
}
// ============================================================
// 4. CREAR PRODUCTO
// ============================================================

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']);
    $sku    = trim($_POST['sku']);
    $price  = (float)($_POST['price'] ?? 0);
    $min_q  = (int)($_POST['min_quantity'] ?? 0);
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $sub_id = (int)($_POST['subcategory_id'] ?? 0);
    $active = 1;

    // Nuevos campos
    $cost_initial = floatval($_POST['cost_initial'] ?? 0);
    $packaging_type = $_POST['packaging_type'] ?? 'unidad';
    if ($packaging_type === 'otro') {
        $packaging_type = trim($_POST['packaging_type_custom'] ?? 'otro');
    }
    $packaging_qty = max(1, intval($_POST['packaging_qty'] ?? 1));
    $iva_percent = floatval($_POST['iva_percent'] ?? 0);
    $margin_percent = floatval($_POST['margin_percent'] ?? 0);
    $price_unit = floatval($_POST['price_unit'] ?? $price);

    // ✅ Nuevo campo rounding_enabled
    $rounding_enabled = isset($_POST['rounding_enabled']) ? (int)$_POST['rounding_enabled'] : 1;

    if ($name && $sku && $price_unit > 0 && $cat_id > 0 && $sub_id > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO products 
            (name, sku, price, min_quantity, category_id, subcategory_id, active,
             cost_initial, packaging_type, packaging_qty, iva_percent, margin_percent, price_unit, rounding_enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $sku, $price_unit, $min_q, $cat_id, $sub_id, $active,
            $cost_initial, $packaging_type, $packaging_qty, $iva_percent, $margin_percent, $price_unit, $rounding_enabled]);

        $new_id = (int)$pdo->lastInsertId();

        if (!empty($_FILES['images']['name'][0])) {
            save_product_images($new_id, $_FILES['images'], $pdo);
        }

        // Guardar price tiers
        $price_tiers_json = $_POST['price_tiers_json'] ?? '';
        if ($price_tiers_json) {
            $tiers = json_decode($price_tiers_json, true);
            if (is_array($tiers)) {
                $stmtDel = $pdo->prepare("DELETE FROM product_prices WHERE product_id=?");
                $stmtDel->execute([$new_id]);
                $stmtIns = $pdo->prepare("INSERT INTO product_prices (product_id, unit_label, quantity, price, margin_percent, category_id, subcategory_id, iva_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($tiers as $t) {
                    $t_label = $t['label'] ?? '';
                    $t_qty = intval($t['quantity'] ?? 1);
                    $t_margin = isset($t['margin']) ? floatval($t['margin']) : 0.0;
                    $t_price = isset($t['price']) ? floatval($t['price']) : 0.0;
                    if ($t_price <= 0) {
                        $t_price = calculate_price_from_margin($cost_initial, $packaging_qty, $iva_percent, $t_margin, (bool)$rounding_enabled);
                    }
                    $t_category = !empty($t['category_id']) ? intval($t['category_id']) : null;
                    $t_subcategory = !empty($t['subcategory_id']) ? intval($t['subcategory_id']) : null;
                    $t_iva = isset($t['iva_percent']) ? floatval($t['iva_percent']) : 0.0;

                    $stmtIns->execute([$new_id, $t_label, $t_qty, $t_price, $t_margin, $t_category, $t_subcategory, $t_iva]);
                }
            }
        }

        $msg = "Producto creado correctamente.";
        $action = null;
    } else {
        $msg = "Todos los campos obligatorios deben estar completos.";
    }
}

// ============================================================
// 5. EDITAR PRODUCTO
// ============================================================

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $name   = trim($_POST['name']);
    $sku    = trim($_POST['sku']);
    $price  = (float)($_POST['price'] ?? 0);
    $min_q  = (int)($_POST['min_quantity'] ?? 0);
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $sub_id = (int)($_POST['subcategory_id'] ?? 0);

    // Nuevos campos
    $cost_initial = floatval($_POST['cost_initial'] ?? 0);
    $packaging_type = $_POST['packaging_type'] ?? 'unidad';
    if ($packaging_type === 'otro') {
        $packaging_type = trim($_POST['packaging_type_custom'] ?? 'otro');
    }
    $packaging_qty = max(1, intval($_POST['packaging_qty'] ?? 1));
    $iva_percent = floatval($_POST['iva_percent'] ?? 0);
    $margin_percent = floatval($_POST['margin_percent'] ?? 0);
    $price_unit = floatval($_POST['price_unit'] ?? $price);

    // ✅ Nuevo campo rounding_enabled
    $rounding_enabled = isset($_POST['rounding_enabled']) ? (int)$_POST['rounding_enabled'] : 1;

    if ($name && $sku && $price_unit > 0 && $cat_id > 0 && $sub_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE products 
            SET name=?, sku=?, price=?, min_quantity=?, category_id=?, subcategory_id=?,
                cost_initial=?, packaging_type=?, packaging_qty=?, iva_percent=?, margin_percent=?, price_unit=?, rounding_enabled=?
            WHERE id=?
        ");
        $stmt->execute([$name, $sku, $price_unit, $min_q, $cat_id, $sub_id,
            $cost_initial, $packaging_type, $packaging_qty, $iva_percent, $margin_percent, $price_unit, $rounding_enabled, $id]);

        if (!empty($_FILES['images']['name'][0])) {
            save_product_images($id, $_FILES['images'], $pdo);
        }

        // Guardar price tiers
        $price_tiers_json = $_POST['price_tiers_json'] ?? '';
        if ($price_tiers_json) {
            $tiers = json_decode($price_tiers_json, true);
            if (is_array($tiers)) {
                $stmtDel = $pdo->prepare("DELETE FROM product_prices WHERE product_id=?");
                $stmtDel->execute([$id]);
                $stmtIns = $pdo->prepare("INSERT INTO product_prices (product_id, unit_label, quantity, price, margin_percent, category_id, subcategory_id, iva_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($tiers as $t) {
                    $t_label = $t['label'] ?? '';
                    $t_qty = intval($t['quantity'] ?? 1);
                    $t_margin = isset($t['margin']) ? floatval($t['margin']) : 0.0;
                    $t_price = isset($t['price']) ? floatval($t['price']) : 0.0;
                    if ($t_price <= 0) {
                        $t_price = calculate_price_from_margin($cost_initial, $packaging_qty, $iva_percent, $t_margin, (bool)$rounding_enabled);
                    }
                    $t_category = !empty($t['category_id']) ? intval($t['category_id']) : null;
                    $t_subcategory = !empty($t['subcategory_id']) ? intval($t['subcategory_id']) : null;
                    $t_iva = isset($t['iva_percent']) ? floatval($t['iva_percent']) : 0.0;

                    $stmtIns->execute([$id, $t_label, $t_qty, $t_price, $t_margin, $t_category, $t_subcategory, $t_iva]);
                }
            }
        }

        $msg = "Producto actualizado.";
    } else {
        $msg = "Todos los campos obligatorios deben estar completos.";
    }
}
// ============================================================
// 2.5 CARGAR DATOS PARA EDICIÓN Y LISTADO
// ============================================================

// Si estamos en edición, cargar el producto, sus imágenes y sus presentaciones
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtImg = $pdo->prepare("SELECT * FROM product_images WHERE product_id=?");
    $stmtImg->execute([$id]);
    $edit_images = $stmtImg->fetchAll(PDO::FETCH_ASSOC);

    $stmtTiers = $pdo->prepare("SELECT * FROM product_prices WHERE product_id=?");
    $stmtTiers->execute([$id]);
    $edit_price_tiers = $stmtTiers->fetchAll(PDO::FETCH_ASSOC);
}

// Siempre cargar la lista de productos para la tabla final
$products = $pdo->query("
    SELECT p.*, c.name AS category_name, s.name AS subcategory_name,
           (SELECT filename FROM product_images WHERE product_id=p.id AND is_main=1 LIMIT 1) AS main_image
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    ORDER BY p.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ... aquí termina la lógica PHP, ahora el HTML/JS sigue
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Admin - Productos</title>

  <!-- Bloque de estilos (restaurado / mejorado) -->
  <style>
    body { font-family: Arial, Helvetica, sans-serif; background:#f7f7f7; color:#222; margin:0; padding:0; }
    header { background:#0b5; padding:18px 24px; color:#fff; }
    header h2 { margin:0; font-size:20px; }
    .branch { margin-top:6px; font-size:13px; opacity:0.95; }
    .container { max-width:1100px; margin:18px auto; background:#fff; padding:18px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06); }
    .msg { background:#e6ffed; border:1px solid #b7f0c6; padding:10px; margin-bottom:12px; border-radius:6px; color:#0a6; }
    .btn { display:inline-block; padding:8px 12px; border-radius:6px; border:0; cursor:pointer; background:#eee; color:#222; text-decoration:none; }
    .btn.primary { background:#0078d4; color:#fff; }
    .btn.secondary { background:#6c757d; color:#fff; }
    .btn-create { background:#0b5; color:#fff; padding:10px 14px; border-radius:6px; text-decoration:none; }
    .small { font-size:13px; color:#444; }
    input[type="text"], input[type="number"], select { padding:8px; border:1px solid #ddd; border-radius:6px; margin:6px 0; width:100%; box-sizing:border-box; }
    input[readonly] { background:#f3f3f3; }
    label { display:block; margin-top:8px; font-weight:600; font-size:14px; }
    table { width:100%; border-collapse:collapse; margin-top:12px; }
    table th, table td { padding:8px 10px; border:1px solid #eee; text-align:left; font-size:14px; }
    table thead th { background:#fafafa; font-weight:700; }
    .thumb { width:64px; height:64px; object-fit:cover; border-radius:6px; }
    .gallery { display:flex; gap:12px; flex-wrap:wrap; margin-top:12px; }
    .gallery-item { position:relative; }
    .tag-main { position:absolute; left:6px; top:6px; background:#0078d4; color:#fff; padding:4px 6px; border-radius:4px; font-size:12px; }
    .btn-small-main, .btn-small-del { display:inline-block; margin-top:6px; padding:6px 8px; background:#eee; border-radius:6px; text-decoration:none; color:#222; font-size:13px; }
    #price-tiers-editor { margin-top:8px; }
    #price-tiers td, #price-tiers th { white-space:nowrap; }
    .btn-remove-tier { background:#ff6b6b; color:#fff; border:0; padding:6px 8px; border-radius:6px; cursor:pointer; }
    @media (max-width:900px) {
      .container { padding:12px; }
      .thumb { width:48px; height:48px; }
    }
  </style>
</head>
<body>
<header>
    <h2>Gestión de Productos</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
    <p class="small" style="margin-top:12px;">Enlaces rápidos:  
      <a href="/mi_tienda/admin/dashboard.php">Salir</a>
    </p>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<a href="/mi_tienda/admin/productos.php?action=new" class="btn btn-create">+ Crear Producto</a>
<?php if ($action === 'new'): ?>
<?php
// ✅ Calcular el próximo SKU automáticamente (ahora la columna es INT)
$stmt = $pdo->query("SELECT MAX(sku) AS last_sku FROM products");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$lastSku = $row['last_sku'] ?? 0;

// Si no hay productos, arrancar en 100
$nextSku = $lastSku > 0 ? $lastSku + 1 : 100;
?>

<h3>Nuevo Producto</h3>
<form method="POST" action="/mi_tienda/admin/productos.php?action=create" enctype="multipart/form-data">

    <input type="text" name="name" placeholder="Nombre del producto" required>
    <input type="text" name="sku" id="sku" value="<?= $nextSku ?>" readonly required>
    <input type="number" step="0.01" name="price" id="price_field" placeholder="Precio" readonly required>
    <input type="number" name="min_quantity" placeholder="Cantidad mínima (opcional)">

    <label>Categoría:</label>
    <select name="category_id" id="category_select" required>
        <option value="">Seleccione una categoría</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Subcategoría:</label>
    <select name="subcategory_id" id="subcategory_select" required>
        <option value="">Seleccione una subcategoría</option>
    </select>

    <label>Costo inicial</label>
    <input type="number" step="0.01" id="cost_initial" name="cost_initial" value="0">

    <label>Embalaje</label>
    <select id="packaging_type" name="packaging_type">
      <option value="unidad">Unidad</option>
      <option value="docena">Docena</option>
      <option value="caja">Caja</option>
      <option value="paca">Paca</option>
      <option value="kg">Kilogramo</option>
      <option value="millar">Millar</option>
      <option value="otro">Otro</option>
    </select>
    <input type="text" id="packaging_type_custom" name="packaging_type_custom" placeholder="Si eliges Otro">

    <label>Cantidad embalaje</label>
    <input type="number" id="packaging_qty" name="packaging_qty" min="1" value="1">

    <label>IVA %</label>
    <input type="number" step="0.01" id="iva_percent" name="iva_percent" value="0">

    <label>Porcentaje % (margen)</label>
    <input type="number" step="0.01" id="margin_percent" name="margin_percent" value="0">

    <div style="margin:10px 0; padding:8px; border:1px solid #eee; border-radius:6px;">
        <div>Costo unidad: <strong id="cost_unit">0.00</strong></div>
        <div>IVA por unidad: <strong id="iva_amount">0.00</strong></div>
        <div>Total costo + IVA: <strong id="total_cost_iva">0.00</strong></div>
        <div>Valor %: <strong id="value_percent">0.00</strong></div>
        <div>Ajuste: <strong id="adjustment">0.00</strong></div>
        <div>Precio unidad calculado: <strong id="price_unit_calc">0.00</strong></div>
    </div>

    <input type="hidden" id="price_unit_hidden" name="price_unit" value="0">
    <input type="hidden" id="adjustment_hidden" name="adjustment" value="0">

    <!-- Botón ajuste automático -->
    <div style="margin:10px 0;">
      <input type="hidden" id="rounding_enabled" name="rounding_enabled" value="1">
      <button type="button" id="toggleRoundingBtn" class="btn primary">
        Desactivar ajuste automático
      </button>
    </div>

    
    <h4>Precios por presentación</h4>
    <div id="price-tiers-editor">
      <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
        <select id="tier_label">
          <option value="unidad">Unidad</option>
          <option value="docena">Docena</option>
          <option value="caja">Caja</option>
          <option value="paca">Paca</option>
          <option value="otro">Otro</option>
        </select>
        <input type="text" id="tier_label_custom" placeholder="Etiqueta personalizada (opcional)" style="min-width:160px;">
        <input id="tier_qty" type="number" min="1" value="1" style="width:100px;" placeholder="Cantidad">
        <input id="tier_price" type="number" step="0.01" placeholder="Precio (opcional)" style="width:120px;">
        <input id="tier_margin" type="number" step="0.01" placeholder="Margen % (opcional)" style="width:100px;">
        <select id="tier_category" style="min-width:140px;">
          <option value="">Seleccione categoría</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="tier_subcategory" style="min-width:140px;">
          <option value="">Seleccione subcategoría</option>
        </select>
        <input id="tier_iva" type="number" step="0.01" placeholder="IVA %" style="width:80px;">
        <button type="button" class="btn" onclick="addPriceTier()">Agregar</button>
      </div>

      <table id="price-tiers" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>Pertenece a:</th>
            <th>Etiqueta</th>
            <th>Cantidad</th>
            <th>Precio</th>
            <th>Margen %</th>
            <th>Categoría</th>
            <th>Subcategoría</th>
            <th>IVA %</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <input type="hidden" id="price_tiers_json" name="price_tiers_json" value="">
    </div>

    <button class="btn btn-create">Guardar</button>
</form>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_product): ?>
    <h3>Editar Producto: <?= htmlspecialchars($edit_product['name']) ?></h3>
    <form method="POST" action="/mi_tienda/admin/productos.php?action=edit&id=<?= $edit_product['id'] ?>" enctype="multipart/form-data">
        <input type="text" name="name" value="<?= htmlspecialchars($edit_product['name']) ?>" required>
        <input type="text" name="sku" value="<?= htmlspecialchars($edit_product['sku']) ?>" required>
        <input type="number" step="0.01" name="price" id="price_field" value="<?= htmlspecialchars($edit_product['price']) ?>" readonly required>

        <input type="number" name="min_quantity" value="<?= htmlspecialchars($edit_product['min_quantity']) ?>">

        <label>Categoría:</label>
        <select name="category_id" id="category_select_edit" required>
            <option value="">Seleccione una categoría</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($edit_product['category_id'] == $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Subcategoría:</label>
        <select name="subcategory_id" id="subcategory_select_edit" required>
            <option value="">Cargando subcategorías...</option>
        </select>

        <label>Costo inicial</label>
        <input type="number" step="0.01" id="cost_initial" name="cost_initial" value="<?= htmlspecialchars($edit_product['cost_initial'] ?? 0) ?>">

        <label>Embalaje</label>
        <select id="packaging_type" name="packaging_type">
          <option value="unidad" <?= ($edit_product['packaging_type']=='unidad') ? 'selected' : '' ?>>Unidad</option>
          <option value="docena" <?= ($edit_product['packaging_type']=='docena') ? 'selected' : '' ?>>Docena</option>
          <option value="caja" <?= ($edit_product['packaging_type']=='caja') ? 'selected' : '' ?>>Caja</option>
          <option value="paca" <?= ($edit_product['packaging_type']=='paca') ? 'selected' : '' ?>>Paca</option>
          <option value="kg" <?= ($edit_product['packaging_type']=='kg') ? 'selected' : '' ?>>Kilogramo</option>
          <option value="millar" <?= ($edit_product['packaging_type']=='millar') ? 'selected' : '' ?>>Millar</option>
          <option value="otro" <?= ($edit_product['packaging_type']=='otro') ? 'selected' : '' ?>>Otro</option>
        </select>
        <input type="text" id="packaging_type_custom" name="packaging_type_custom" placeholder="Si eliges Otro" value="<?= htmlspecialchars($edit_product['packaging_type'] ?? '') ?>">

        <label>Cantidad embalaje</label>
        <input type="number" id="packaging_qty" name="packaging_qty" min="1" value="<?= htmlspecialchars($edit_product['packaging_qty'] ?? 1) ?>">

        <label>IVA %</label>
        <input type="number" step="0.01" id="iva_percent" name="iva_percent" value="<?= htmlspecialchars($edit_product['iva_percent'] ?? 0) ?>">

        <label>Porcentaje % (margen)</label>
        <input type="number" step="0.01" id="margin_percent" name="margin_percent" value="<?= htmlspecialchars($edit_product['margin_percent'] ?? 0) ?>">

        <div style="margin:10px 0; padding:8px; border:1px solid #eee; border-radius:6px;">
          <div>Costo unidad: <strong id="cost_unit">0.00</strong></div>
          <div>IVA por unidad: <strong id="iva_amount">0.00</strong></div>
          <div>Total costo + IVA: <strong id="total_cost_iva">0.00</strong></div>
          <div>Valor %: <strong id="value_percent">0.00</strong></div>
          <div>Precio unidad calculado: <strong id="price_unit_calc">0.00</strong></div>
        </div>

        <input type="hidden" id="price_unit_hidden" name="price_unit" value="<?= htmlspecialchars($edit_product['price_unit'] ?? $edit_product['price'] ?? 0) ?>">

        <label>Agregar más imágenes (puedes seleccionar varias):</label>
        <input type="file" name="images[]" multiple accept="image/*">

        <!-- Botón ajuste automático -->
        <div style="margin:10px 0;">
          <input type="hidden" id="rounding_enabled" name="rounding_enabled" value="<?= isset($edit_product['rounding_enabled']) ? (int)$edit_product['rounding_enabled'] : 1 ?>">
          <button type="button" id="toggleRoundingBtn" class="btn <?= !empty($edit_product['rounding_enabled']) ? 'primary' : 'secondary' ?>">
            <?= !empty($edit_product['rounding_enabled']) ? 'Desactivar ajuste automático' : 'Activar ajuste automático' ?>
          </button>
        </div>

        <script>
          document.addEventListener("DOMContentLoaded", function(){
            const btn = document.getElementById("toggleRoundingBtn");
            const hiddenField = document.getElementById("rounding_enabled");
            btn.addEventListener("click", function(){
              if (hiddenField.value === "1") {
                hiddenField.value = "0";
                this.textContent = "Activar ajuste automático";
                this.classList.remove("primary");
                this.classList.add("secondary");
              } else {
                hiddenField.value = "1";
                this.textContent = "Desactivar ajuste automático";
                this.classList.remove("secondary");
                this.classList.add("primary");
              }
              try { updatePriceCalc(); } catch(e) {}
            });
          });
        </script>

        <h4>Precios por presentación</h4>
<div id="price-tiers-editor">
  <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
    <select id="tier_label">
      <option value="unidad">Unidad</option>
      <option value="docena">Docena</option>
      <option value="caja">Caja</option>
      <option value="paca">Paca</option>
      <option value="otro">Otro</option>
    </select>

    <input type="text" id="tier_label_custom" placeholder="Etiqueta personalizada (opcional)" style="min-width:160px;">

    <input id="tier_qty" type="number" min="1" value="1" style="width:100px;" placeholder="Cantidad">

    <input id="tier_price" type="number" step="0.01" placeholder="Precio (opcional)" style="width:120px;">

    <input id="tier_margin" type="number" step="0.01" placeholder="Margen % (opcional)" style="width:100px;">

    <select id="tier_category" style="min-width:140px;">
      <option value="">Seleccione categoría</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <select id="tier_subcategory" style="min-width:140px;">
      <option value="">Seleccione subcategoría</option>
    </select>

    <input id="tier_iva" type="number" step="0.01" placeholder="IVA %" style="width:80px;">

    <button type="button" class="btn" onclick="addPriceTier()">Agregar</button>
  </div>

  <table id="price-tiers" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th>Pertenece a:</th>
        <th>Etiqueta</th>
        <th>Cantidad</th>
        <th>Precio</th>
        <th>Margen %</th>
        <th>Categoría</th>
        <th>Subcategoría</th>
        <th>IVA %</th>
        <th></th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>

  <input type="hidden" id="price_tiers_json" name="price_tiers_json" value="">
</div>

        <button class="btn btn-create">Actualizar</button>
    </form>

    <h3>Galería de Imágenes</h3>
    <?php if ($edit_images): ?>
        <div class="gallery">
            <?php foreach ($edit_images as $img): ?>
                <div class="gallery-item">
                    <img class="thumb" src="/mi_tienda/uploads/products/<?= htmlspecialchars($img['filename']) ?>" alt="">
                    <?php if ($img['is_main']): ?>
                        <div class="tag-main">Principal</div>
                    <?php endif; ?>
                    <a class="btn-small-main" href="/mi_tienda/admin/productos.php?action=edit&id=<?= $edit_product['id'] ?>&image_action=set_main&image_id=<?= $img['id'] ?>">Marcar principal</a>
                    <a class="btn-small-del" href="/mi_tienda/admin/productos.php?action=edit&id=<?= $edit_product['id'] ?>&image_action=delete&image_id=<?= $img['id'] ?>" onclick="return confirm('¿Eliminar esta imagen?')">Eliminar</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>Este producto aún no tiene imágenes.</p>
    <?php endif; ?>
<?php endif; ?>

<!-- ===========================
     SCRIPTS PARA CÁLCULOS Y PRECIOS POR PRESENTACIÓN
=========================== -->

<!-- Inyectar mapas de categorías y subcategorías para JS (robusto) -->
<script>
  let categoriesMap = {};
  let subcategoriesMap = {};
  try {
    categoriesMap = <?= $categoriesMapJs ?: '{}' ?>;
  } catch(e) {
    console.error('Error parsing categoriesMap JSON', e);
    categoriesMap = {};
  }
  try {
    subcategoriesMap = <?= $subcategoriesMapJs ?: '{}' ?>;
  } catch(e) {
    console.error('Error parsing subcategoriesMap JSON', e);
    subcategoriesMap = {};
  }
</script>

<?php
// Inyectar tiers desde PHP cuando estamos en edición, marcando 'auto' si el precio coincide con el calculado
if (!empty($edit_price_tiers) && is_array($edit_price_tiers) && isset($edit_product)) {
    $ctx_cost = floatval($edit_product['cost_initial'] ?? 0);
    $ctx_pack_qty = max(1, intval($edit_product['packaging_qty'] ?? 1));
    $ctx_rounding = !empty($edit_product['rounding_enabled']) ? true : false;

    $jsTiers = array_map(function($r) use ($ctx_cost, $ctx_pack_qty, $ctx_rounding) {
        $price = number_format((float)$r['price'],2,'.','');
        $margin = number_format((float)($r['margin_percent'] ?? 0),2,'.','');
        $t_category = isset($r['category_id']) ? $r['category_id'] : null;
        $t_subcategory = isset($r['subcategory_id']) ? $r['subcategory_id'] : null;
        $t_iva = number_format((float)($r['iva_percent'] ?? 0),2,'.','');

        // calcular precio esperado desde margen con el contexto actual
        $expected = calculate_price_from_margin($ctx_cost, $ctx_pack_qty, floatval($t_iva), floatval($margin), $ctx_rounding);
        // tolerancia pequeña para comparar floats
        $autoFlag = (abs(floatval($price) - floatval($expected)) < 0.01) && (floatval($margin) > 0);

        return [
            'label' => $r['unit_label'],
            'quantity' => intval($r['quantity']),
            'price' => $price,
            'margin' => $margin,
            'category_id' => $t_category,
            'subcategory_id' => $t_subcategory,
            'iva_percent' => $t_iva,
            'auto' => $autoFlag ? true : false
        ];
    }, $edit_price_tiers);

    // inyectar JSON
    ?>
    <script>
      let tiers = <?= json_encode($jsTiers, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php
} else {
    // inicializar tiers vacío si no hay edición
    ?>
    <script>let tiers = [];</script>
    <?php
}
?>

<script>
function loadSubcategories(categoryId, targetSelect, selectedId = null) {
    if (!targetSelect) return; // protección adicional
    if (!categoryId) {
        targetSelect.innerHTML = '<option value="">Seleccione una subcategoría</option>';
        return;
    }

    fetch('/mi_tienda/admin/ajax/get_subcategories.php?category_id=' + encodeURIComponent(categoryId))
        .then(response => response.json())
        .then(data => {
            targetSelect.innerHTML = '<option value="">Seleccione una subcategoría</option>';
            data.forEach(sub => {
                let opt = document.createElement('option');
                opt.value = sub.id;
                opt.textContent = sub.name;
                if (selectedId && String(selectedId) === String(sub.id)) {
                    opt.selected = true;
                }
                targetSelect.appendChild(opt);
            });
        })
        .catch(err => {
            console.error('Error cargando subcategorías:', err);
            targetSelect.innerHTML = '<option value="">Error cargando subcategorías</option>';
        });
}

// ✅ Formulario de creación (enlazado de forma resiliente)
(function(){
  const catNew = document.getElementById('category_select');
  const subNew = document.getElementById('subcategory_select');
  if (catNew && subNew) {
      catNew.addEventListener('change', () => {
          loadSubcategories(catNew.value, subNew);
      });
  }
})();

// ✅ Formulario de edición (enlazado de forma resiliente)
(function(){
  const catEdit = document.getElementById('category_select_edit');
  const subEdit = document.getElementById('subcategory_select_edit');
  if (catEdit && subEdit) {
      try {
        loadSubcategories(catEdit.value, subEdit, <?= isset($edit_product['subcategory_id']) ? (int)$edit_product['subcategory_id'] : 'null' ?>);
      } catch(e) {}
      catEdit.addEventListener('change', () => {
          loadSubcategories(catEdit.value, subEdit);
      });
  }
})();
</script>

<script>
/* ===========================
   CÁLCULO EN TIEMPO REAL DEL PRECIO UNITARIO
   =========================== */
function updatePriceCalc() {
    const costEl = document.getElementById('cost_initial');
    const packQtyEl = document.getElementById('packaging_qty');
    const ivaEl = document.getElementById('iva_percent');
    const marginEl = document.getElementById('margin_percent');
    const priceField = document.getElementById('price_field');
    const priceHidden = document.getElementById('price_unit_hidden');

    if (!costEl || !packQtyEl || !ivaEl || !marginEl || !priceHidden) return;

    const cost_initial = parseFloat(costEl.value || 0);
    let packaging_qty = parseInt(packQtyEl.value || 1, 10);
    if (!packaging_qty || packaging_qty <= 0) packaging_qty = 1;
    const iva_percent = parseFloat(ivaEl.value || 0);
    const margin_percent = parseFloat(marginEl.value || 0);

    const cost_unit = packaging_qty ? (cost_initial / packaging_qty) : 0;
    const iva_amount = cost_unit * (iva_percent / 100.0);
    const total_cost_iva = cost_unit + iva_amount;
    const value_percent = total_cost_iva * (margin_percent / 100.0);
    let price_unit_raw = total_cost_iva + value_percent;

    // ✅ Ajuste según rounding_enabled
    const roundingEnabledEl = document.getElementById('rounding_enabled');
    const roundingEnabled = roundingEnabledEl ? (roundingEnabledEl.value === "1") : true;
    let adjustment = 0;
    if (roundingEnabled) {
        const nextHundred = Math.ceil(price_unit_raw / 100.0) * 100.0;
        adjustment = nextHundred - price_unit_raw;
        price_unit_raw = nextHundred;
    }

    const fmt = v => Number(v || 0).toFixed(2);
    const costUnitEl = document.getElementById('cost_unit');
    const ivaAmountEl = document.getElementById('iva_amount');
    const totalCostIvaEl = document.getElementById('total_cost_iva');
    const valuePercentEl = document.getElementById('value_percent');
    const adjustmentEl = document.getElementById('adjustment');
    const priceUnitCalcEl = document.getElementById('price_unit_calc');

    if (costUnitEl) costUnitEl.textContent = fmt(cost_unit);
    if (ivaAmountEl) ivaAmountEl.textContent = fmt(iva_amount);
    if (totalCostIvaEl) totalCostIvaEl.textContent = fmt(total_cost_iva);
    if (valuePercentEl) valuePercentEl.textContent = fmt(value_percent);
    if (adjustmentEl) adjustmentEl.textContent = fmt(adjustment);
    if (priceUnitCalcEl) priceUnitCalcEl.textContent = fmt(price_unit_raw);

    priceHidden.value = Number(price_unit_raw).toFixed(2);
    if (priceField) priceField.value = Number(price_unit_raw).toFixed(2);

    // Recalcular precios automáticos de tiers
    try { updateTiersPrices(); } catch(e) { console.error('updateTiersPrices error', e); }
}

// Vincular eventos para recalcular en tiempo real
['cost_initial','packaging_qty','iva_percent','margin_percent'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', updatePriceCalc);
        el.addEventListener('change', updatePriceCalc);
    }
});

// Ejecutar al cargar la página
document.addEventListener('DOMContentLoaded', function(){
    try { updatePriceCalc(); } catch(e) {}
});
</script>

<script>
/* ===========================
   Editor de price tiers JS (con margen y cálculo automático)
   =========================== */
(function(){
  // seleccionar tbody del primer price-tiers que exista
  const tiersTableBody = document.querySelector('#price-tiers tbody');
  const tierLabel = document.getElementById('tier_label');
  const tierLabelCustom = document.getElementById('tier_label_custom');
  const tierQty = document.getElementById('tier_qty');
  const tierPrice = document.getElementById('tier_price');
  const tierMargin = document.getElementById('tier_margin');
  const hiddenJson = document.getElementById('price_tiers_json');

  // selects dinámicos (puede haber duplicados en la página)
  const tierCategory = document.querySelector('#tier_category');
  const tierSubcategory = document.querySelector('#tier_subcategory');
  const tierIva = document.querySelector('#tier_iva');

  // 'tiers' ya fue inyectado por PHP arriba (edición) o inicializado vacío
  if (typeof tiers === 'undefined') tiers = [];

  function getProductCostContext() {
    const cost_initial = parseFloat(document.getElementById('cost_initial')?.value || 0);
    const packaging_qty = parseInt(document.getElementById('packaging_qty')?.value || 1, 10) || 1;
    const iva_percent = parseFloat(document.getElementById('iva_percent')?.value || 0);
    return { cost_initial, packaging_qty, iva_percent };
  }

  function calcPriceFromMargin(cost_initial, packaging_qty, iva_percent, margin_percent) {
    if (!packaging_qty || packaging_qty <= 0) packaging_qty = 1;
    const costUnit = cost_initial / packaging_qty;
    const ivaAmount = costUnit * (iva_percent / 100.0);
    const totalCostIva = costUnit + ivaAmount;
    const valuePercent = totalCostIva * (margin_percent / 100.0);
    let priceUnit = totalCostIva + valuePercent;

    const roundingEnabledEl = document.getElementById('rounding_enabled');
    const roundingEnabled = roundingEnabledEl ? (roundingEnabledEl.value === "1") : true;

    if (roundingEnabled) {
      const nextHundred = Math.ceil(priceUnit / 100.0) * 100.0;
      priceUnit = nextHundred;
    }

    return Number(priceUnit.toFixed(2));
  }

  // Enlazar selects de tier_category con su subcategoria correspondiente (resiliente)
  document.querySelectorAll('[id="tier_category"]').forEach(function(catEl){
    // buscar el contenedor más cercano que agrupa los controles
    let container = catEl.closest('#price-tiers-editor') || catEl.parentElement;
    let subEl = container ? container.querySelector('[id="tier_subcategory"]') : null;
    if (!subEl) subEl = document.querySelector('[id="tier_subcategory"]');
    if (subEl) {
      catEl.addEventListener('change', function(){
        loadSubcategories(this.value, subEl);
      });
    }
  });

  window.addPriceTier = function() {
    const custom = (tierLabelCustom && tierLabelCustom.value) ? String(tierLabelCustom.value).trim() : '';
    const label = (custom || (tierLabel ? tierLabel.value : '')).trim();
    const qty = parseInt(tierQty.value) || 1;
    let price = parseFloat(tierPrice.value) || 0;
    const margin = parseFloat(tierMargin.value || 0);

    if (!label || qty <= 0) {
      alert('Ingrese etiqueta y cantidad válidas.');
      return;
    }

    let autoFlag = false;
    if ((!price || price <= 0) && !isNaN(margin) && margin > 0) {
      const ctx = getProductCostContext();
      price = calcPriceFromMargin(ctx.cost_initial, ctx.packaging_qty, ctx.iva_percent, margin);
      autoFlag = true;
    }

    if (!price || price <= 0) {
      alert('Ingrese un precio válido o un margen para calcularlo.');
      return;
    }

    const categoryId = tierCategory ? tierCategory.value : '';
    const subcategoryId = tierSubcategory ? tierSubcategory.value : '';
    const ivaPercent = tierIva ? parseFloat(tierIva.value || 0) : 0;

    const exists = tiers.find(t => 
      t.label === label && 
      t.quantity === qty && 
      Number(t.price) === Number(price) && 
      Number(t.margin || 0) === Number(margin || 0) && 
      String(t.category_id||'') === String(categoryId||'') && 
      String(t.subcategory_id||'') === String(subcategoryId||'') && 
      Number(t.iva_percent||0) === Number(ivaPercent||0)
    );
    if (exists) {
      alert('Esa presentación ya fue agregada.');
      return;
    }

    const item = {
      label: label,
      quantity: qty,
      price: Number(price).toFixed(2),
      margin: Number(margin || 0).toFixed(2),
      category_id: categoryId || null,
      subcategory_id: subcategoryId || null,
      iva_percent: (isNaN(ivaPercent) ? 0 : Number(ivaPercent).toFixed(2)),
      auto: autoFlag
    };
    tiers.push(item);
    renderTiers();
    clearTierInputs();
  };

  function clearTierInputs() {
    if (tierQty) tierQty.value = 1;
    if (tierPrice) tierPrice.value = '';
    if (tierLabel) tierLabel.value = 'unidad';
    if (tierLabelCustom) tierLabelCustom.value = '';
    if (tierMargin) tierMargin.value = '';
    if (tierIva) tierIva.value = '';
    if (tierCategory) tierCategory.value = '';
    if (tierSubcategory) tierSubcategory.innerHTML = '<option value="">Seleccione subcategoría</option>';
  }

  function renderTiers() {
    if (!tiersTableBody) return;
    tiersTableBody.innerHTML = '';
    tiers.forEach((t, idx) => {
      // resolver nombres desde los mapas inyectados por PHP
      const catName = (t.category_id && categoriesMap && categoriesMap[t.category_id]) ? categoriesMap[t.category_id] : '-';
      const subName = (t.subcategory_id && subcategoriesMap && subcategoriesMap[t.subcategory_id]) ? subcategoriesMap[t.subcategory_id] : (t.subcategory_id ? t.subcategory_id : '-');

      const autoTag = t.auto ? ' <small style="color:#0078d4; font-weight:700;">(Auto)</small>' : '';

      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + escapeHtml(catName) + '</td>' +
                     '<td>' + escapeHtml(t.label) + autoTag + '</td>' +
                     '<td>' + escapeHtml(String(t.quantity)) + '</td>' +
                     '<td>$' + escapeHtml(String(Number(t.price).toFixed(2))) + '</td>' +
                     '<td>' + escapeHtml(String(Number(t.margin || 0).toFixed(2))) + '%</td>' +
                     '<td>' + escapeHtml(t.category_id || '-') + '</td>' +
                     '<td>' + escapeHtml(subName) + '</td>' +
                     '<td>' + escapeHtml(String(Number(t.iva_percent || 0).toFixed(2))) + '%</td>' +
                     '<td><button type="button" class="btn-remove-tier" data-idx="'+idx+'">Eliminar</button></td>';
      tiersTableBody.appendChild(tr);
    });

    tiersTableBody.querySelectorAll('.btn-remove-tier').forEach(btn=>{
      btn.addEventListener('click', function(){
        const i = parseInt(this.getAttribute('data-idx'));
        if (!isNaN(i)) {
          tiers.splice(i,1);
          renderTiers();
        }
      });
    });

    if (hiddenJson) hiddenJson.value = JSON.stringify(tiers);
  }

  // Recalcula los precios de los tiers marcados como auto
  function updateTiersPrices() {
    if (!Array.isArray(tiers) || tiers.length === 0) return;
    const ctx = getProductCostContext();
    let changed = false;
    tiers = tiers.map(t => {
      const margin = parseFloat(t.margin || 0);
      if (t.auto && !isNaN(margin) && margin > 0) {
        const newPrice = calcPriceFromMargin(ctx.cost_initial, ctx.packaging_qty, parseFloat(t.iva_percent || ctx.iva_percent || 0), margin);
        if (Number(newPrice).toFixed(2) !== Number(t.price).toFixed(2)) {
          t.price = Number(newPrice).toFixed(2);
          changed = true;
        }
      }
      return t;
    });
    if (changed) {
      renderTiers();
    } else {
      if (hiddenJson) hiddenJson.value = JSON.stringify(tiers);
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(m){ 
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; 
    });
  }

  // Inicializar si estamos en edición (ya inyectado por PHP)
  document.addEventListener('DOMContentLoaded', function(){ renderTiers(); });

  // Antes de enviar el formulario, serializar tiers y recalcular automáticos
  document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', function(){
      try {
        updateTiersPrices();
      } catch(err) {
        console.error('Error actualizando tiers antes de submit', err);
      }
      if (hiddenJson) hiddenJson.value = JSON.stringify(tiers);
      try { updatePriceCalc(); } catch(e) {}
    });
  });

})();
</script>

<h3>Lista de Productos</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Imagen</th>
            <th>Nombre</th>
            <th>SKU</th>
            <th>Precio</th>
            <th>Mínimo</th>
            <th>Categoría</th>
            <th>Subcategoría</th>
            <th>Activo</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['id']) ?></td>
            <td>
                <?php if (!empty($p['main_image'])): ?>
                    <img class="thumb" src="/mi_tienda/uploads/products/<?= htmlspecialchars($p['main_image']) ?>" alt="">
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['sku']) ?></td>
            <td>$<?= number_format((float)($p['price'] ?? 0), 2) ?></td>
            <td><?= (int)($p['min_quantity'] ?? 0) ?></td>
            <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($p['subcategory_name'] ?? '-') ?></td>
            <td><?= !empty($p['active']) ? '✅' : '❌' ?></td>
            <td>
                <a class="btn btn-edit" href="/mi_tienda/admin/productos.php?action=edit&id=<?= (int)$p['id'] ?>">Editar</a>
                <a class="btn btn-toggle" href="/mi_tienda/admin/productos.php?action=toggle&id=<?= (int)$p['id'] ?>">Activar/Desactivar</a>
                <a class="btn btn-delete" href="/mi_tienda/admin/productos.php?action=delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('¿Eliminar producto e imágenes?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div> <!-- cierre container -->
</body>
</html>

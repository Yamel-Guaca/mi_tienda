<?php
/**
 * Módulo de Gestión de Productos
 * Permite listar, crear, editar, eliminar y gestionar precios e imágenes por presentación.
 */

// ==========================================
// 0. CONFIGURACIÓN, DEPENDENCIAS Y SEGURIDAD
// ==========================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_upload.php';

require_role([1]);
$pdo = DB::getConnection();

$msg = '';
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$currentBranchId = $_SESSION['branch_id'] ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) die('No hay sucursal seleccionada. Vuelva al dashboard.');
if (!$currentBranchName) $currentBranchName = 'No seleccionada';

$edit_product = null;
$edit_images = [];
$edit_price_tiers = [];
$branchMinimums = [];

// ==========================================
// 1. CARGA GLOBAL DE TABLAS
// ==========================================
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subcategories = $pdo->query("SELECT id, name, category_id FROM subcategories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categoriesMapJs = json_encode(array_column($categories, 'name', 'id'), JSON_UNESCAPED_UNICODE);
$subcategoriesMapJs = json_encode(array_column($subcategories, 'name', 'id'), JSON_UNESCAPED_UNICODE);

// ==========================================
// 2. ACCIONES RÁPIDAS SOBRE IMÁGENES (GET)
// ==========================================
$image_action = $_GET['image_action'] ?? null;
$image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : null;

if ($image_action && $id) {
    if ($image_action === 'set_main' && $image_id) {
        set_main_image($image_id, $id, $pdo);
        $msg = 'Imagen marcada como principal.';
    }
    if ($image_action === 'delete' && $image_id) {
        delete_product_image($image_id, $id, $pdo);
        $msg = 'Imagen eliminada.';
    }
}

// ==========================================
// 3. HELPERS DE CÁLCULO Y DESGLOSE DE COSTOS
// ==========================================
function getBaseCostForPresentation(array $product, int $quantitySold, string $unitType = 'package'): float {
    $costInitial = (float)($product['cost_initial'] ?? 0);
    $packagingQty = max(1, (int)($product['packaging_qty'] ?? 1));
    $hasInner = !empty($product['has_inner_units']);
    $innerQty = max(1, (int)($product['inner_unit_qty'] ?? 1));
    $costPackaging = $costInitial;
    $costPackage = $costInitial / $packagingQty;
    $costIndividual = $hasInner ? ($costPackage / $innerQty) : $costPackage;

    switch ($unitType) {
        case 'individual': 
            return $costIndividual * $quantitySold;
        case 'inner': 
            return $costPackage * $quantitySold;
        case 'package':
        default: 
            return $costPackaging * $quantitySold;
    }
}

function get_product_cost_breakdown(float $cost_initial, int $packaging_qty, int $has_inner_units, ?int $inner_unit_qty): array {
    $safe_packaging_qty = max(1, $packaging_qty);
    $safe_inner_qty = ($has_inner_units && $inner_unit_qty !== null && $inner_unit_qty > 0) ? $inner_unit_qty : 1;
    
    return [
        'cost_packaging' => $cost_initial,
        'cost_package'   => $cost_initial / $safe_packaging_qty,
        'cost_unit'      => ($cost_initial / $safe_packaging_qty) / $safe_inner_qty,
        'total_units'    => $safe_packaging_qty * $safe_inner_qty,
        'has_inner'      => (bool)$has_inner_units
    ];
}

function calculate_price_from_margin(float $cost_initial, int $packaging_qty, float $iva_percent, float $margin_percent, bool $rounding_enabled = true): float {
    if ($packaging_qty <= 0) $packaging_qty = 1;
    
    $cost_unit = $cost_initial / $packaging_qty;
    $iva_amount = $cost_unit * ($iva_percent / 100.0);
    $total_cost_iva = $cost_unit + $iva_amount;
    $value_percent = $total_cost_iva * ($margin_percent / 100.0);
    $price_unit = $total_cost_iva + $value_percent;
    
    if ($rounding_enabled) {
        $price_unit = ceil($price_unit / 100.0) * 100.0;
    }
    
    return round($price_unit, 2);
}

// ==========================================
// 3.5 ACTIVAR / DESACTIVAR PRODUCTO
// ==========================================
if ($action === 'toggle' && $id) {
    try {
        $stmt = $pdo->prepare("UPDATE products SET active = IF(active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        $msg = 'Estado del producto actualizado correctamente.';
        $action = null;
    } catch (Exception $e) {
        error_log('Error cambiando estado del producto: ' . $e->getMessage());
        $msg = 'Ocurrió un error al cambiar el estado del producto.';
    }
}

// ==========================================
// 3.6 ELIMINAR PRODUCTO
// ==========================================
if ($action === 'delete' && $id) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM product_prices WHERE product_id = ?");
        $stmt->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM product_branch_minimums WHERE product_id = ?");
        $stmt->execute([$id]);
        $stmt = $pdo->prepare("SELECT id FROM product_images WHERE product_id = ?");
        $stmt->execute([$id]);
        $imagesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($imagesToDelete as $image) delete_product_image((int)$image['id'], $id, $pdo);
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $pdo->commit();
        $msg = 'Producto eliminado correctamente.';
        $action = null;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Error eliminando producto: ' . $e->getMessage());
        $msg = 'Ocurrió un error al eliminar el producto.';
    }
}

// ============================================================
// 4. CREAR PRODUCTO
// ============================================================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $min_q = max(0, (int)($_POST['min_quantity'] ?? 0));
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $sub_id = (int)($_POST['subcategory_id'] ?? 0);
    $active = 1;
    $cost_initial = floatval($_POST['cost_initial'] ?? 0);
    $packaging_type = $_POST['packaging_type'] ?? 'unidad';
    if ($packaging_type === 'otro') $packaging_type = trim($_POST['packaging_type_custom'] ?? 'otro');
    $packaging_qty = max(1, intval($_POST['packaging_qty'] ?? 1));
    $iva_percent = floatval($_POST['iva_percent'] ?? 0);
    $margin_percent = floatval($_POST['margin_percent'] ?? 0);
    $price_unit = floatval($_POST['price_unit'] ?? $price);
    $rounding_enabled = isset($_POST['rounding_enabled']) ? (int)$_POST['rounding_enabled'] : 1;
    $has_inner_units = isset($_POST['has_inner_units']) && $_POST['has_inner_units'] == '1' ? 1 : 0;
    $inner_unit_qty = ($has_inner_units === 1 && !empty($_POST['inner_unit_qty'])) ? max(1, (int)$_POST['inner_unit_qty']) : null;
    $branch_minimums_input = $_POST['branch_minimums'] ?? [];

    if ($name && $sku && $price_unit > 0 && $cat_id > 0 && $sub_id > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO products (name, sku, price, min_quantity, category_id, subcategory_id, active, cost_initial, packaging_type, packaging_qty, iva_percent, margin_percent, price_unit, rounding_enabled, has_inner_units, inner_unit_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $sku, $price_unit, $min_q, $cat_id, $sub_id, $active, $cost_initial, $packaging_type, $packaging_qty, $iva_percent, $margin_percent, $price_unit, $rounding_enabled, $has_inner_units, $inner_unit_qty]);
            $new_id = (int)$pdo->lastInsertId();

            if (!empty($_FILES['images']['name'][0])) save_product_images($new_id, $_FILES['images'], $pdo);

            if (is_array($branch_minimums_input) && count($branch_minimums_input) > 0) {
                $stmtMin = $pdo->prepare("INSERT INTO product_branch_minimums (product_id, branch_id, min_quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE min_quantity = VALUES(min_quantity)");
                foreach ($branch_minimums_input as $branch_id => $min_val) {
                    $b_id = (int)$branch_id;
                    if ($b_id <= 0) continue;
                    $stmtMin->execute([$new_id, $b_id, max(0, (int)$min_val)]);
                }
            }

            $price_tiers_json = $_POST['price_tiers_json'] ?? '';
            if ($price_tiers_json) {
                $tiersDecoded = json_decode($price_tiers_json, true);
                if (is_array($tiersDecoded)) {
                    $stmtIns = $pdo->prepare("INSERT INTO product_prices (product_id, unit_label, unit_level, quantity, price, margin_percent, category_id, subcategory_id, iva_percent, image_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($tiersDecoded as $t) {
                        $t_label = $t['label'] ?? '';
                        $t_level = $t['level'] ?? 'package';
                        if (!in_array($t_level, ['package', 'inner', 'individual'], true)) $t_level = 'package';
                        $t_qty = max(1, intval($t['quantity'] ?? 1));
                        $t_margin = isset($t['margin']) ? floatval($t['margin']) : 0.0;
                        $t_price = isset($t['price']) ? floatval($t['price']) : 0.0;
                        if ($t_price <= 0) $t_price = calculate_price_from_margin($cost_initial, $packaging_qty, $iva_percent, $t_margin, (bool)$rounding_enabled);
                        $t_category = !empty($t['category_id']) ? intval($t['category_id']) : null;
                        $t_subcategory = !empty($t['subcategory_id']) ? intval($t['subcategory_id']) : null;
                        $t_iva = isset($t['iva_percent']) ? floatval($t['iva_percent']) : 0.0;
                        $t_image_id = !empty($t['image_id']) ? intval($t['image_id']) : null;
                        $stmtIns->execute([$new_id, $t_label, $t_level, $t_qty, $t_price, $t_margin, $t_category, $t_subcategory, $t_iva, $t_image_id]);
                    }
                }
            }

            $pdo->commit();
            $msg = 'Producto creado correctamente.';
            $action = null;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Error creando producto: ' . $e->getMessage());
            $msg = 'Ocurrió un error al crear el producto. Intente nuevamente.';
        }
    } else {
        $msg = 'Todos los campos obligatorios deben estar completos.';
    }
}

// ============================================================
// 5. EDITAR PRODUCTO
// ============================================================
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $min_q = max(0, (int)($_POST['min_quantity'] ?? 0));
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $sub_id = (int)($_POST['subcategory_id'] ?? 0);
    $cost_initial = floatval($_POST['cost_initial'] ?? 0);
    $packaging_type = $_POST['packaging_type'] ?? 'unidad';
    if ($packaging_type === 'otro') $packaging_type = trim($_POST['packaging_type_custom'] ?? 'otro');
    $packaging_qty = max(1, intval($_POST['packaging_qty'] ?? 1));
    $iva_percent = floatval($_POST['iva_percent'] ?? 0);
    $margin_percent = floatval($_POST['margin_percent'] ?? 0);
    $price_unit = floatval($_POST['price_unit'] ?? $price);
    $rounding_enabled = isset($_POST['rounding_enabled']) ? (int)$_POST['rounding_enabled'] : 1;
    $visible_web = isset($_POST['visible_web']) ? (int)$_POST['visible_web'] : 1;
    $has_inner_units = isset($_POST['has_inner_units']) && $_POST['has_inner_units'] == '1' ? 1 : 0;
    $inner_unit_qty = ($has_inner_units === 1 && !empty($_POST['inner_unit_qty'])) ? max(1, (int)$_POST['inner_unit_qty']) : null;

    if ($name && $sku && $price_unit > 0 && $cat_id > 0 && $sub_id > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, price=?, min_quantity=?, category_id=?, subcategory_id=?, cost_initial=?, packaging_type=?, packaging_qty=?, iva_percent=?, margin_percent=?, price_unit=?, rounding_enabled=?, visible_web=?, has_inner_units=?, inner_unit_qty=? WHERE id=?");
            $stmt->execute([$name, $sku, $price_unit, $min_q, $cat_id, $sub_id, $cost_initial, $packaging_type, $packaging_qty, $iva_percent, $margin_percent, $price_unit, $rounding_enabled, $visible_web, $has_inner_units, $inner_unit_qty, $id]);

            if (!empty($_FILES['images']['name'][0])) save_product_images($id, $_FILES['images'], $pdo);

            $branch_minimums_input = $_POST['branch_minimums'] ?? [];
            if (is_array($branch_minimums_input) && count($branch_minimums_input) > 0) {
                $stmtMin = $pdo->prepare("INSERT INTO product_branch_minimums (product_id, branch_id, min_quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE min_quantity = VALUES(min_quantity)");
                foreach ($branch_minimums_input as $branch_id => $min_val) {
                    $b_id = (int)$branch_id;
                    if ($b_id <= 0) continue;
                    $stmtMin->execute([$id, $b_id, max(0, (int)$min_val)]);
                }
            }

            $price_tiers_json = $_POST['price_tiers_json'] ?? '';
            if ($price_tiers_json !== '') {
                $tiersDecoded = json_decode($price_tiers_json, true);
                if (is_array($tiersDecoded)) {
                    $stmtDel = $pdo->prepare("DELETE FROM product_prices WHERE product_id=?");
                    $stmtDel->execute([$id]);
                    $stmtIns = $pdo->prepare("INSERT INTO product_prices (product_id, unit_label, unit_level, quantity, price, margin_percent, category_id, subcategory_id, iva_percent, image_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($tiersDecoded as $t) {
                        $t_label = $t['label'] ?? '';
                        $t_level = $t['level'] ?? 'package';
                        if (!in_array($t_level, ['package', 'inner', 'individual'], true)) $t_level = 'package';
                        $t_qty = max(1, intval($t['quantity'] ?? 1));
                        $t_margin = isset($t['margin']) ? floatval($t['margin']) : 0.0;
                        $t_price = isset($t['price']) ? floatval($t['price']) : 0.0;
                        if ($t_price <= 0) $t_price = calculate_price_from_margin($cost_initial, $packaging_qty, $iva_percent, $t_margin, (bool)$rounding_enabled);
                        $t_category = !empty($t['category_id']) ? intval($t['category_id']) : null;
                        $t_subcategory = !empty($t['subcategory_id']) ? intval($t['subcategory_id']) : null;
                        $t_iva = isset($t['iva_percent']) ? floatval($t['iva_percent']) : 0.0;
                        $t_image_id = !empty($t['image_id']) ? intval($t['image_id']) : null;
                        $stmtIns->execute([$id, $t_label, $t_level, $t_qty, $t_price, $t_margin, $t_category, $t_subcategory, $t_iva, $t_image_id]);
                    }
                }
            }

            $pdo->commit();
            $msg = 'Producto actualizado correctamente.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Error actualizando producto: ' . $e->getMessage());
            $msg = 'Ocurrió un error al actualizar el producto.';
        }
    } else {
        $msg = 'Todos los campos obligatorios deben estar completos.';
    }
}

// ============================================================
// 6. CARGAR DATOS PARA EDICIÓN Y LISTADO
// ============================================================
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($edit_product) {
        $stmtImg = $pdo->prepare("SELECT * FROM product_images WHERE product_id=?");
        $stmtImg->execute([$id]);
        $edit_images = $stmtImg->fetchAll(PDO::FETCH_ASSOC);

        $stmtTiers = $pdo->prepare("SELECT * FROM product_prices WHERE product_id=?");
        $stmtTiers->execute([$id]);
        $edit_price_tiers = $stmtTiers->fetchAll(PDO::FETCH_ASSOC);

        $stmtMin = $pdo->prepare("SELECT branch_id, min_quantity FROM product_branch_minimums WHERE product_id = ?");
        $stmtMin->execute([$id]);
        $branchMinimums = $stmtMin->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!is_array($branchMinimums)) $branchMinimums = [];
    }
}

$products = $pdo->query("SELECT p.*, c.name AS category_name, s.name AS subcategory_name, (SELECT filename FROM product_images WHERE product_id=p.id AND is_main=1 LIMIT 1) AS main_image FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN subcategories s ON p.subcategory_id=s.id ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Admin - Productos</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f7f7f7;color:#222;margin:0;padding:0}header{background:#0b5;padding:18px 24px;color:#fff}header h2{margin:0;font-size:20px}.branch{margin-top:6px;font-size:13px;opacity:.95}.container{max-width:1100px;margin:18px auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.06)}.msg{background:#e6ffed;border:1px solid #b7f0c6;padding:10px;margin-bottom:12px;border-radius:6px;color:#0a6}.btn{display:inline-block;padding:8px 12px;border-radius:6px;border:0;cursor:pointer;background:#eee;color:#222;text-decoration:none}.btn.primary{background:#0078d4;color:#fff}.btn.secondary{background:#6c757d;color:#fff}.btn.warning{background:#f39c12;color:#fff}.btn-create{background:#0b5;color:#fff;padding:10px 14px;border-radius:6px;text-decoration:none}.small{font-size:13px;color:#444}input[type="text"],input[type="number"],select{padding:8px;border:1px solid #ddd;border-radius:6px;margin:6px 0;width:100%;box-sizing:border-box}input[readonly]{background:#f3f3f3}label{display:block;margin-top:8px;font-weight:600;font-size:14px}table{width:100%;border-collapse:collapse;margin-top:12px}table th,table td{padding:8px 10px;border:1px solid #eee;text-align:left;font-size:14px}table thead th{background:#fafafa;font-weight:700}.thumb{width:64px;height:64px;object-fit:cover;border-radius:6px}.gallery{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px}.gallery-item{position:relative}.tag-main{position:absolute;left:6px;top:6px;background:#0078d4;color:#fff;padding:4px 6px;border-radius:4px;font-size:12px}.btn-small-main,.btn-small-del{display:inline-block;margin-top:6px;padding:6px 8px;background:#eee;border-radius:6px;text-decoration:none;color:#222;font-size:13px}#price-tiers-editor{margin-top:8px}#price-tiers td,#price-tiers th{white-space:nowrap}.btn-remove-tier{background:#ff6b6b;color:#fff;border:0;padding:6px 8px;border-radius:6px;cursor:pointer}.btn-edit-tier{background:#f39c12;color:#fff;border:0;padding:6px 8px;border-radius:6px;cursor:pointer}@media(max-width:900px){.container{padding:12px}.thumb{width:48px;height:48px}}
</style>
</head>
<body>
<header>
<h2>Gestión de Productos</h2>
<div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
<p class="small" style="margin-top:12px;">Enlaces rápidos: <a href="/mi_tienda/admin/dashboard.php">Salir al Dashboard</a> | <a href="/mi_tienda/admin/branch_visibility.php">Visibilidad por Sucursal</a></p>
</header>
<div class="container">
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<a href="/mi_tienda/admin/productos.php?action=new" class="btn btn-create">+ Crear Producto</a>

<?php if ($action === 'new'): ?>
<?php
$stmt = $pdo->query("SELECT MAX(sku) AS last_sku FROM products");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$lastSku = $row['last_sku'] ?? 0;
$nextSku = $lastSku > 0 ? $lastSku + 1 : 100;
?>
<h3>Nuevo Producto</h3>
<form method="POST" action="/mi_tienda/admin/productos.php?action=create" enctype="multipart/form-data">
<input type="text" name="name" placeholder="Nombre del producto" required>
<input type="text" name="sku" id="sku" value="<?= $nextSku ?>" readonly required>
<input type="number" step="0.01" name="price" id="price_field" placeholder="Precio" readonly required>

<label>Categoría:</label>
<select name="category_id" id="category_select" required><option value="">Seleccione una categoría</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
<label>Subcategoría:</label>
<select name="subcategory_id" id="subcategory_select" required><option value="">Seleccione una subcategoría</option></select>

<label>Costo inicial</label>
<input type="number" step="0.01" id="cost_initial" name="cost_initial" value="0">
<label>Tipo de Embalaje</label>
<select id="packaging_type" name="packaging_type"><option value="unidad">Unidad</option><option value="docena">Docena</option><option value="caja">Caja</option><option value="paca">Paca</option><option value="kg">Kilogramo</option><option value="millar">Millar</option><option value="otro">Otro</option></select>
<input type="text" id="packaging_type_custom" name="packaging_type_custom" placeholder="Si eliges Otro">
<label>Cantidad de paquetes/contenedores en embalaje</label>
<input type="number" name="packaging_qty" id="packaging_qty" value="1" min="1" required>
<div id="inner_units_wrapper" style="margin-top:10px;display:none;"><label>Unidades por paquete / contenedor</label><input type="number" name="inner_unit_qty" id="inner_unit_qty" min="1" placeholder="Ej: 50"></div>
<div id="conversion_summary" style="margin-top:15px;padding:10px;border:1px solid #bce8f1;background:#d9edf7;border-radius:4px;display:none;"><strong>Resumen de conversión:</strong><ul style="margin-bottom:0;padding-left:20px;"><li>Total unidades por embalaje completo: <span id="summary_total_units">0</span></li><li>Costo por paquete/contenedor: $<span id="summary_cost_package">0</span></li><li id="summary_cost_unit_row">Costo por unidad mínima: $<span id="summary_cost_unit">0</span></li></ul></div>

<label>IVA %</label><input type="number" step="0.01" id="iva_percent" name="iva_percent" value="0">
<label>Porcentaje % (margen)</label><input type="number" step="0.01" id="margin_percent" name="margin_percent" value="0">
<div style="margin:10px 0;padding:8px;border:1px solid #eee;border-radius:6px;"><div>Costo unidad: <strong id="cost_unit">0.00</strong></div><div>IVA por unidad: <strong id="iva_amount">0.00</strong></div><div>Total costo + IVA: <strong id="total_cost_iva">0.00</strong></div><div>Valor %: <strong id="value_percent">0.00</strong></div><div>Ajuste: <strong id="adjustment">0.00</strong></div><div>Precio unidad calculado: <strong id="price_unit_calc">0.00</strong></div></div>
<input type="hidden" id="price_unit_hidden" name="price_unit" value="0"><input type="hidden" id="adjustment_hidden" name="adjustment" value="0">
<div style="margin:10px 0;"><input type="hidden" id="rounding_enabled" name="rounding_enabled" value="1"><button type="button" id="toggleRoundingBtn" class="btn primary">Desactivar ajuste automático</button></div>

<label>Imágenes:</label><input type="file" name="images[]" multiple accept="image/*">
<button class="btn btn-create" style="margin-top:10px;">Guardar</button>
</form>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_product): ?>
<h3>Editar Producto: <?= htmlspecialchars($edit_product['name']) ?></h3>
<form method="POST" action="/mi_tienda/admin/productos.php?action=edit&id=<?= (int)$edit_product['id'] ?>" enctype="multipart/form-data">
<input type="text" name="name" value="<?= htmlspecialchars($edit_product['name']) ?>" required><input type="text" name="sku" value="<?= htmlspecialchars($edit_product['sku']) ?>" required><input type="number" step="0.01" name="price" id="price_field" value="<?= htmlspecialchars($edit_product['price']) ?>" readonly required><input type="number" name="min_quantity" value="<?= htmlspecialchars($edit_product['min_quantity']) ?>">

<h4>Cantidad mínima por sucursal</h4>
<?php if (!empty($branches)): ?><div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;"><?php foreach ($branches as $b): $bId=(int)$b['id']; $val=isset($branchMinimums[$bId])?(int)$branchMinimums[$bId]:(int)($edit_product['min_quantity']??0); ?><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><label style="min-width:180px;margin:0;font-weight:600;"><?= htmlspecialchars($b['name']) ?></label><input type="number" name="branch_minimums[<?= $bId ?>]" value="<?= htmlspecialchars($val) ?>" min="0" style="width:140px;"><span class="small">(0 usa el mínimo global)</span></div><?php endforeach; ?></div><?php else: ?><p class="small">No se encontraron sucursales.</p><?php endif; ?>

<label>Visibilidad en el sitio web:</label><select name="visible_web" id="visible_web"><option value="1" <?= (int)($edit_product['visible_web'] ?? 1)===1?'selected':'' ?>>Visible (Mostrar en tienda)</option><option value="0" <?= (int)($edit_product['visible_web'] ?? 1)===0?'selected':'' ?>>Oculto (No mostrar en tienda)</option></select>
<label>Categoría:</label><select name="category_id" id="category_select_edit" required><option value="">Seleccione una categoría</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $edit_product['category_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
<label>Subcategoría:</label><select name="subcategory_id" id="subcategory_select_edit" required><option value="">Cargando subcategorías...</option></select>
<label>Costo inicial</label><input type="number" step="0.01" id="cost_initial" name="cost_initial" value="<?= htmlspecialchars($edit_product['cost_initial'] ?? 0) ?>">
<label>Embalaje</label><select id="packaging_type" name="packaging_type"><?php foreach (['unidad'=>'Unidad','docena'=>'Docena','caja'=>'Caja','paca'=>'Paca','kg'=>'Kilogramo','millar'=>'Millar','otro'=>'Otro'] as $v=>$txt): ?><option value="<?= $v ?>" <?= ($edit_product['packaging_type']??'unidad')===$v?'selected':'' ?>><?= $txt ?></option><?php endforeach; ?></select><input type="text" id="packaging_type_custom" name="packaging_type_custom" placeholder="Si eliges Otro" value="<?= htmlspecialchars($edit_product['packaging_type'] ?? '') ?>">
<label>Cantidad embalaje</label><input type="number" id="packaging_qty" name="packaging_qty" min="1" value="<?= htmlspecialchars($edit_product['packaging_qty'] ?? 1) ?>">
<label>¿Cada paquete/contenedor contiene unidades internas?</label><select name="has_inner_units" id="has_inner_units" onchange="toggleInnerUnitsEdit()"><option value="0" <?= empty($edit_product['has_inner_units'])?'selected':'' ?>>No</option><option value="1" <?= !empty($edit_product['has_inner_units'])?'selected':'' ?>>Sí</option></select>
<div id="inner_units_wrapper_edit" style="margin-top:10px;display:<?= !empty($edit_product['has_inner_units'])?'block':'none' ?>;"><label>Unidades por paquete / contenedor</label><input type="number" name="inner_unit_qty" id="inner_unit_qty" min="1" value="<?= htmlspecialchars($edit_product['inner_unit_qty'] ?? '') ?>" placeholder="Ej: 50"><div class="small">Ejemplo: si cada paquete contiene 50 vasos, escribe 50.</div></div>
<div id="conversion_summary_edit" style="margin-top:15px;padding:10px;border:1px solid #bce8f1;background:#d9edf7;border-radius:4px;display:none;"><strong>Resumen de conversión:</strong><ul style="margin-bottom:0;padding-left:20px;"><li>Total unidades por embalaje completo: <span id="summary_total_units_edit">0</span></li><li>Costo por paquete/contenedor: $<span id="summary_cost_package_edit">0.00</span></li><li id="summary_cost_unit_row_edit">Costo por unidad mínima: $<span id="summary_cost_unit_edit">0.00</span></li></ul></div>
<label>IVA %</label><input type="number" step="0.01" id="iva_percent" name="iva_percent" value="<?= htmlspecialchars($edit_product['iva_percent'] ?? 0) ?>"><label>Porcentaje % (margen)</label><input type="number" step="0.01" id="margin_percent" name="margin_percent" value="<?= htmlspecialchars($edit_product['margin_percent'] ?? 0) ?>">
<div style="margin:10px 0;padding:8px;border:1px solid #eee;border-radius:6px;"><div>Costo unidad: <strong id="cost_unit">0.00</strong></div><div>IVA por unidad: <strong id="iva_amount">0.00</strong></div><div>Total costo + IVA: <strong id="total_cost_iva">0.00</strong></div><div>Valor %: <strong id="value_percent">0.00</strong></div><div>Ajuste: <strong id="adjustment">0.00</strong></div><div>Precio unidad calculated: <strong id="price_unit_calc">0.00</strong></div></div>
<input type="hidden" id="price_unit_hidden" name="price_unit" value="<?= htmlspecialchars($edit_product['price_unit'] ?? $edit_product['price'] ?? 0) ?>"><input type="hidden" id="adjustment_hidden" name="adjustment" value="0">
<label>Agregar más imágenes (puedes seleccionar varias):</label><input type="file" name="images[]" multiple accept="image/*">
<div style="margin:10px 0;"><input type="hidden" id="rounding_enabled" name="rounding_enabled" value="<?= isset($edit_product['rounding_enabled'])?(int)$edit_product['rounding_enabled']:1 ?>"><button type="button" id="toggleRoundingBtn" class="btn <?= !empty($edit_product['rounding_enabled'])?'primary':'secondary' ?>"><?= !empty($edit_product['rounding_enabled'])?'Desactivar ajuste automático':'Activar ajuste automático' ?></button></div>

<h4>Precios por presentación</h4>
<div id="price-tiers-editor">
    <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
        <select id="tier_label"><option value="unidad">Unidad</option><option value="docena">Docena</option><option value="caja">Caja</option><option value="paca">Paca</option><option value="otro">Otro</option></select>
        <input type="text" id="tier_label_custom" placeholder="Etiqueta personalizada (opcional)" style="min-width:160px;">
        
        <!-- SELECTOR DE IMAGEN CON MINIATURA DE VISTA PREVIA -->
        <div style="display:flex;align-items:center;gap:6px;">
            <select id="tier_image" style="min-width:180px;" onchange="updateTierImagePreview()">
                <option value="">Imagen (Por defecto)</option>
                <?php foreach (($edit_images ?? []) as $img): ?>
                    <option value="<?= (int)$img['id'] ?>" data-filename="<?= htmlspecialchars($img['filename']) ?>">
                        #<?= (int)$img['id'] ?> - <?= htmlspecialchars($img['filename']) ?> <?= !empty($img['is_main']) ? '(Principal)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="tier_image_preview_box" style="display:none;align-items:center;">
                <img id="tier_image_preview" src="" alt="Vista previa" style="width:38px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #0078d4;box-shadow:0 2px 4px rgba(0,0,0,0.15);">
            </div>
        </div>

        <select id="tier_level" style="min-width:220px;"><option value="inner">Costo por paquete/contenedor</option><option value="individual" class="tier-individual-option" style="display:none;" disabled>Costo por unidad mínima</option></select>
        <input id="tier_qty" type="number" min="1" value="1" style="width:100px;" placeholder="Cantidad">
        <input id="tier_price" type="number" step="0.01" placeholder="Precio (opcional)" style="width:120px;">
        <input id="tier_margin" type="number" step="0.01" placeholder="Margen % (opcional)" style="width:100px;">
        <select id="tier_category" style="min-width:140px;"><option value="">Seleccione categoría</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
        <select id="tier_subcategory" style="min-width:140px;"><option value="">Seleccione subcategoría</option></select>
        <input id="tier_iva" type="number" step="0.01" placeholder="IVA %" style="width:80px;">
        
        <button type="button" class="btn primary" id="add_tier_btn" onclick="addPriceTier()">Agregar</button>
        <button type="button" class="btn secondary" id="cancel_tier_btn" style="display:none;" onclick="cancelEditTier()">Cancelar</button>
    </div>
    <table id="price-tiers">
        <thead>
            <tr>
                <th>Pertenece a:</th>
                <th>Etiqueta</th>
                <th>Imagen</th>
                <th>Costo base</th>
                <th>Cantidad</th>
                <th>Precio</th>
                <th>Margen %</th>
                <th>Categoría</th>
                <th>Subcategoría</th>
                <th>IVA %</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <input type="hidden" id="price_tiers_json" name="price_tiers_json" value="">
</div>
<button type="submit" class="btn btn-create" style="margin-top:14px;">Actualizar Producto</button>
</form>

<h3>Galería de Imágenes</h3>
<?php if ($edit_images): ?><div class="gallery"><?php foreach ($edit_images as $img): ?><div class="gallery-item"><img class="thumb" src="/mi_tienda/uploads/products/<?= htmlspecialchars($img['filename']) ?>" alt=""><?php if ($img['is_main']): ?><div class="tag-main">Principal</div><?php endif; ?><a class="btn-small-main" href="/mi_tienda/admin/productos.php?action=edit&id=<?= (int)$edit_product['id'] ?>&image_action=set_main&image_id=<?= (int)$img['id'] ?>">Marcar principal</a> <a class="btn-small-del" href="/mi_tienda/admin/productos.php?action=edit&id=<?= (int)$edit_product['id'] ?>&image_action=delete&image_id=<?= (int)$img['id'] ?>" onclick="return confirm('¿Eliminar esta imagen?')">Eliminar</a></div><?php endforeach; ?></div><?php else: ?><p>Este producto aún no tiene imágenes.</p><?php endif; ?>
<?php endif; ?>

<script>
let categoriesMap = <?= $categoriesMapJs ?: '{}' ?>;
let subcategoriesMap = <?= $subcategoriesMapJs ?: '{}' ?>;
let editImagesMap = <?= json_encode(array_column($edit_images ?? [], 'filename', 'id'), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

function updateTierImagePreview() {
    const select = document.getElementById('tier_image');
    const previewBox = document.getElementById('tier_image_preview_box');
    const previewImg = document.getElementById('tier_image_preview');
    if (!select || !previewImg || !previewBox) return;

    const selectedOpt = select.options[select.selectedIndex];
    const filename = selectedOpt ? selectedOpt.getAttribute('data-filename') : null;

    if (select.value && filename) {
        previewImg.src = '/mi_tienda/uploads/products/' + filename;
        previewBox.style.display = 'inline-flex';
    } else {
        previewBox.style.display = 'none';
        previewImg.src = '';
    }
}
</script>
<?php
if (!empty($edit_price_tiers) && is_array($edit_price_tiers) && isset($edit_product)) {
    $ctx_cost = floatval($edit_product['cost_initial'] ?? 0);
    $ctx_pack_qty = max(1, intval($edit_product['packaging_qty'] ?? 1));
    $ctx_rounding = !empty($edit_product['rounding_enabled']);
    $jsTiers = array_map(function($r) use ($ctx_cost, $ctx_pack_qty, $ctx_rounding) {
        $price = number_format((float)$r['price'], 2, '.', '');
        $margin = number_format((float)($r['margin_percent'] ?? 0), 2, '.', '');
        $t_iva = number_format((float)($r['iva_percent'] ?? 0), 2, '.', '');
        $expected = calculate_price_from_margin($ctx_cost, $ctx_pack_qty, floatval($t_iva), floatval($margin), $ctx_rounding);
        $autoFlag = abs(floatval($price) - floatval($expected)) < 0.01 && floatval($margin) > 0;
        return [
            'label'          => $r['unit_label'],
            'level'          => $r['unit_level'] ?? 'package',
            'quantity'       => intval($r['quantity']),
            'price'          => $price,
            'margin'         => $margin,
            'category_id'    => $r['category_id'] ?? null,
            'subcategory_id' => $r['subcategory_id'] ?? null,
            'iva_percent'    => $t_iva,
            'image_id'       => !empty($r['image_id']) ? intval($r['image_id']) : null,
            'auto'           => $autoFlag
        ];
    }, $edit_price_tiers);
} else $jsTiers = [];
?>
<script>
let tiers = <?= json_encode($jsTiers, JSON_UNESCAPED_UNICODE) ?>;

function loadSubcategories(categoryId,targetSelect,selectedId=null){
    if(!targetSelect)return;
    if(!categoryId){targetSelect.innerHTML='<option value="">Seleccione una subcategoría</option>';return;}
    fetch('/mi_tienda/admin/ajax/get_subcategories.php?category_id='+encodeURIComponent(categoryId)).then(r=>r.json()).then(data=>{targetSelect.innerHTML='<option value="">Seleccione una subcategoría</option>';data.forEach(sub=>{const opt=document.createElement('option');opt.value=sub.id;opt.textContent=sub.name;if(selectedId&&String(selectedId)===String(sub.id))opt.selected=true;targetSelect.appendChild(opt);});}).catch(err=>{console.error('Error cargando subcategorías:',err);targetSelect.innerHTML='<option value="">Error cargando subcategorías</option>';});
}

(function(){const cat=document.getElementById('category_select'),sub=document.getElementById('subcategory_select');if(cat&&sub)cat.addEventListener('change',()=>loadSubcategories(cat.value,sub));})();
(function(){const cat=document.getElementById('category_select_edit'),sub=document.getElementById('subcategory_select_edit');if(cat&&sub){loadSubcategories(cat.value,sub,<?= isset($edit_product['subcategory_id']) ? (int)$edit_product['subcategory_id'] : 'null' ?>);cat.addEventListener('change',()=>loadSubcategories(cat.value,sub));}})();

function updateTierCostOptions(){
    const hasInner=document.getElementById('has_inner_units');
    const level=document.getElementById('tier_level');
    if(!hasInner||!level)return;

    const individual=level.querySelector('option[value="individual"]');
    if(!individual)return;

    const enabled=hasInner.value==='1';
    individual.style.display=enabled?'block':'none';
    individual.disabled=!enabled;

    if(!enabled&&level.value==='individual')level.value='inner';
}

function toggleInnerUnits(){const has=document.getElementById('has_inner_units'),wrap=document.getElementById('inner_units_wrapper'),inner=document.getElementById('inner_unit_qty');if(!has||!wrap)return;wrap.style.display=has.value==='1'?'block':'none';if(has.value!=='1'&&inner)inner.value='';calculateConversionSummary();updateTierCostOptions();}
function toggleInnerUnitsEdit(){const has=document.getElementById('has_inner_units'),wrap=document.getElementById('inner_units_wrapper_edit'),inner=document.getElementById('inner_unit_qty');if(!has||!wrap)return;wrap.style.display=has.value==='1'?'block':'none';if(has.value!=='1'&&inner)inner.value='';calculateConversionSummary();updateTierCostOptions();}
function calculateConversionSummary(){
    const cost=parseFloat(document.getElementById('cost_initial')?.value||0),pack=parseInt(document.getElementById('packaging_qty')?.value||1,10),has=document.getElementById('has_inner_units')?.value,inner=parseInt(document.getElementById('inner_unit_qty')?.value||0,10);
    const box=document.getElementById('conversion_summary')||document.getElementById('conversion_summary_edit');if(!box)return;if(cost<=0||pack<=0){box.style.display='none';return;}box.style.display='block';const suffix=box.id.endsWith('_edit')?'_edit':'';const costPackage=cost/pack;document.getElementById('summary_cost_package'+suffix).textContent=costPackage.toFixed(2);document.getElementById('summary_total_units'+suffix).textContent=(has==='1'&&inner>0?pack*inner:pack).toLocaleString();const row=document.getElementById('summary_cost_unit_row'+suffix),unit=document.getElementById('summary_cost_unit'+suffix);if(has==='1'&&inner>0){unit.textContent=(costPackage/inner).toFixed(2);row.style.display='list-item';}else row.style.display='none';
}

function updatePriceCalc(){
    const costEl=document.getElementById('cost_initial'),packEl=document.getElementById('packaging_qty'),ivaEl=document.getElementById('iva_percent'),marginEl=document.getElementById('margin_percent'),priceField=document.getElementById('price_field'),priceHidden=document.getElementById('price_unit_hidden');if(!costEl||!packEl||!ivaEl||!marginEl||!priceHidden)return;
    const cost=parseFloat(costEl.value||0);let pack=parseInt(packEl.value||1,10);if(!pack||pack<=0)pack=1;const iva=parseFloat(ivaEl.value||0),margin=parseFloat(marginEl.value||0),costUnit=cost/pack,ivaAmount=costUnit*(iva/100),total=costUnit+ivaAmount,valuePercent=total*(margin/100);let price=total+valuePercent,adjustment=0;const rounding=document.getElementById('rounding_enabled')?.value!=='0';if(rounding){const next=Math.ceil(price/100)*100;adjustment=next-price;price=next;}const fmt=v=>Number(v||0).toFixed(2);if(document.getElementById('cost_unit'))document.getElementById('cost_unit').textContent=fmt(costUnit);if(document.getElementById('iva_amount'))document.getElementById('iva_amount').textContent=fmt(ivaAmount);if(document.getElementById('total_cost_iva'))document.getElementById('total_cost_iva').textContent=fmt(total);if(document.getElementById('value_percent'))document.getElementById('value_percent').textContent=fmt(valuePercent);if(document.getElementById('adjustment'))document.getElementById('adjustment').textContent=fmt(adjustment);if(document.getElementById('adjustment_hidden'))document.getElementById('adjustment_hidden').value=fmt(adjustment);if(document.getElementById('price_unit_calc'))document.getElementById('price_unit_calc').textContent=fmt(price);priceHidden.value=fmt(price);if(priceField)priceField.value=fmt(price);if(typeof window.updateTiersPrices==='function')window.updateTiersPrices();
}

(function(){
    const btn=document.getElementById('toggleRoundingBtn'),hidden=document.getElementById('rounding_enabled');if(btn&&hidden)btn.addEventListener('click',function(){hidden.value=hidden.value==='1'?'0':'1';this.textContent=hidden.value==='1'?'Desactivar ajuste automático':'Activar ajuste automático';this.classList.toggle('primary',hidden.value==='1');this.classList.toggle('secondary',hidden.value!=='1');updatePriceCalc();});
})();

(function(){
    const body = document.querySelector('#price-tiers tbody'),
          labelEl = document.getElementById('tier_label'),
          customEl = document.getElementById('tier_label_custom'),
          imgEl = document.getElementById('tier_image'),
          levelEl = document.getElementById('tier_level'),
          qtyEl = document.getElementById('tier_qty'),
          priceEl = document.getElementById('tier_price'),
          marginEl = document.getElementById('tier_margin'),
          hidden = document.getElementById('price_tiers_json'),
          catEl = document.getElementById('tier_category'),
          subEl = document.getElementById('tier_subcategory'),
          ivaEl = document.getElementById('tier_iva');

    let editingTierIndex = null;

    if (!body || !hidden) return;

    if (catEl && subEl) {
        catEl.addEventListener('change', () => {
            loadSubcategories(catEl.value, subEl);
        });
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function ctx() {
        return {
            cost: parseFloat(document.getElementById('cost_initial')?.value || 0),
            pack: parseInt(document.getElementById('packaging_qty')?.value || 1, 10) || 1,
            iva: parseFloat(document.getElementById('iva_percent')?.value || 0)
        };
    }

    function calc(cost, pack, iva, margin, level = 'inner', qty = 1) {
        pack = Math.max(1, parseInt(pack || 1, 10));
        qty = Math.max(1, parseInt(qty || 1, 10));

        const hasInner = document.getElementById('has_inner_units')?.value === '1';
        const inner = Math.max(1, parseInt(document.getElementById('inner_unit_qty')?.value || 1, 10));

        const costPackage = cost / pack;
        const costIndividual = hasInner ? costPackage / inner : costPackage;
        const baseCost = level === 'individual' ? costIndividual : costPackage;

        let p = (baseCost * qty) * (1 + iva / 100) * (1 + margin / 100);

        if (document.getElementById('rounding_enabled')?.value !== '0') {
            p = Math.ceil(p / 100) * 100;
        }

        return Number(p.toFixed(2));
    }

    function render() {
        body.innerHTML = '';

        tiers.forEach((t, i) => {
            const level = t.level || 'inner';
            const levelName = level === 'individual' ? 'Costo por unidad mínima' : 'Costo por paquete/contenedor';
            const catName = t.category_id && categoriesMap[t.category_id] ? categoriesMap[t.category_id] : '-';
            const subName = t.subcategory_id && subcategoriesMap[t.subcategory_id] ? subcategoriesMap[t.subcategory_id] : (t.subcategory_id || '-');
            const auto = t.auto ? ' <small style="color:#0078d4;font-weight:700;">(Auto)</small>' : '';

            const imgThumb = t.image_id && editImagesMap[t.image_id]
                ? '<img src="/mi_tienda/uploads/products/' + escapeHtml(editImagesMap[t.image_id]) + '" style="width:36px;height:36px;object-fit:cover;border-radius:4px;">'
                : '<span style="color:#888;">Por defecto</span>';

            const tr = document.createElement('tr');

            tr.innerHTML =
                '<td>' + escapeHtml(catName) + '</td>' +
                '<td>' + escapeHtml(t.label) + auto + '</td>' +
                '<td>' + imgThumb + '</td>' +
                '<td>' + escapeHtml(levelName) + '</td>' +
                '<td>' + escapeHtml(t.quantity) + '</td>' +
                '<td>$' + escapeHtml(Number(t.price || 0).toFixed(2)) + '</td>' +
                '<td>' + escapeHtml(Number(t.margin || 0).toFixed(2)) + '%</td>' +
                '<td>' + escapeHtml(catName) + '</td>' +
                '<td>' + escapeHtml(subName) + '</td>' +
                '<td>' + escapeHtml(Number(t.iva_percent || 0).toFixed(2)) + '%</td>' +
                '<td>' +
                    '<button type="button" class="btn-edit-tier" data-i="' + i + '" style="margin-right:4px;">Editar</button>' +
                    '<button type="button" class="btn-remove-tier" data-i="' + i + '">Eliminar</button>' +
                '</td>';

            body.appendChild(tr);
        });

        // BOTONES EDITAR PRESENTACIÓN
        body.querySelectorAll('.btn-edit-tier').forEach(button => {
            button.onclick = () => {
                const index = parseInt(button.dataset.i, 10);
                const t = tiers[index];
                if (!t) return;

                editingTierIndex = index;

                const stdLabels = ['unidad', 'docena', 'caja', 'paca', 'otro'];
                if (labelEl) {
                    if (stdLabels.includes(t.label)) {
                        labelEl.value = t.label;
                        if (customEl) customEl.value = '';
                    } else {
                        labelEl.value = 'otro';
                        if (customEl) customEl.value = t.label;
                    }
                }

                if (imgEl) {
                    imgEl.value = t.image_id || '';
                    updateTierImagePreview();
                }
                if (levelEl) levelEl.value = t.level || 'inner';
                if (qtyEl) qtyEl.value = t.quantity || 1;
                if (priceEl) priceEl.value = t.price || '';
                if (marginEl) marginEl.value = t.margin || '';
                if (ivaEl) ivaEl.value = t.iva_percent || '';
                if (catEl) {
                    catEl.value = t.category_id || '';
                    if (subEl) {
                        loadSubcategories(catEl.value, subEl, t.subcategory_id);
                    }
                }

                const addBtn = document.getElementById('add_tier_btn');
                const cancelBtn = document.getElementById('cancel_tier_btn');
                if (addBtn) {
                    addBtn.textContent = 'Guardar Cambios';
                    addBtn.className = 'btn warning';
                }
                if (cancelBtn) cancelBtn.style.display = 'inline-block';
            };
        });

        // BOTONES ELIMINAR PRESENTACIÓN
        body.querySelectorAll('.btn-remove-tier').forEach(button => {
            button.onclick = () => {
                const index = parseInt(button.dataset.i, 10);
                if (editingTierIndex === index) {
                    cancelEditTier();
                }
                tiers.splice(index, 1);
                render();
            };
        });

        hidden.value = JSON.stringify(tiers);
    }

    window.cancelEditTier = function() {
        editingTierIndex = null;
        const addBtn = document.getElementById('add_tier_btn');
        const cancelBtn = document.getElementById('cancel_tier_btn');
        if (addBtn) {
            addBtn.textContent = 'Agregar';
            addBtn.className = 'btn primary';
        }
        if (cancelBtn) cancelBtn.style.display = 'none';

        if (qtyEl) qtyEl.value = 1;
        if (priceEl) priceEl.value = '';
        if (labelEl) labelEl.value = 'unidad';
        if (customEl) customEl.value = '';
        if (imgEl) {
            imgEl.value = '';
            updateTierImagePreview();
        }
        if (levelEl) levelEl.value = 'inner';
        if (marginEl) marginEl.value = '';
        if (ivaEl) ivaEl.value = '';
        if (catEl) catEl.value = '';
        if (subEl) subEl.innerHTML = '<option value="">Seleccione subcategoría</option>';
    };

    window.addPriceTier = function() {
        const label = (customEl?.value || '').trim() || (labelEl?.value || '').trim();
        const level = levelEl?.value || 'inner';
        const qty = parseInt(qtyEl?.value || 1, 10) || 1;
        const margin = parseFloat(marginEl?.value || 0) || 0;
        let price = parseFloat(priceEl?.value || 0) || 0;
        const imageId = imgEl?.value ? parseInt(imgEl.value, 10) : null;
        let auto = false;

        if (!label || qty <= 0) {
            return alert('Ingrese etiqueta y cantidad válidas.');
        }

        if (price <= 0 && margin > 0) {
            const c = ctx();
            const tierIva = parseFloat(ivaEl?.value || c.iva);
            price = calc(c.cost, c.pack, tierIva, margin, level, qty);
            auto = true;
        }

        if (price <= 0) {
            return alert('Ingrese un precio válido o un margen para calcularlo.');
        }

        const item = {
            label: label,
            level: level,
            quantity: qty,
            price: Number(price).toFixed(2),
            margin: Number(margin).toFixed(2),
            category_id: catEl?.value || null,
            subcategory_id: subEl?.value || null,
            iva_percent: Number(parseFloat(ivaEl?.value || 0) || 0).toFixed(2),
            image_id: imageId,
            auto: auto
        };

        const duplicate = tiers.some((t, idx) => {
            if (editingTierIndex !== null && idx === editingTierIndex) return false;
            return JSON.stringify({ ...t, auto: false }) === JSON.stringify({ ...item, auto: false });
        });

        if (duplicate) {
            return alert('Esa presentación ya fue agregada.');
        }

        if (editingTierIndex !== null) {
            tiers[editingTierIndex] = item;
        } else {
            tiers.push(item);
        }

        render();
        cancelEditTier();
    };

    window.updateTiersPrices = function() {
        const c = ctx();

        tiers = tiers.map(t => {
            const margin = parseFloat(t.margin || 0);

            if (t.auto && margin > 0) {
                const tierIva = parseFloat(t.iva_percent || c.iva) || 0;
                t.price = calc(
                    c.cost,
                    c.pack,
                    tierIva,
                    margin,
                    t.level || 'inner',
                    parseInt(t.quantity || 1, 10)
                ).toFixed(2);
            }

            return t;
        });

        render();
    };

    render();
})();

document.addEventListener('DOMContentLoaded',function(){
    ['cost_initial','packaging_qty','iva_percent','margin_percent','inner_unit_qty'].forEach(id=>{const el=document.getElementById(id);if(el){el.addEventListener('input',()=>{calculateConversionSummary();updatePriceCalc();});el.addEventListener('change',()=>{calculateConversionSummary();updatePriceCalc();});}});
    const hasInner=document.getElementById('has_inner_units');if(hasInner)hasInner.addEventListener('change',()=>{updateTierCostOptions();calculateConversionSummary();updatePriceCalc();});
    updateTierCostOptions();calculateConversionSummary();updatePriceCalc();
});
</script>

<div style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><input id="product_search" type="search" placeholder="Buscar por nombre, SKU, categoría o subcategoría" style="flex:1;min-width:220px;padding:8px;border-radius:6px;border:1px solid #ddd;"><button id="product_search_btn" class="btn">Buscar</button><button id="product_clear_btn" class="btn">Limpiar</button><div id="product_search_info" class="small" style="margin-left:auto;opacity:.85;"></div></div>
<h3>Lista de Productos</h3>
<table id="products_table"><thead><tr><th>ID</th><th>Imagen</th><th>Nombre</th><th>SKU</th><th>Costo inicial</th><th>Precio</th><th>Mínimo</th><th>Categoría</th><th>Subcategoría</th><th>Activo</th><th>Acciones</th></tr></thead><tbody><?php foreach ($products as $p): ?><tr><td><?= htmlspecialchars($p['id']) ?></td><td><?php if (!empty($p['main_image'])): ?><img class="thumb" src="/mi_tienda/uploads/products/<?= htmlspecialchars($p['main_image']) ?>" alt=""><?php else: ?>-<?php endif; ?></td><td><?= htmlspecialchars($p['name']) ?></td><td><?= htmlspecialchars($p['sku']) ?></td><td>$<?= number_format((float)($p['cost_initial']??0),2) ?></td><td>$<?= number_format((float)($p['price']??0),2) ?></td><td><?= (int)($p['min_quantity']??0) ?></td><td><?= htmlspecialchars($p['category_name']??'-') ?></td><td><?= htmlspecialchars($p['subcategory_name']??'-') ?></td><td><?= !empty($p['active'])?'✅':'❌' ?></td><td><a class="btn btn-edit" href="/mi_tienda/admin/productos.php?action=edit&id=<?= (int)$p['id'] ?>">Editar</a> <a class="btn btn-toggle" href="/mi_tienda/admin/productos.php?action=toggle&id=<?= (int)$p['id'] ?>">Activar/Desactivar</a> <a class="btn btn-delete" href="/mi_tienda/admin/productos.php?action=delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('¿Eliminar producto e imágenes?')">Eliminar</a></td></tr><?php endforeach; ?></tbody></table>
<script>
(function(){
 const input=document.getElementById('product_search'),btnSearch=document.getElementById('product_search_btn'),btnClear=document.getElementById('product_clear_btn'),info=document.getElementById('product_search_info'),tbody=document.querySelector('#products_table tbody');if(!input||!tbody)return;
 function escapeHtml(value){const div=document.createElement('div');div.textContent=String(value??'');return div.innerHTML;}
 let timer=null;const wait=300;function setInfo(t){if(info)info.textContent=t;}function renderRows(products){tbody.innerHTML='';if(!Array.isArray(products)||!products.length){tbody.innerHTML='<tr><td colspan="11" style="text-align:center;opacity:.8;">No se encontraron productos</td></tr>';return;}const frag=document.createDocumentFragment();products.forEach(p=>{const tr=document.createElement('tr'),id=Number(p.id)||0,img=p.main_image?'<img class="thumb" src="/mi_tienda/uploads/products/'+escapeHtml(p.main_image)+'" alt="">':'-',actions='<a class="btn btn-edit" href="/mi_tienda/admin/productos.php?action=edit&id='+id+'">Editar</a> <a class="btn btn-toggle" href="/mi_tienda/admin/productos.php?action=toggle&id='+id+'">Activar/Desactivar</a> <a class="btn btn-delete" href="/mi_tienda/admin/productos.php?action=delete&id='+id+'" onclick="return confirm(\'¿Eliminar producto e imágenes?\')">Eliminar</a>';tr.innerHTML='<td>'+id+'</td><td>'+img+'</td><td>'+escapeHtml(p.name)+'</td><td>'+escapeHtml(p.sku)+'</td><td>$'+Number(p.cost_initial||0).toFixed(2)+'</td><td>$'+Number(p.price||0).toFixed(2)+'</td><td>'+Number(p.min_quantity||0)+'</td><td>'+escapeHtml(p.category_name||'-')+'</td><td>'+escapeHtml(p.subcategory_name||'-')+'</td><td>'+(Number(p.active)?'✅':'❌')+'</td><td>'+actions+'</td>';frag.appendChild(tr);});tbody.appendChild(frag);}
 function fetchProducts(q){setInfo('Buscando...');fetch('/mi_tienda/admin/ajax/search_products.php?q='+encodeURIComponent(q)).then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(data=>{renderRows(Array.isArray(data)?data:[]);setInfo(Array.isArray(data)?data.length+' resultado(s)':'Sin resultados');}).catch(e=>{console.error('search error',e);setInfo('Error al buscar');});}
 input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{const q=input.value.trim();if(!q)return setInfo('Escribe para buscar');fetchProducts(q);},wait);});if(btnSearch)btnSearch.addEventListener('click',()=>{clearTimeout(timer);const q=input.value.trim();if(!q)return setInfo('Ingrese término de búsqueda');fetchProducts(q);});if(btnClear)btnClear.addEventListener('click',()=>{input.value='';window.location.href=window.location.pathname;});
})();
</script>
</div>
</body>
</html>
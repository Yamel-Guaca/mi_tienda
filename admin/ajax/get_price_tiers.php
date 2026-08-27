<?php
// admin/ajax/get_price_tiers.php
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/db.php';
require_role([1,2]); // ajustar según permisos
$pdo = DB::getConnection();

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
header('Content-Type: application/json');

if (!$product_id) {
    echo json_encode(['product'=>null,'variants'=>[]]);
    exit;
}

// Datos base del producto (para cálculo fallback)
$stmtP = $pdo->prepare("SELECT id, cost_initial, packaging_qty, iva_percent FROM products WHERE id = ?");
$stmtP->execute([$product_id]);
$product = $stmtP->fetch(PDO::FETCH_ASSOC);

// Variantes
$stmt = $pdo->prepare("SELECT id, unit_label AS label, quantity, price, margin_percent FROM product_prices WHERE product_id = ? ORDER BY id ASC");
$stmt->execute([$product_id]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si alguna variante tiene price = 0, calcular precio en servidor como fallback
function calc_price(float $cost_initial, int $pack_qty, float $iva, float $margin): float {
    if ($pack_qty <= 0) $pack_qty = 1;
    $cost_unit = $cost_initial / $pack_qty;
    $iva_amount = $cost_unit * ($iva / 100.0);
    $total_cost_iva = $cost_unit + $iva_amount;
    $value_percent = $total_cost_iva * ($margin / 100.0);
    $price_unit = $total_cost_iva + $value_percent;
    $nextHundred = ceil($price_unit / 100.0) * 100.0;
    $adjustment = $nextHundred - $price_unit;
    return round($price_unit + $adjustment, 2);
}

if ($product) {
    foreach ($variants as &$v) {
        if (floatval($v['price']) <= 0) {
            $v['price'] = calc_price(floatval($product['cost_initial'] ?? 0), intval($product['packaging_qty'] ?? 1), floatval($product['iva_percent'] ?? 0), floatval($v['margin_percent'] ?? 0));
        } else {
            $v['price'] = round(floatval($v['price']), 2);
        }
        $v['margin_percent'] = round(floatval($v['margin_percent'] ?? 0), 2);
        $v['quantity'] = intval($v['quantity']);
    }
}

echo json_encode(['product'=>$product, 'variants'=>$variants]);

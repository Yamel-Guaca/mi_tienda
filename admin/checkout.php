<?php
// admin/checkout.php
// Endpoint para procesar ventas vía AJAX (JSON) o formulario tradicional.
// Devuelve JSON con success, order_id y receipt_url.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// Asegurar sesión / usuario
if (empty($_SESSION['user']['id'])) {
    $acceptsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    $isJsonBody  = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

    if ($acceptsJson || $isJsonBody) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No autenticado']);
        exit;
    } else {
        http_response_code(401);
        echo "No autenticado";
        exit;
    }
}

// Obtener branch desde sesión
$branch_id = $_SESSION['branch_id'] ?? null;
if (!$branch_id) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Sucursal no definida']);
    exit;
}

// Leer entrada: soporta JSON (AJAX) o form-data (POST tradicional)
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

// Normalizar items y payments
$items    = $data['items'] ?? [];
$payments = $data['payments'] ?? [];

if (is_string($items)) {
    $tmp = json_decode($items, true);
    if (is_array($tmp)) $items = $tmp;
}
if (is_string($payments)) {
    $tmp = json_decode($payments, true);
    if (is_array($tmp)) $payments = $tmp;
}

// Validaciones básicas
if (empty($items) || !is_array($items)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No hay items en la venta']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Determinar método principal
    $payment_method = 'efectivo';
    if (is_array($payments) && count($payments) === 1) {
        $payment_method = $payments[0]['type'] ?? 'efectivo';
    } else {
        $payment_method = 'mixto';
    }

    // Tomar primera referencia no-efectivo si existe
    $transaction_ref = null;
    foreach ($payments as $p) {
        if (($p['type'] ?? '') !== 'efectivo' && !empty($p['ref'])) {
            $transaction_ref = $p['ref'];
            break;
        }
    }

    // Crear orden
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, branch_id, total, status, payment_method, transaction_ref, created_at) VALUES (?, ?, 0, 'completado', ?, ?, NOW())");
    $stmt->execute([$_SESSION['user']['id'], $branch_id, $payment_method, $transaction_ref]);
    $order_id = $pdo->lastInsertId();

    $total = 0;
    foreach ($items as $item) {
        $pid        = intval($item['product_id'] ?? 0);
        $qty        = intval($item['quantity'] ?? 0);
        $price      = isset($item['price']) ? floatval($item['price']) : null;
        $unit_qty   = intval($item['unit_qty'] ?? 1);
        $unit_label = $item['unit_label'] ?? 'unidad';
        $tierId     = isset($item['tier_id']) ? intval($item['tier_id']) : null;
        $prodName   = $item['name'] ?? null;

        if ($pid <= 0 || $qty <= 0) continue;

        if ($price === null) {
            $stmt = $pdo->prepare("SELECT price_unit FROM products WHERE id = ?");
            $stmt->execute([$pid]);
            $price = floatval($stmt->fetchColumn());
        }
        if (!$price) $price = 0;

        $subtotal = $price * $qty;
        $total += $subtotal;

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, tier_id, unit_label, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $pid, $prodName, $tierId, $unit_label, $qty, $price, $subtotal]);

        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE product_id = ? AND branch_id = ?");
        $stmtChk->execute([$pid, $branch_id]);
        if ($stmtChk->fetchColumn() == 0) {
            $stmtIns = $pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, 0)");
            $stmtIns->execute([$pid, $branch_id]);
        }

        $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND branch_id = ?");
        $stmt->execute([$qty * $unit_qty, $pid, $branch_id]);

        $stmt = $pdo->prepare("INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note, created_at) VALUES (?, ?, ?, 'salida', ?, 'Venta POS', NOW())");
        $stmt->execute([$pid, $branch_id, $_SESSION['user']['id'], $qty * $unit_qty]);
    }

    $stmt = $pdo->prepare("UPDATE orders SET total = ? WHERE id = ?");
    $stmt->execute([$total, $order_id]);
    // Validación: pagos deben cubrir el total
    $sumPayments = 0;
    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            $sumPayments += floatval($p['amount'] ?? 0);
        }
    }
    if ($sumPayments < $total) {
        throw new RuntimeException('La suma de pagos es insuficiente para cubrir el total');
    }

    // Registrar pagos en tabla payments
    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            if (!isset($p['amount'])) continue;
            $type = $p['type'] ?? 'otro';
            $amt  = floatval($p['amount']);
            $ref  = $p['ref'] ?? null;

            // Calcular vuelto si es efectivo
            $cash_received = null;
            $change_given  = null;
            if ($type === 'efectivo') {
                $cash_received = $amt;
                $change_given  = max(0, $sumPayments - $total);
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (order_id, amount, method, reference, status, cash_received, change_given, user_id, created_at)
                    VALUES (?, ?, ?, ?, 'completado', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $order_id,
                    $amt,
                    $type,
                    $ref,
                    $cash_received,
                    $change_given,
                    $_SESSION['user']['id']
                ]);
            } catch (\Throwable $e) {
                error_log("checkout.php payments insert error: " . $e->getMessage());
            }
        }
    }

    $pdo->commit();

    $receipt_url = '/mi_tienda/admin/invoice_print.php?order_id=' . intval($order_id);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'      => true,
        'order_id'     => $order_id,
        'receipt_url'  => $receipt_url,
        'message'      => 'Venta procesada'
    ]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("checkout.php error: " . $e->getMessage());

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Error interno al procesar la venta']);
    exit;
}

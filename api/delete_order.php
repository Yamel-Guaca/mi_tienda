<?php
// admin/api/delete_order.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

// Validar permiso
$canDeleteInvoice = !empty($_SESSION['user']['can_delete_invoice']);
if (!$canDeleteInvoice) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para borrar facturas']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id inválido']);
    exit;
}

$pdo = DB::getConnection();

try {
    $stmt = $pdo->prepare("SELECT id, branch_id FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Orden no encontrada']);
        exit;
    }

    // Opcional: restringir por sucursal
    $currentBranchId = $_SESSION['branch_id'] ?? null;
    if ($currentBranchId && intval($order['branch_id']) !== intval($currentBranchId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No puedes borrar una orden de otra sucursal']);
        exit;
    }

    $pdo->beginTransaction();

    // Revertir inventario (opcional)
    $stmt = $pdo->prepare("SELECT product_id, quantity, unit_label FROM order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $it) {
        $stmtUp = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND branch_id = ?");
        $stmtUp->execute([intval($it['quantity']), intval($it['product_id']), intval($order['branch_id'])]);
    }

    // Borrar order_items
    $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // Borrar order
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Orden borrada']);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log("delete_order error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error interno al borrar la orden']);
    exit;
}

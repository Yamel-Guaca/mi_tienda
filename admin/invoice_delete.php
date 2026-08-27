<?php
// admin/invoice_delete.php
// Script para anular (cancelar) una factura y devolver productos al inventario.
// Solo accesible por administradores o supervisores.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// --- Verificar permisos ---
if (!isset($_SESSION['user'])) {
    die("Acceso denegado. Debes iniciar sesión.");
}

$userRoleName = $_SESSION['user']['role_name'] ?? '';
$userId       = $_SESSION['user']['id'] ?? 0;

// ✅ Ajuste: aceptar roles según tu tabla
$allowedRoles = ['Administrador', 'Supervisor'];

if (!in_array($userRoleName, $allowedRoles)) {
    die("No tienes permisos para anular facturas.");
}

// --- Obtener ID de la factura ---
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($orderId <= 0) {
    die("Factura inválida.");
}

try {
    $pdo->beginTransaction();

    // --- Verificar que la factura exista ---
    $stmtCheck = $pdo->prepare("SELECT id, status, branch_id FROM orders WHERE id = ? FOR UPDATE");
    $stmtCheck->execute([$orderId]);
    $orderRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$orderRow) {
        throw new Exception("Factura no encontrada.");
    }

    if ($orderRow['status'] === 'cancelado') {
        throw new Exception("La factura ya está anulada.");
    }

    $branchId = (int)$orderRow['branch_id'];

    // --- Marcar como cancelada con auditoría ---
    $stmtUpdate = $pdo->prepare("
        UPDATE orders 
        SET status = 'cancelado', cancelled_at = NOW(), cancelled_by = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$userId, $orderId]);

    // --- Devolver productos al inventario ---
    $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        // Actualizar stock en INVENTORY
        $stmtUpdateStock = $pdo->prepare("
            UPDATE inventory 
            SET quantity = quantity + ? 
            WHERE product_id = ? AND branch_id = ?
        ");
        $stmtUpdateStock->execute([$item['quantity'], $item['product_id'], $branchId]);

        // Registrar en log de auditoría
        $stmtLog = $pdo->prepare("
            INSERT INTO order_cancellations_log (order_id, product_id, quantity, cancelled_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmtLog->execute([$orderId, $item['product_id'], $item['quantity'], $userId]);
    }

    $pdo->commit();

    echo "✅ Factura #{$orderId} anulada correctamente. Los productos fueron devueltos al inventario.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error al anular la factura: " . $e->getMessage();
}

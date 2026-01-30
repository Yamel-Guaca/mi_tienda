<?php
// admin/branch_visibility_clear.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo administradores
require_role([1]);

header('Content-Type: application/json');

$pdo = DB::getConnection();
$data = json_decode(file_get_contents('php://input'), true);
$branchId = (int)($data['branch_id'] ?? 0);

if ($branchId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Sucursal inválida']);
    exit;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM branch_category_visibility WHERE branch_id=?")->execute([$branchId]);
    $pdo->prepare("DELETE FROM branch_subcategory_visibility WHERE branch_id=?")->execute([$branchId]);
    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

<?php
// admin/branch_visibility_clear.php

// En endpoints JSON es mejor registrar errores en log y no mostrarlos directamente
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo administradores
require_role([1]);

header('Content-Type: application/json; charset=utf-8');

// Validar que la petición sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$pdo = DB::getConnection();
$data = json_decode(file_get_contents('php://input'), true);
$branchId = (int)($data['branch_id'] ?? 0);

if ($branchId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Sucursal inválida']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt1 = $pdo->prepare("DELETE FROM branch_category_visibility WHERE branch_id = ?");
    $stmt1->execute([$branchId]);

    $stmt2 = $pdo->prepare("DELETE FROM branch_subcategory_visibility WHERE branch_id = ?");
    $stmt2->execute([$branchId]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error borrando visibilidad de sucursal ({$branchId}): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al borrar la configuración en la base de datos']);
}
<?php
require_once __DIR__ . '/../../includes/db.php';

$pdo = DB::getConnection();

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    echo json_encode([]);
    exit;
}

$ids = implode(",", array_map("intval", $data));

$stmt = $pdo->query("SELECT id FROM products WHERE id IN ($ids) AND active = 0");
$inactive = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($inactive);

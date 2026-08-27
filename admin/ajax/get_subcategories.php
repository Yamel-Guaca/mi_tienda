<?php
// /mi_tienda/admin/ajax/get_subcategories.php

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$pdo = DB::getConnection();

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if ($category_id <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY name");
$stmt->execute([$category_id]);

$subcats = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($subcats);

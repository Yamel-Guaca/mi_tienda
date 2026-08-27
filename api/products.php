<?php
// api/products.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = DB::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // listar productos activos
    $stmt = $pdo->query("SELECT p.*, IFNULL(SUM(i.quantity),0) as total_stock FROM products p LEFT JOIN inventory i ON p.id = i.product_id GROUP BY p.id");
    $products = $stmt->fetchAll();
    json_response(['success' => true, 'data' => $products]);
}

if ($method === 'POST') {
    // crear producto (espera JSON)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_response(['success'=>false,'msg'=>'JSON inválido'],400);
    $stmt = $pdo->prepare("INSERT INTO products (sku, name, description, price, cost, tax_percent, active) VALUES (:sku,:name,:description,:price,:cost,:tax,1)");
    $stmt->execute([
        'sku'=>$input['sku'] ?? null,
        'name'=>$input['name'],
        'description'=>$input['description'] ?? null,
        'price'=>$input['price'] ?? 0,
        'cost'=>$input['cost'] ?? 0,
        'tax'=>$input['tax_percent'] ?? 0
    ]);
    json_response(['success'=>true,'id'=>$pdo->lastInsertId()],201);
}

// PUT y DELETE pueden implementarse similarmente (omito por brevedad)
json_response(['success'=>false,'msg'=>'Método no soportado'],405);

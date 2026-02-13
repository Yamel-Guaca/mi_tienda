<?php
// admin/ajax/search_products.php, este codigo esta siendo usado por el buscador o filtro del archivo de
// codigo productos.php se creo js y el contenedor del buscador 2. HTML: campo de búsqueda (pegar en admin/productos.php)
// Inserta antes del <h3>Lista de Productos</h3> dentro del contenedor:
// 3. JS: búsqueda en vivo con debounce (pegar al final de admin/productos.php)
// Pega antes de </div> <!-- cierre container --> o justo antes de </body>:
// Devuelve JSON con productos filtrados por q (nombre, sku, categoría, subcategoría)

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

// Seguridad mínima
if (empty($_GET['q']) && !isset($_GET['q'])) {
    echo json_encode([]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 200); // limitar longitud

try {
    require_once __DIR__ . '/../../includes/db.php';
    $pdo = DB::getConnection();

    // Preparar consulta segura (LIKE en columnas relevantes)
    $sql = "
        SELECT p.id, p.name, p.sku, p.price, p.min_quantity, p.cost_initial, p.active,
               c.name AS category_name, s.name AS subcategory_name,
               (SELECT filename FROM product_images WHERE product_id=p.id AND is_main=1 LIMIT 1) AS main_image
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id
        WHERE (p.name LIKE :like OR p.sku LIKE :like OR c.name LIKE :like OR s.name LIKE :like)
        ORDER BY p.id DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $like = '%' . $q . '%';
    $stmt->bindValue(':like', $like, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalizar salida (tipos)
    $out = array_map(function($r){
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'] ?? '',
            'sku' => $r['sku'] ?? '',
            'price' => isset($r['price']) ? (float)$r['price'] : 0.0,
            'min_quantity' => isset($r['min_quantity']) ? (int)$r['min_quantity'] : 0,
            'cost_initial' => isset($r['cost_initial']) ? (float)$r['cost_initial'] : 0.0, // NUEVO CAMPO
            'active' => !empty($r['active']) ? 1 : 0,
            'category_name' => $r['category_name'] ?? '',
            'subcategory_name' => $r['subcategory_name'] ?? '',
            'main_image' => $r['main_image'] ?? null
        ];
    }, $rows);

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    // No exponer detalles en producción
    http_response_code(500);
    echo json_encode(['error' => 'Error interno']);
    exit;
}

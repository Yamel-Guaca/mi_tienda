<?php
// process_order.php - Versión Segura (Precios calculados desde la BD)
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = DB::getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['items']) || empty($data['customer'])) {
    echo json_encode(['success' => false, 'error' => 'Datos de pedido incompletos']);
    exit;
}

$cust = $data['customer'];
$items = $data['items'];

// --- REVISIÓN RIGUROSA DE PRECIOS DESDE LA BASE DE DATOS ---
$totalCalculado = 0;
$itemsValidados = [];

$stmtProd = $pdo->prepare("SELECT id, name, COALESCE(price_unit, price, 0) AS price FROM products WHERE id = ?");
$stmtTier = $pdo->prepare("SELECT price, unit_label FROM product_prices WHERE id = ? AND product_id = ?");

foreach ($items as $item) {
    $pId = intval($item['id']);
    $qty = max(1, intval($item['qty']));
    
    // Consultar precio base en BD
    $stmtProd->execute([$pId]);
    $prodBD = $stmtProd->fetch(PDO::FETCH_ASSOC);

    if (!$prodBD) {
        echo json_encode(['success' => false, 'error' => 'Un producto en el carrito no existe']);
        exit;
    }

    $realPrice = floatval($prodBD['price']);
    $itemTitle = $prodBD['name'];

    // Si seleccionó una presentación/tier
    if (!empty($item['tier_id'])) {
        $tierId = intval($item['tier_id']);
        $stmtTier->execute([$tierId, $pId]);
        $tierBD = $stmtTier->fetch(PDO::FETCH_ASSOC);
        if ($tierBD) {
            $realPrice = floatval($tierBD['price']);
            $itemTitle .= " (" . $tierBD['unit_label'] . ")";
        }
    }

    $subtotal = $realPrice * $qty;
    $totalCalculado += $subtotal;

    $itemsValidados[] = [
        'id' => $pId,
        'name' => $itemTitle,
        'price' => $realPrice,
        'qty' => $qty,
        'subtotal' => $subtotal
    ];
}

$amountInCents = (int) round($totalCalculado * 100);

if ($amountInCents <= 0) {
    echo json_encode(['success' => false, 'error' => 'Monto total no válido']);
    exit;
}

$dispatchBranchId = 1; // Sucursal principal de despacho

try {
    $pdo->beginTransaction();

    // Guardar orden con estado PENDIENTE
    $stmtOrder = $pdo->prepare("
        INSERT INTO orders 
        (branch_id, customer_name, customer_email, customer_doc_type, customer_doc_num, customer_phone, customer_address, total, status, fulfillment_status, payment_method) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', 'pendiente', 'Wompi')
    ");
    $stmtOrder->execute([
        $dispatchBranchId,
        $cust['name'],
        $cust['email'],
        $cust['doc_type'],
        $cust['doc_num'],
        $cust['phone'],
        $cust['address'],
        $totalCalculado
    ]);
    
    $orderId = $pdo->lastInsertId();

    // Guardar items validados
    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($itemsValidados as $item) {
        $stmtItem->execute([$orderId, $item['id'], $item['name'], $item['price'], $item['qty'], $item['subtotal']]);
    }

    $pdo->commit();

    // Petición a Wompi
    $wompiPrivateKey = "prv_prod_SKUhzYrGCS1dRXchtiIcMCvypS192Ttf-"; 
    $apiUrl = "https://production.wompi.co/v1/payment_links";

    $payload = [
        "name" => "Pedido #" . $orderId . " - Mi Tienda",
        "description" => "Pago de pedido #" . $orderId,
        "single_use" => true,
        "currency" => "COP",
        "amount_in_cents" => $amountInCents,
        "redirect_url" => "http://tudominio.com/catalogo.php?order_id=" . $orderId
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($wompiPrivateKey)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode === 201 && isset($responseData['data']['id'])) {
        echo json_encode([
            'success' => true,
            'payment_url' => "https://checkout.wompi.co/l/" . $responseData['data']['id'],
            'order_id' => $orderId
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al conectar con la pasarela de pago']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error interno procesando la orden']);
}
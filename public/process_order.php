<?php
header('Content-Type: application/json');

// Recibir datos JSON enviados desde JS
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'error' => 'El carrito está vacío']);
    exit;
}

// 1. Calcular el valor total en centavos para Colombia (COP)
$total = 0;
foreach ($data['items'] as $item) {
    $total += $item['price'] * $item['qty'];
}
$amountInCents = (int) round($total * 100);

if ($amountInCents <= 0) {
    echo json_encode(['success' => false, 'error' => 'Monto no válido']);
    exit;
}

// 2. Configurar la API de Wompi (Entorno de Producción)
$wompiPrivateKey = "prv_prod_SKUhzYrGCS1dRXchtiIcMCvypS192Ttf-"; 
$apiUrl = "https://production.wompi.co/v1/payment_links";

// 3. Estructura exacta requerida por la API de Payment Links de Wompi
$payload = [
    "name" => "Compra FRESS_BOLSA",
    "description" => "Pago de pedido en tienda virtual",
    "single_use" => true,
    "currency" => "COP",
    "amount_in_cents" => $amountInCents,
    "collect_shipping" => false,
    "redirect_url" => "http://localhost/mi_tienda/public/catalogo.php"
];

// 4. Petición POST cURL a Wompi
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

// 5. Devolver la URL del link dinámico creado por Wompi
if ($httpCode === 201 && isset($responseData['data']['id'])) {
    $linkId = $responseData['data']['id'];
    $paymentUrl = "https://checkout.wompi.co/l/" . $linkId;
    
    echo json_encode([
        'success' => true,
        'payment_url' => $paymentUrl
    ]);
} else {
    // Si falla la validación, mostrar los detalles específicos que devuelve Wompi
    $errorMessage = 'Error de validación';
    if (isset($responseData['error']['messages'])) {
        $errorMessage = json_encode($responseData['error']['messages']);
    } else if (isset($responseData['error']['type'])) {
        $errorMessage = $responseData['error']['type'];
    }

    echo json_encode([
        'success' => false,
        'error' => $errorMessage
    ]);
}
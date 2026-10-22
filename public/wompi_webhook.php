<?php
// wompi_webhook.php - Recibe confirmaciones reales de Wompi
require_once __DIR__ . '/includes/db.php';

// Leer el JSON que envía Wompi
$input = file_get_contents('php://input');
$event = json_decode($input, true);

// Verificar que sea un evento válido de transacción
if (!$event || !isset($event['event']) || $event['event'] !== 'transaction.updated') {
    http_response_code(400);
    echo "Evento no válido";
    exit;
}

$transaction = $event['data']['transaction'] ?? null;

if ($transaction) {
    $status = $transaction['status']; // APPROVED, DECLINED, VOIDED, etc.
    $reference = $transaction['reference'] ?? ''; // Referencia enviada a Wompi
    $wompiId = $transaction['id'] ?? '';

    // Extraer el número de orden (ejemplo: si la descripción o nombre traía Pedido #123)
    // O si usas la referencia interna
    try {
        $pdo = DB::getConnection();

        if ($status === 'APPROVED') {
            // Actualizar la orden a PAGADO de forma 100% segura
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET status = 'pagado', payment_reference = ? 
                WHERE id = ? OR wompi_transaction_id = ?
            ");
            // Si guardas la referencia de Wompi
            $stmt->execute([$wompiId, intval($reference), $wompiId]);
        } else if (in_array($status, ['DECLINED', 'VOIDED', 'ERROR'])) {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'rechazado' WHERE id = ?");
            $stmt->execute([intval($reference)]);
        }

        http_response_code(200);
        echo "OK";
    } catch (Exception $e) {
        http_response_code(500);
        echo "Error en base de datos";
    }
}
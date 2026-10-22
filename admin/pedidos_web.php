<?php
// admin/pedidos_web.php - Versión Segura y Optimizada
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1, 2]);
$pdo = DB::getConnection();

// --- 1. GENERACIÓN DE TOKEN CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = '';
$msgType = 'success';

// --- 2. SANITIZACIÓN Y ENVÍO SEGURO DE CORREOS ---
function notify_customer_by_email($email, $name, $orderId, $status, $branchName) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    // Prevenir Inyección de Cabeceras (Mail Header Injection)
    $cleanEmail = str_replace(["\r", "\n"], '', $email);
    $cleanName = str_replace(["\r", "\n"], '', $name);

    $subject = "Actualizacion de tu pedido #{$orderId} - Mi Tienda";
    
    switch ($status) {
        case 'en_preparacion':
            $message = "Hola {$cleanName},\n\nTu pedido #{$orderId} ya esta en proceso de preparacion en nuestra sucursal '{$branchName}'.\nPronto estara listo para el envio.\n\n¡Gracias por tu compra!";
            break;
        case 'despachado':
            $message = "Hola {$cleanName},\n\n¡Buenas noticias! Tu pedido #{$orderId} ha sido despachado y va en camino a tu direccion de entrega.\n\n¡Atento a tu telefono!";
            break;
        case 'entregado':
            $message = "Hola {$cleanName},\n\nTu pedido #{$orderId} ha sido entregado con exito.\n\nGracias por confiar en nosotros.";
            break;
        default:
            return;
    }

    $headers = "From: no-reply@mitienda.com\r\n" .
               "Reply-To: soporte@mitienda.com\r\n" .
               "X-Mailer: PHP/" . phpversion();

    @mail($cleanEmail, $subject, $message, $headers);
}

// --- 3. ACTUALIZACIÓN SEGURA DE PEDIDOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    
    // Validación de Token CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        die('Solicitud no autorizada (CSRF Token inválido).');
    }

    $orderId = intval($_POST['order_id'] ?? 0);
    $fulfillmentStatus = $_POST['fulfillment_status'] ?? 'pendiente';
    $branchId = intval($_POST['branch_id'] ?? 0);

    // Lista Blanca (Whitelist) de Estados Permitiendo la entrada
    $allowedStatuses = ['pendiente', 'en_preparacion', 'despachado', 'entregado'];
    if (!in_array($fulfillmentStatus, $allowedStatuses, true)) {
        $fulfillmentStatus = 'pendiente';
    }

    if ($orderId > 0) {
        // Actualizar en Base de Datos
        $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = ?, branch_id = ? WHERE id = ?");
        $stmt->execute([$fulfillmentStatus, $branchId, $orderId]);

        // Obtener datos del pedido y la sucursal para la notificación
        $stmtSelect = $pdo->prepare("
            SELECT o.*, b.name AS branch_name 
            FROM orders o 
            LEFT JOIN branches b ON b.id = ? 
            WHERE o.id = ?
        ");
        $stmtSelect->execute([$branchId, $orderId]);
        $orderData = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        if ($orderData) {
            $branchName = !empty($orderData['branch_name']) ? $orderData['branch_name'] : 'Sucursal Principal';
            notify_customer_by_email(
                $orderData['customer_email'] ?? '',
                $orderData['customer_name'] ?? 'Cliente',
                $orderId,
                $fulfillmentStatus,
                $branchName
            );
        }

        $msg = "Pedido #{$orderId} actualizado correctamente y cliente notificado por correo.";
        $msgType = 'success';
    }
}

// --- 4. CONSULTA DE PEDIDOS FILTRADOS PARA TIENDA VIRTUAL ---
$orders = $pdo->query("
    SELECT o.*, b.name AS branch_name 
    FROM orders o 
    LEFT JOIN branches b ON o.branch_id = b.id 
    WHERE (o.payment_method IN ('Wompi', 'Web', 'WhatsApp') 
       OR (o.customer_address IS NOT NULL AND o.customer_address != ''))
    ORDER BY o.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$branches = $pdo->query("SELECT id, name FROM branches WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

function safe_html(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Pedidos Web</title>
<style>
  body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
  .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
  table { width: 100%; border-collapse: collapse; margin-top: 15px; }
  th, td { padding: 10px; border: 1px solid #ddd; text-align: left; font-size: 14px; }
  th { background: #0B2545; color: #fff; }
  .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; display: inline-block; }
  .badge-pagado, .badge-approved { background: #22c55e; color: #fff; }
  .badge-pendiente { background: #f59e0b; color: #fff; }
  .badge-cancelado, .badge-rechazado, .badge-declined { background: #ef4444; color: #fff; }
  .msg { padding: 12px; margin-bottom: 15px; border-radius: 6px; font-weight: 500; }
  .msg-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
  .msg-danger { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
  .btn-submit { padding: 6px 12px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
  .btn-submit:hover { background: #0056b3; }
</style>
</head>
<body>

<div class="container">
  <h2>Gestión de Pedidos de la Tienda Virtual</h2>
  <a href="/mi_tienda/admin/dashboard.php">&larr; Volver al Dashboard</a>
  
  <?php if ($msg): ?>
    <div class="msg msg-<?= $msgType ?>"><?= safe_html($msg) ?></div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th># Orden</th>
        <th>Fecha</th>
        <th>Cliente</th>
        <th>Teléfono / Dirección</th>
        <th>Total</th>
        <th>Estado Pago</th>
        <th>Sucursal de Despacho</th>
        <th>Estado Despacho</th>
        <th>Acción</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($orders)): ?>
        <tr><td colspan="9" style="text-align:center; padding:20px;">No hay pedidos web registrados.</td></tr>
      <?php else: ?>
        <?php foreach ($orders as $o): 
          $status = strtolower($o['status'] ?? 'pendiente');
          $fulfillmentStatus = strtolower($o['fulfillment_status'] ?? 'pendiente');
          $custName = safe_html($o['customer_name'] ?? 'Cliente Web');
          $custEmail = safe_html($o['customer_email'] ?? '');
          $custPhone = safe_html($o['customer_phone'] ?? '');
          $custAddress = safe_html($o['customer_address'] ?? '');
          $total = floatval($o['total'] ?? 0);
          $orderId = intval($o['id']);
          $branchId = intval($o['branch_id'] ?? 0);
        ?>
          <tr>
            <td><strong>#<?= $orderId ?></strong></td>
            <td><?= safe_html($o['created_at'] ?? '') ?></td>
            <td><?= $custName ?><?= $custEmail ? '<br><small>'.$custEmail.'</small>' : '' ?></td>
            <td><?= $custPhone ?><?= $custAddress ? '<br><small>'.$custAddress.'</small>' : '' ?></td>
            <td>$<?= number_format($total, 2) ?></td>
            <td><span class="badge badge-<?= $status ?>"><?= strtoupper($status) ?></span></td>
            
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
              <input type="hidden" name="order_id" value="<?= $orderId ?>">
              <td>
                <select name="branch_id" style="padding:4px; border-radius:4px;">
                  <option value="0">Sin Asignar</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $branchId == $b['id'] ? 'selected' : '' ?>>
                      <?= safe_html($b['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="fulfillment_status" style="padding:4px; border-radius:4px;">
                  <option value="pendiente" <?= $fulfillmentStatus === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                  <option value="en_preparacion" <?= $fulfillmentStatus === 'en_preparacion' ? 'selected' : '' ?>>En Preparación</option>
                  <option value="despachado" <?= $fulfillmentStatus === 'despachado' ? 'selected' : '' ?>>Despachado</option>
                  <option value="entregado" <?= $fulfillmentStatus === 'entregado' ? 'selected' : '' ?>>Entregado</option>
                </select>
              </td>
              <td>
                <button type="submit" name="update_order" class="btn-submit">
                  Guardar y Notificar
                </button>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
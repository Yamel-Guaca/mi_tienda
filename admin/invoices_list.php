<?php
// admin/invoices_list.php
session_start();
require_once __DIR__ . '/../includes/db.php';

// ✅ Validar sesión iniciada
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo = DB::getConnection();

// --- Consultar todas las facturas ---
$stmt = $pdo->query("SELECT id, customer_name, total, status, created_at FROM orders ORDER BY created_at DESC");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Listado de Facturas</title>
<style>
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
th { background: #f2f2f2; }
.btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; margin-right:4px; }
.btn-danger { background: #d9534f; color: #fff; }
.btn-danger:hover { background: #c9302c; }
.btn-secondary { background: #5bc0de; color: #fff; }
.btn-secondary:hover { background: #31b0d5; }
</style>
</head>
<body>
<h1>📋 Listado de Facturas</h1>
<!-- ✅ ENLACES RÁPIDOS COMPLETOS -->
    <p class="small" style="margin-top:12px;">Enlaces rápidos: 
      <a href="/mi_tienda/admin/pos.php">Atras</a> ·
      
    </p>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Cliente</th>
      <th>Total</th>
      <th>Estado</th>
      <th>Fecha</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($orders as $order): ?>
      <tr>
        <td><?= htmlspecialchars($order['id']) ?></td>
        <td><?= htmlspecialchars($order['customer_name']) ?></td>
        <td>$<?= number_format($order['total'], 2, ",", ".") ?></td>
        <td><?= htmlspecialchars($order['status']) ?></td>
        <td><?= htmlspecialchars($order['created_at']) ?></td>
        <td>
          <!-- Botón de reimpresión disponible para todos los roles -->
          <a href="invoice_print.php?order_id=<?= $order['id'] ?>" 
             target="_blank" 
             class="btn btn-secondary">Reimprimir</a>

          <!-- Botón de anular solo para Administrador o Supervisor -->
          <?php if ($order['status'] !== 'cancelado'): ?>
            <?php if (isset($_SESSION['user']['role_id']) && in_array($_SESSION['user']['role_id'], [1,2])): ?>
              <a href="invoice_delete.php?order_id=<?= $order['id'] ?>"
                 onclick="return confirm('¿Seguro que deseas anular la factura #<?= $order['id'] ?>?');"
                 class="btn btn-danger">Anular</a>
            <?php else: ?>
              <span style="color:gray;">Sin permisos</span>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:gray;">Anulada</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>

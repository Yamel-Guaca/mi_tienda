<?php
// admin/auditoria_facturas.php
// Reporte de auditoría: facturas anuladas con detalle de quién las anuló y productos devueltos.

session_start();
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// --- Verificar permisos ---
if (!isset($_SESSION['user'])) {
    die("Acceso denegado. Debes iniciar sesión.");
}

$userRoleName = $_SESSION['user']['role_name'] ?? '';
$allowedRoles = ['Administrador', 'Supervisor'];
if (!in_array($userRoleName, $allowedRoles)) {
    die("No tienes permisos para ver el reporte de auditoría.");
}

// --- Consultar facturas anuladas ---
$stmt = $pdo->prepare("
    SELECT o.id, o.customer_name, o.total, o.cancelled_at, u.name AS cancelled_by
    FROM orders o
    LEFT JOIN users u ON u.id = o.cancelled_by
    WHERE o.status = 'cancelado'
    ORDER BY o.cancelled_at DESC
");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Auditoría de Facturas Anuladas</title>
<style>
body { font-family: Arial, sans-serif; margin:20px; }
table { border-collapse: collapse; width: 100%; margin-top:20px; }
th, td { border:1px solid #ccc; padding:8px; text-align:left; }
th { background:#f2f2f2; }
h1 { font-size:20px; }
</style>
</head>
<body>
<h1>📋 Auditoría de Facturas Anuladas</h1>

<table>
    <thead>
        <tr>
            <th>ID Factura</th>
            <th>Cliente</th>
            <th>Total</th>
            <th>Fecha Anulación</th>
            <th>Anulada por</th>
            <th>Productos devueltos</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $order): ?>
        <tr>
            <td><?= htmlspecialchars($order['id']) ?></td>
            <td><?= htmlspecialchars($order['customer_name']) ?></td>
            <td>$<?= number_format($order['total'], 2, ",", ".") ?></td>
            <td><?= htmlspecialchars($order['cancelled_at']) ?></td>
            <td><?= htmlspecialchars($order['cancelled_by']) ?></td>
            <td>
                <?php
                // --- Consultar productos devueltos desde order_cancellations_log ---
                $stmtItems = $pdo->prepare("
                    SELECT l.product_id, l.quantity, p.name 
                    FROM order_cancellations_log l
                    LEFT JOIN products p ON p.id = l.product_id
                    WHERE l.order_id = ?
                ");
                $stmtItems->execute([$order['id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                if ($items) {
                    echo "<ul>";
                    foreach ($items as $item) {
                        echo "<li>" . htmlspecialchars($item['name']) . " (+" . intval($item['quantity']) . ")</li>";
                    }
                    echo "</ul>";
                } else {
                    echo "Sin detalle";
                }
                ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>

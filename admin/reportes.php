<?php
// admin/reportes.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ NO usar session_start() aquí (ya está en auth_functions.php)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1, 2]);


$pdo = DB::getConnection();

// ============================================================
// 1. SUCURSAL SELECCIONADA
// ============================================================

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// ✅ Evitar error de htmlspecialchars(null)
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ============================================================
// 2. FILTROS DE FECHA
// ============================================================

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');

// ============================================================
// 3. CONSULTAR VENTAS
// ============================================================

$stmt = $pdo->prepare("
    SELECT o.*, u.name AS user_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.branch_id = ?
    AND DATE(o.created_at) BETWEEN ? AND ?
    AND o.status != 'cancelado'
    ORDER BY o.id DESC
");
$stmt->execute([$currentBranchId, $start, $end]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales
$totalAmount = 0;
$totalOrders = count($sales);

foreach ($sales as $s) {
    $totalAmount += $s['total'];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reportes - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
    input { padding:6px; }
    .btn { padding:6px 10px; background:#0a6; color:#fff; border-radius:4px; text-decoration:none; }
</style>
</head>
<body>

<header>
    <h2>Reportes de Ventas</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
</header>

<div class="container">

<h3>Filtrar por fecha</h3>

<form method="GET" action="/mi_tienda/admin/reportes.php">
    <label>Desde:</label>
    <input type="date" name="start" value="<?= $start ?>">
    <label>Hasta:</label>
    <input type="date" name="end" value="<?= $end ?>">
    <button class="btn">Filtrar</button>
</form>

<h3>Resumen</h3>
<p><strong>Total vendido:</strong> $<?= number_format($totalAmount, 2) ?></p>
<p><strong>Pedidos realizados:</strong> <?= $totalOrders ?></p>

<h3>Ventas</h3>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Total</th>
            <th>Fecha</th>
            <th>Estado</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($sales as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['user_name']) ?></td>
            <td>$<?= number_format($s['total'], 2) ?></td>
            <td><?= $s['created_at'] ?></td>
            <td><?= $s['status'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>

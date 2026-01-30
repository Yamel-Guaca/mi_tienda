<?php
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1]); // Solo administrador

$pdo = DB::getConnection();

// Sucursal desde sesión
$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? "No seleccionada";

// Filtros
$desde = $_GET['desde'] ?? date("Y-m-d");
$hasta = $_GET['hasta'] ?? date("Y-m-d");

// Consulta principal
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        SUM(oi.quantity) AS total_qty,
        SUM(oi.subtotal) AS total_ingreso,
        SUM(oi.quantity * p.cost_initial) AS total_costo,
        (SUM(oi.subtotal) - SUM(oi.quantity * p.cost_initial)) AS ganancia,
        ROUND(
            (SUM(oi.subtotal) - SUM(oi.quantity * p.cost_initial)) 
            / NULLIF(SUM(oi.subtotal), 0) * 100, 
        2) AS margen
    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.branch_id = ?
    GROUP BY p.id
    ORDER BY ganancia DESC
");
$stmt->execute([$desde, $hasta, $currentBranchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales
$total_ingreso = 0;
$total_costo   = 0;
$total_ganancia = 0;

foreach ($rows as $r) {
    $total_ingreso += $r['total_ingreso'];
    $total_costo   += $r['total_costo'];
    $total_ganancia += $r['ganancia'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ganancias</title>
    <link rel="stylesheet" href="../public/css/admin.css">
</head>
<body>

<h2>Reporte de Ganancias</h2>

<div style="margin-bottom:20px;">
    <form method="GET">
        <label>Desde:</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">

        <label>Hasta:</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">

        <button class="btn">Filtrar</button>
    </form>
</div>

<h3>Sucursal: <?= htmlspecialchars($currentBranchName) ?></h3>

<!-- Totales -->
<div class="totals-box">
    <div class="total-item">
        <strong>Total Ingreso:</strong> $<?= number_format($total_ingreso, 2) ?>
    </div>
    <div class="total-item">
        <strong>Total Costo:</strong> $<?= number_format($total_costo, 2) ?>
    </div>
    <div class="total-item">
        <strong>Ganancia Total:</strong> $<?= number_format($total_ganancia, 2) ?>
    </div>
</div>

<!-- Tabla -->
<table class="table">
    <thead>
        <tr>
            <th>Producto</th>
            <th>Cant.</th>
            <th>Ingreso</th>
            <th>Costo</th>
            <th>Ganancia</th>
            <th>Margen %</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= (int)$r['total_qty'] ?></td>
            <td>$<?= number_format($r['total_ingreso'], 2) ?></td>
            <td>$<?= number_format($r['total_costo'], 2) ?></td>
            <td>$<?= number_format($r['ganancia'], 2) ?></td>
            <td><?= $r['margen'] ?>%</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>

<?php
// admin/cierres_diarios.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ❌ session_start();  ← ELIMINADO (ya se inicia en auth_functions.php)

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (1) y Supervisor (2)
require_role([1, 2]);

$pdo = DB::getConnection();

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// ✅ Ajuste para evitar el deprecated de htmlspecialchars(null)
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT 
        DATE(created_at) AS fecha,
        COUNT(*)         AS pedidos,
        SUM(total)       AS total
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY fecha DESC
");
$stmt->execute([$currentBranchId, $start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cierres diarios por sucursal</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
    input { padding:6px; margin-right:5px; }
</style>
</head>
<body>

<header>
    <h2>Cierres diarios</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
</header>

<div class="container">

<form method="GET" action="cierres_diarios.php">
    <label>Desde:</label>
    <input type="date" name="start" value="<?= $start ?>">
    <label>Hasta:</label>
    <input type="date" name="end" value="<?= $end ?>">
    <button type="submit">Filtrar</button>
</form>

<table>
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Pedidos</th>
            <th>Total vendido</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= $r['fecha'] ?></td>
            <td><?= $r['pedidos'] ?></td>
            <td>$<?= number_format($r['total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($rows)): ?>
    <p>No hay ventas registradas en el rango seleccionado.</p>
<?php endif; ?>

</div>
</body>
</html>

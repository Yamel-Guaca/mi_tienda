<?php
// admin/kardex.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ❌ session_start();  ← ELIMINADO (ya se inicia en auth_functions.php)

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (1) y Auxiliar (4)
require_role([1, 4]);

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

$productId = intval($_GET['product_id'] ?? 0);
$start     = $_GET['start'] ?? date('Y-m-01');
$end       = $_GET['end']   ?? date('Y-m-d');

// Productos para select
$products = $pdo->query("
    SELECT id, name FROM products WHERE active = 1 ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$movements = [];
$currentProductName = "";

if ($productId > 0) {
    $stmt = $pdo->prepare("SELECT name FROM products WHERE id=?");
    $stmt->execute([$productId]);
    $currentProductName = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.type,
            m.quantity,
            m.note,
            m.created_at,
            u.name AS user_name
        FROM inventory_movements m
        JOIN users u ON u.id = m.user_id
        WHERE m.product_id = ?
          AND m.branch_id  = ?
          AND DATE(m.created_at) BETWEEN ? AND ?
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([$productId, $currentBranchId, $start, $end]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Kardex por producto</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
    input, select { padding:6px; margin-right:5px; }
</style>
</head>
<body>

<header>
    <h2>Kardex por producto</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
</header>

<div class="container">

<form method="GET" action="kardex.php">
    <label>Producto:</label>
    <select name="product_id" required>
        <option value="">Seleccione</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id'] == $productId ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Desde:</label>
    <input type="date" name="start" value="<?= $start ?>">

    <label>Hasta:</label>
    <input type="date" name="end" value="<?= $end ?>">

    <button type="submit">Filtrar</button>
</form>

<?php if ($productId && $currentProductName): ?>
    <h3>Movimientos de: <?= htmlspecialchars($currentProductName) ?></h3>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Usuario</th>
                <th>Nota</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $m): ?>
            <tr>
                <td><?= $m['created_at'] ?></td>
                <td><?= htmlspecialchars($m['type']) ?></td>
                <td><?= (int)$m['quantity'] ?></td>
                <td><?= htmlspecialchars($m['user_name']) ?></td>
                <td><?= htmlspecialchars($m['note']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
</body>
</html>

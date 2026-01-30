<?php
// admin/inventario_bajo.php

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

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        p.sku,
        p.min_quantity,
        IFNULL(i.quantity, 0) AS stock
    FROM products p
    LEFT JOIN inventory i 
        ON p.id = i.product_id 
       AND i.branch_id = ?
    WHERE p.active = 1
      AND p.min_quantity > 0
      AND IFNULL(i.quantity, 0) < p.min_quantity
    ORDER BY p.name
");
$stmt->execute([$currentBranchId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario bajo mínimo</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#d9534f; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
</style>
</head>
<body>

<header>
    <h2>Inventario bajo mínimo</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
</header>

<div class="container">
    <p>Productos cuyo stock está por debajo del mínimo configurado.</p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>SKU</th>
                <th>Producto</th>
                <th>Stock actual</th>
                <th>Mínimo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i): ?>
            <tr>
                <td><?= $i['id'] ?></td>
                <td><?= htmlspecialchars($i['sku']) ?></td>
                <td><?= htmlspecialchars($i['name']) ?></td>
                <td style="color:#d9534f; font-weight:bold;"><?= (int)$i['stock'] ?></td>
                <td><?= (int)$i['min_quantity'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (empty($items)): ?>
        <p>No hay productos por debajo del mínimo en esta sucursal.</p>
    <?php endif; ?>
</div>

</body>
</html>

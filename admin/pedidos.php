<?php
// admin/pedidos.php

// Mostrar errores en desarrollo (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ NO usar session_start() aquí (ya está en auth_functions.php)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (role_id = 1)
require_role([1]);

$pdo = DB::getConnection();

// ============================================================
// 1. SUCURSAL SELECCIONADA
// ============================================================

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ============================================================
// 2. ACCIONES (cambiar estado)
// ============================================================

$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$msg    = "";

if ($action === 'status' && $id) {
    $new_status = $_GET['status'] ?? null;

    if (in_array($new_status, ['pendiente', 'completado', 'cancelado'])) {
        $stmt = $pdo->prepare("UPDATE orders SET status=? WHERE id=? AND branch_id=?");
        $stmt->execute([$new_status, $id, $currentBranchId]);
        $msg = "Estado actualizado.";
    }
}

// ============================================================
// 3. OBTENER LISTA DE PEDIDOS DE LA SUCURSAL
// ============================================================

$orders = $pdo->prepare("
    SELECT id, customer_name, total, status, created_at
    FROM orders
    WHERE branch_id = ?
    ORDER BY id DESC
");
$orders->execute([$currentBranchId]);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. DETALLE DE PEDIDO
// ============================================================

$detail = null;
$items  = [];

if ($action === 'view' && $id) {
    $stmt = $pdo->prepare("
        SELECT * FROM orders WHERE id=? AND branch_id=?
    ");
    $stmt->execute([$id, $currentBranchId]);
    $detail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detail) {
        $stmt = $pdo->prepare("
            SELECT oi.*, p.name 
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id=?
        ");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $msg = "Pedido no encontrado.";
        $action = null;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Pedidos - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
    .btn { padding:6px 10px; border-radius:4px; text-decoration:none; color:#fff; }
    .btn-view { background:#007bff; }
    .btn-status { background:#5bc0de; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
</style>
</head>
<body>

<header>
    <h2>Gestión de Pedidos</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($action === 'view' && $detail): ?>

    <h3>Detalle del Pedido #<?= $detail['id'] ?></h3>

    <p><strong>Cliente:</strong> <?= htmlspecialchars($detail['customer_name']) ?></p>
    <p><strong>Total:</strong> $<?= number_format($detail['total'], 2) ?></p>
    <p><strong>Estado:</strong> <?= htmlspecialchars($detail['status']) ?></p>
    <p><strong>Fecha:</strong> <?= $detail['created_at'] ?></p>

    <h3>Productos</h3>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['name']) ?></td>
                <td><?= intval($it['quantity']) ?></td>
                <td>$<?= number_format($it['subtotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:20px;">
        <a href="/mi_tienda/admin/pedidos.php" class="btn btn-view">Volver</a>
    </p>

<?php else: ?>

<h3>Lista de Pedidos</h3>

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
        <?php foreach ($orders as $o): ?>
        <tr>
            <td><?= $o['id'] ?></td>
            <td><?= htmlspecialchars($o['customer_name']) ?></td>
            <td>$<?= number_format($o['total'], 2) ?></td>
            <td><?= htmlspecialchars($o['status']) ?></td>
            <td><?= $o['created_at'] ?></td>
            <td>
                <a class="btn btn-view" href="/mi_tienda/admin/pedidos.php?action=view&id=<?= $o['id'] ?>">Ver</a>
                <a class="btn btn-status" href="/mi_tienda/admin/pedidos.php?action=status&id=<?= $o['id'] ?>&status=pendiente">Pendiente</a>
                <a class="btn btn-status" href="/mi_tienda/admin/pedidos.php?action=status&id=<?= $o['id'] ?>&status=completado">Completado</a>
                <a class="btn btn-status" href="/mi_tienda/admin/pedidos.php?action=status&id=<?= $o['id'] ?>&status=cancelado">Cancelado</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

</div>

</body>
</html>

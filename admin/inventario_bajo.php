<?php
// admin/inventario_bajo.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Permitir acceso a Administrador (1), Cajero (3) y Auxiliar (4)
require_role([1, 3, 4]);

$pdo = DB::getConnection();

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

/* CSRF token (si no existe) */
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/*
  Si ya se envió una solicitud (bandera en sesión), no cargamos el listado.
  La bandera debe establecerse en crear_pedido.php cuando se crea la solicitud:
    $_SESSION['solicitud_enviada'] = true;
  Y debe eliminarse cuando la solicitud se reciba o se quiera volver a mostrar.
*/
$items = [];
if (empty($_SESSION['solicitud_enviada'])) {
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.name,
            p.sku,
            COALESCE(pb.min_quantity, p.min_quantity) AS min_quantity,
            IFNULL(i.quantity, 0) AS stock
        FROM products p
        LEFT JOIN inventory i 
            ON p.id = i.product_id 
           AND i.branch_id = ?
        LEFT JOIN product_branch_minimums pb
            ON pb.product_id = p.id
           AND pb.branch_id = ?
        WHERE p.active = 1
          AND COALESCE(pb.min_quantity, p.min_quantity) > 0
          AND IFNULL(i.quantity, 0) < COALESCE(pb.min_quantity, p.min_quantity)
        ORDER BY p.name
    ");
    $stmt->execute([$currentBranchId, $currentBranchId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario bajo mínimo</title>
<style>
    body { font-family: Arial, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; background:#f4f4f4; margin:0; padding:0; color:#222; }
    header { background:#d9534f; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .branch { font-size:14px; opacity:0.95; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #eee; text-align:left; vertical-align:middle; }
    th { background:#fafafa; color:#333; font-weight:600; }
    .actions { display:flex; gap:8px; align-items:center; }
    .btn { display:inline-block; padding:8px 12px; border-radius:6px; text-decoration:none; color:#fff; font-size:0.95rem; border:0; cursor:pointer; }
    .btn-request { background:#0d6efd; }
    .btn-back { background:#28a745; display:inline-flex; align-items:center; gap:8px; }
    .btn-view-requests { background:#6f42c1; display:inline-flex; align-items:center; gap:8px; }
    .input-qty { width:90px; padding:6px 8px; border-radius:6px; border:1px solid #ddd; }
    .note { margin-top:12px; color:#666; font-size:0.95rem; }
    .top-actions { display:flex; gap:10px; align-items:center; }
    .small-muted { color:#444; font-size:0.95rem; }
    .submit-row { margin-top:16px; display:flex; justify-content:flex-end; gap:10px; align-items:center; }
    @media (max-width:800px){
        .container { margin:12px; padding:14px; }
        table, thead, tbody, th, td, tr { display:block; }
        thead { display:none; }
        tr { margin-bottom:12px; border:1px solid #eee; border-radius:8px; padding:10px; background:#fafafa; }
        td { border:0; padding:6px 0; }
        td:before { font-weight:bold; display:block; color:#333; margin-bottom:6px; }
        td:nth-of-type(1):before { content: "ID"; }
        td:nth-of-type(2):before { content: "SKU"; }
        td:nth-of-type(3):before { content: "Producto"; }
        td:nth-of-type(4):before { content: "Stock actual"; }
        td:nth-of-type(5):before { content: "Mínimo"; }
        td:nth-of-type(6):before { content: "Solicitar (cantidad)"; }
    }
</style>
</head>
<body>

<header>
    <h2 style="margin:0; font-size:1.1rem;">Inventario bajo mínimo</h2>

    <div class="top-actions" style="margin-left:auto;">
        <!-- Botón para que el cajero vuelva al POS -->
        <a class="btn btn-back" href="/mi_tienda/admin/pos.php" title="Volver al POS" style="text-decoration:none; color:#fff; padding:8px 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16" aria-hidden="true">
              <path fill-rule="evenodd" d="M15 8a.5.5 0 0 1-.5.5H3.707l3.147 3.146a.5.5 0 0 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L3.707 7.5H14.5A.5.5 0 0 1 15 8z"/>
            </svg>
            Volver al POS
        </a>

        <!-- Botón para ir directamente a crear_pedido.php y ver el listado de solicitudes -->
        <a class="btn btn-view-requests" href="/mi_tienda/admin/crear_pedido.php" title="Ver solicitudes / Crear pedido" style="text-decoration:none; color:#fff; padding:8px 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16" aria-hidden="true">
              <path d="M2 2.5A1.5 1.5 0 0 1 3.5 1h9A1.5 1.5 0 0 1 14 2.5v11A1.5 1.5 0 0 1 12.5 15h-9A1.5 1.5 0 0 1 2 13.5v-11zM3.5 2a.5.5 0 0 0-.5.5V4h10V2.5a.5.5 0 0 0-.5-.5h-9z"/>
            </svg>
            Ver solicitudes / Crear pedido
        </a>

        <div class="branch" style="margin-left:12px;">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName, ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>
</header>

<div class="container">
    <?php if (!empty($_SESSION['solicitud_enviada'])): ?>
        <p>✅ <strong>Solicitud enviada.</strong> El listado de productos por debajo del mínimo está oculto porque ya se creó una solicitud. Puedes gestionarla en <a href="/mi_tienda/admin/crear_pedido.php">Crear / Ver solicitudes</a>.</p>
        <p class="note">Si deseas volver a ver el listado sin recibir la solicitud, elimina la bandera de sesión <code>$_SESSION['solicitud_enviada']</code> desde el flujo de recepción o manualmente en el código.</p>
    <?php else: ?>

        <p>Productos cuyo stock está por debajo del mínimo configurado. Indica la cantidad que consideras necesaria para reponer cada producto y presiona <strong>Enviar solicitud</strong>.</p>

        <form method="post" action="/mi_tienda/admin/crear_pedido.php" id="requestForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="branch_id" value="<?= (int)$currentBranchId ?>">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Stock actual</th>
                        <th>Mínimo</th>
                        <th>Solicitar (cantidad)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): ?>
                    <tr>
                        <td><?= (int)$i['id'] ?></td>
                        <td><?= htmlspecialchars($i['sku'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($i['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="color:#d9534f; font-weight:bold;"><?= (int)$i['stock'] ?></td>
                        <td><?= (int)$i['min_quantity'] ?></td>
                        <td>
                            <div class="actions">
                                <!-- input para cantidad solicitada -->
                                <input class="input-qty" type="number" min="0" name="qty[<?= (int)$i['id'] ?>]" value="<?= max(0, (int)$i['min_quantity'] - (int)$i['stock']) ?>" />
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="submit-row">
                <div class="small-muted" style="margin-right:auto;">Solo se enviarán los productos con cantidad mayor a 0.</div>
                <button type="submit" class="btn btn-request" style="background:#0d6efd; color:#fff; border:none; padding:10px 14px; border-radius:8px;">Enviar solicitud</button>
            </div>
        </form>

        <?php if (empty($items)): ?>
            <p>No hay productos por debajo del mínimo en esta sucursal.</p>
        <?php endif; ?>

        <p class="note">Nota: los cajeros y auxiliares pueden solicitar reposición usando las casillas de cantidad. Las solicitudes serán visibles para el personal autorizado en <code>admin/crear_pedido.php</code>.</p>

    <?php endif; ?>
</div>

</body>
</html>

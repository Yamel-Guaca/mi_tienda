<?php
// admin/inventario_bajo.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

/* CSRF token seguro */
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/*
  1. VERIFICACIÓN EN BASE DE DATOS POR SUCURSAL:
     Consultar si la sucursal actual ya tiene una solicitud o pedido de reposición activo/pendiente.
*/
$solicitudActivaSucursal = false;

try {
    // Buscar si existe un pedido pendiente para la sucursal en la tabla 'pedidos'
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM pedidos 
        WHERE branch_id = ? 
          AND status IN ('pendiente', 'solicitado', 'en_proceso', 'abierto', 'enviado')
    ");
    $stmtCheck->execute([$currentBranchId]);
    if ($stmtCheck->fetchColumn() > 0) {
        $solicitudActivaSucursal = true;
    }
} catch (Exception $e) {
    // Si la tabla principal se llama 'solicitudes'
    try {
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM solicitudes 
            WHERE branch_id = ? 
              AND status IN ('pendiente', 'solicitado', 'en_proceso', 'abierta', 'enviada')
        ");
        $stmtCheck->execute([$currentBranchId]);
        if ($stmtCheck->fetchColumn() > 0) {
            $solicitudActivaSucursal = true;
        }
    } catch (Exception $ex) {
        // Fallback a variable de sesión si no existe la tabla
        $solicitudActivaSucursal = !empty($_SESSION['solicitud_enviada']);
    }
}

/*
  2. Cargar productos bajo mínimo solo si NO hay una solicitud activa en la sucursal
*/
$items = [];
if (!$solicitudActivaSucursal && empty($_SESSION['solicitud_enviada'])) {
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.packaging_type,
            p.packaging_qty,
            p.has_inner_units,
            p.inner_unit_qty,
            COALESCE(pb.min_quantity, p.min_quantity, 0) AS min_quantity,
            IFNULL(i.quantity, 0) AS stock
        FROM products p
        LEFT JOIN inventory i 
            ON p.id = i.product_id 
           AND i.branch_id = ?
        LEFT JOIN product_branch_minimums pb
            ON pb.product_id = p.id
           AND pb.branch_id = ?
        WHERE p.active = 1
          AND COALESCE(pb.min_quantity, p.min_quantity, 0) > 0
          AND IFNULL(i.quantity, 0) < COALESCE(pb.min_quantity, p.min_quantity, 0)
        ORDER BY p.name ASC
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body { font-family: Arial, system-ui, -apple-system, sans-serif; background:#f4f6f8; margin:0; padding:0; color:#222; }
    header { background:#d9534f; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .branch { font-size:14px; opacity:0.95; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    table { width:100%; border-collapse:collapse; margin-top:15px; }
    th, td { padding:10px; border-bottom:1px solid #eee; text-align:left; vertical-align:middle; }
    th { background:#fafafa; color:#333; font-weight:600; font-size:14px; }
    .actions { display:flex; gap:8px; align-items:center; }
    .btn { display:inline-block; padding:8px 12px; border-radius:6px; text-decoration:none; color:#fff; font-size:0.9rem; border:0; cursor:pointer; font-weight:600; }
    .btn-request { background:#0d6efd; }
    .btn-request:hover { background:#0b5ed7; }
    .btn-back { background:#28a745; display:inline-flex; align-items:center; gap:6px; }
    .btn-back:hover { background:#218838; }
    .btn-view-requests { background:#6f42c1; display:inline-flex; align-items:center; gap:6px; }
    .btn-view-requests:hover { background:#5a32a3; }
    .input-qty { width:90px; padding:6px 8px; border-radius:6px; border:1px solid #cbd5e1; font-size:14px; }
    .note { margin-top:12px; color:#64748b; font-size:0.9rem; line-height:1.4; }
    .top-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .small-muted { color:#64748b; font-size:0.9rem; }
    .submit-row { margin-top:16px; display:flex; justify-content:flex-end; gap:10px; align-items:center; flex-wrap:wrap; }
    .pkg-info { font-size:11px; color:#666; margin-top:2px; display:block; }
    .alert-banner { background:#e7f5ff; border:1px solid #a5d8ff; padding:15px; border-radius:6px; color:#1864ab; margin-bottom:15px; }

    @media (max-width:800px){
        .container { margin:12px; padding:14px; }
        table, thead, tbody, th, td, tr { display:block; }
        thead { display:none; }
        tr { margin-bottom:12px; border:1px solid #e2e8f0; border-radius:8px; padding:10px; background:#fafafa; }
        td { border:0; padding:6px 0; }
        td:before { font-weight:bold; display:block; color:#333; margin-bottom:4px; font-size:12px; }
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
        <!-- Volver al POS -->
        <a class="btn btn-back" href="pos.php" title="Volver al POS">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16" aria-hidden="true">
              <path fill-rule="evenodd" d="M15 8a.5.5 0 0 1-.5.5H3.707l3.147 3.146a.5.5 0 0 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L3.707 7.5H14.5A.5.5 0 0 1 15 8z"/>
            </svg>
            Volver al POS
        </a>

        <!-- Ver solicitudes de reposición -->
        <a class="btn btn-view-requests" href="crear_pedido.php" title="Ver solicitudes / Crear pedido">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16" aria-hidden="true">
              <path d="M2 2.5A1.5 1.5 0 0 1 3.5 1h9A1.5 1.5 0 0 1 14 2.5v11A1.5 1.5 0 0 1 12.5 15h-9A1.5 1.5 0 0 1 2 13.5v-11zM3.5 2a.5.5 0 0 0-.5.5V4h10V2.5a.5.5 0 0 0-.5-.5h-9z"/>
            </svg>
            Ver solicitudes / Crear pedido
        </a>

        <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName, ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>
</header>

<div class="container">
    <?php if ($solicitudActivaSucursal || !empty($_SESSION['solicitud_enviada'])): ?>
        <div class="alert-banner">
            📋 <strong>Solicitud de reposición en curso.</strong> Ya existe un pedido de reposición enviado y activo para la sucursal <strong><?= htmlspecialchars($currentBranchName, ENT_QUOTES, 'UTF-8') ?></strong>.
        </div>
        <p class="note">El listado de productos por debajo del mínimo se mantendrá oculto para evitar solicitudes duplicadas hasta que el pedido pendiente sea despachado o gestionado desde <a href="crear_pedido.php">Ver solicitudes / Crear pedido</a>.</p>
    <?php else: ?>

        <p>Productos cuyo stock está por debajo del límite configurado. Indica la cantidad sugerida de reposición y presiona <strong>Enviar solicitud de reposición</strong>.</p>

        <?php if (!empty($items)): ?>
            <form method="post" action="crear_pedido.php" id="requestForm" novalidate>
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
                            <th>Solicitar (unidades)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i): 
                            $stock = (int)$i['stock'];
                            $minQty = (int)$i['min_quantity'];
                            $suggestedQty = max(0, $minQty - $stock);
                            $packQty = max(1, (int)($i['packaging_qty'] ?? 1));
                            $hasInner = !empty($i['has_inner_units']);
                            $innerQty = $hasInner ? max(1, (int)($i['inner_unit_qty'] ?? 1)) : 1;
                            $factor = $packQty * $innerQty;
                        ?>
                        <tr>
                            <td><?= (int)$i['id'] ?></td>
                            <td><?= htmlspecialchars($i['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <strong><?= htmlspecialchars($i['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($factor > 1): ?>
                                    <span class="pkg-info">Empaque: <?= htmlspecialchars($i['packaging_type'] ?? 'Empaque') ?> de <?= $factor ?> unid. mínimas</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#d9534f; font-weight:bold;"><?= $stock ?></td>
                            <td><?= $minQty ?></td>
                            <td>
                                <div class="actions">
                                    <input class="input-qty" type="number" min="0" name="qty[<?= (int)$i['id'] ?>]" value="<?= $suggestedQty ?>" />
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="submit-row">
                    <div class="small-muted" style="margin-right:auto;">Solo se procesarán los ítems con cantidad mayor a 0.</div>
                    <button type="submit" class="btn btn-request">Enviar solicitud de reposición</button>
                </div>
            </form>
        <?php else: ?>
            <p class="note" style="background:#e6ffed; border:1px solid #b7f0c6; padding:12px; border-radius:6px; color:#0a6;">
                🎉 <strong>¡Todo en orden!</strong> No hay productos por debajo del stock mínimo en esta sucursal.
            </p>
        <?php endif; ?>

        <p class="note">Nota: Las solicitudes serán procesadas por el personal autorizado desde el módulo <code>crear_pedido.php</code>.</p>

    <?php endif; ?>
</div>

</body>
</html>
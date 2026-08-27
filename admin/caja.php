<?php
// admin/caja.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1, 3]);

$pdo = DB::getConnection();

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;
$currentUserId     = $_SESSION['user']['id']  ?? null;
$currentUserRole   = $_SESSION['user']['role_id'] ?? null; // rol del usuario

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

$msg = "";
$diferencia = null;
$action = $_POST['action'] ?? null;


// --- Handler ack_report ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'ack_report')) {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId > 0) {
        try {
            $stmtAck = $pdo->prepare("
                UPDATE cash_sessions 
                SET report_acknowledged = 1, report_acknowledged_at = NOW() 
                WHERE id = ?
            ");
            $stmtAck->execute([$sessionId]);
        } catch (Exception $e) {
            // opcional: loguear error
        }
    }
    // Redirigir para evitar reenvío del formulario
    header("Location: caja.php");
    exit;
}
// --- fin handler ack_report ---


// --- Handler ack_print_order ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'ack_print_order')) {
    $orderId = intval($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        try {
            $stmtAckOrder = $pdo->prepare("
                UPDATE orders 
                SET print_acknowledged = 1, print_acknowledged_at = NOW() 
                WHERE id = ?
            ");
            $stmtAckOrder->execute([$orderId]);
        } catch (Exception $e) {
            // opcional: loguear error
        }
    }
    header("Location: caja.php");
    exit;
}
// --- fin handler ack_print_order ---


// Detectar si se pasó un lastOrderId
$lastOrderId = intval($_GET['last_order_id'] ?? $_POST['last_order_id'] ?? 0);

// Caja abierta actual del usuario
$stmt = $pdo->prepare("
    SELECT * FROM cash_sessions
    WHERE user_id=? AND branch_id=? AND status='abierta'
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$currentUserId, $currentBranchId]);
$currentSession = $stmt->fetch(PDO::FETCH_ASSOC);

// Preparar consulta para ventas por sesión (entre apertura y cierre)
$stmtVentasSesion = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND created_at BETWEEN ? AND ?
");

// Preparar consulta para pagos virtuales
$stmtVirtual = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount),0) 
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    WHERE p.method = 'virtual'
      AND p.status = 'completado'
      AND o.created_at BETWEEN ? AND ?
      AND o.branch_id = ?
");

// --- NUEVO: gastos por sesión ---
$stmtGastos = $pdo->prepare("
    SELECT COALESCE(SUM(valor),0)
    FROM gastos g
    WHERE g.branch_id = ?
      AND CONCAT(g.fecha, ' ', g.hora) BETWEEN ? AND ?
      AND g.anulado = 0
");

// Variables para reporte
$reportSession = null;
$reportVentas  = 0;
$reportVirtualPayments = 0;
$reportGastos = 0;












// Variables para mostrar reporte después de cerrar
$reportSession = null;
$reportVentas  = 0;
$reportVirtualPayments = 0;
$reportGastos = 0;

// --- Apertura ---
if ($action === 'open' && !$currentSession) {
    $opening = floatval(str_replace('.', '', $_POST['opening_amount'] ?? 0));
    if ($opening <= 0) {
        $msg = "El monto de apertura debe ser mayor a 0.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO cash_sessions (user_id, branch_id, opening_amount)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$currentUserId, $currentBranchId, $opening]);
        $msg = "Caja abierta correctamente.";
        header("Location: caja.php");
        exit;
    }
}

// --- Cierre ---
if ($action === 'close' && $currentSession) {
    // Tomar el monto de cierre enviado por el formulario
    $closing = floatval(str_replace('.', '', $_POST['closing_amount'] ?? 0));
    $diferencia = $closing - $currentSession['opening_amount'];

    // Actualizar la sesión en la base de datos
    $stmt = $pdo->prepare("
        UPDATE cash_sessions
        SET closing_amount=?, closed_at=NOW(), status='cerrada'
        WHERE id=?
    ");
    $stmt->execute([$closing, $currentSession['id']]);

    // Recuperar la sesión actualizada para el reporte
    $stmt2 = $pdo->prepare("
        SELECT c.*, u.name AS user_name 
        FROM cash_sessions c 
        JOIN users u ON u.id = c.user_id 
        WHERE c.id = ? LIMIT 1
    ");
    $stmt2->execute([$currentSession['id']]);
    $reportSession = $stmt2->fetch(PDO::FETCH_ASSOC);

    // Calcular ventas dentro del rango de la sesión (apertura -> cierre)
    $openedAt = $reportSession['opened_at'];
    $closedAt = $reportSession['closed_at'] ?: date('Y-m-d H:i:s');
    $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportVentas = (float)$stmtVentasSesion->fetchColumn();

    // Calcular pagos virtuales dentro del rango
    $stmtVirtual->execute([$openedAt, $closedAt, $currentBranchId]);
    $reportVirtualPayments = (float)$stmtVirtual->fetchColumn();

    // Calcular gastos dentro del rango
    $stmtGastos->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportGastos = (float)$stmtGastos->fetchColumn();

    $msg = "Caja cerrada correctamente. Generando reporte de cierre.";
    // No redirigimos para que el cajero vea el reporte
}

// --- Re-ejecutar consultas de resumen si no hubo cierre ---
$defaultOpenedAt = $currentSession['opened_at'] ?? date('Y-m-d H:i:s');
$defaultClosedAt = $currentSession['closed_at'] ?? date('Y-m-d H:i:s');

// Pagos virtuales
$stmtVirtual->execute([$defaultOpenedAt, $defaultClosedAt, $currentBranchId]);
if (empty($reportVirtualPayments)) {
    $reportVirtualPayments = (float)$stmtVirtual->fetchColumn();
}

// Gastos
$stmtGastos->execute([$currentBranchId, $defaultOpenedAt, $defaultClosedAt]);
if (empty($reportGastos)) {
    $reportGastos = (float)$stmtGastos->fetchColumn();
}

// Ventas del día de esa sucursal
$stmt = $pdo->prepare("
    SELECT SUM(total) 
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND DATE(created_at) = CURDATE()
");
$stmt->execute([$currentBranchId]);
$todaySales = $stmt->fetchColumn() ?: 0;

// Últimas sesiones de caja
$stmt = $pdo->prepare("
    SELECT c.*, u.name AS user_name
    FROM cash_sessions c
    JOIN users u ON u.id = c.user_id
    WHERE c.branch_id = ?
    ORDER BY c.id DESC
    LIMIT 20
");
$stmt->execute([$currentBranchId]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Caja - Arqueo</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; position:relative; }
    th, td { padding:10px; border-bottom:1px solid #ddd; text-align:center; position:relative; }
    th { background:#eee; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
    input { padding:6px; width:100%; box-sizing:border-box; }
    button { padding:10px 15px; margin-top:10px; width:100%; }
    .report { background:#fff; padding:16px; border:1px solid #ccc; margin-top:20px; border-radius:6px; }
    .report h4 { margin:0 0 10px 0; }
    .report .row { display:flex; justify-content:space-between; margin-bottom:6px; }
    .report .bold { font-weight:bold; }












    /* Alternar "sombra" de color (azul/rojo) debajo de cada fila */
table tbody tr { position:relative; }
table tbody tr::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 6px;
    pointer-events: none;
    border-radius: 0 0 4px 4px;
}
table tbody tr:nth-child(odd)::after {
    background: rgba(0, 123, 255, 0.06); /* azul suave */
}
table tbody tr:nth-child(even)::after {
    background: rgba(220, 53, 69, 0.06); /* rojo suave */
}

/* Ocultar columna ID en la vista (no mostrar al usuario) */
.id-col { display:none; }

@media (max-width:768px) {
    header { flex-direction:column; align-items:flex-start; }
    .table { display:block; overflow-x:auto; white-space:nowrap; }
    table { min-width:700px; }
    .report .row { flex-direction:column; align-items:flex-start; }
}
</style>
</head>
<body>

<header>
    <h2>Caja / Arqueo</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
    <a href="/mi_tienda/admin/pos.php?mode=tactil">Atrás</a>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- BLOQUE NUEVO: mostrar opciones de impresión tras guardar una venta -->
<?php if (!empty($lastOrderId)): ?>
    <div class="report" id="report-venta">
        <h4>Venta #<?= htmlspecialchars($lastOrderId) ?> registrada</h4>
        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>','_blank','noopener')">Vista impresión</button>
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>&width=58','_blank','noopener')">Imprimir 58mm</button>
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>&width=80','_blank','noopener')">Imprimir 80mm</button>

            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="ack_print_order">
                <input type="hidden" name="order_id" value="<?= htmlspecialchars($lastOrderId) ?>">
                <button type="submit">No imprimir</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<!-- FIN BLOQUE NUEVO -->

<h3>Arqueo de Caja</h3>
<p>Sucursal actual: <?= htmlspecialchars($currentBranchName) ?></p>

<!-- Denominaciones y inputs -->
<table class="table">
    <thead>
        <tr>
            <th>Denominación</th>
            <th>Unidades</th>
            <th>Subtotal</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $denominaciones = [50,100,200,500,1000,2000,5000,10000,20000,50000,100000];
        foreach ($denominaciones as $den): ?>
        <tr>
            <td>$<?= number_format($den,0,",",".") ?></td>
            <td>
                <input type="text" 
                       name="den[<?= $den ?>]" 
                       value="0" 
                       class="den-input" 
                       data-valor="<?= $den ?>">
            </td>
            <td class="subtotal">$0</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top:15px;">
    <strong>Total en caja: $<span id="total-caja">0</span></strong>
</div>

<script>
// Formatear con puntos de mil
function formatMiles(num) {
    return num.toLocaleString('es-CO');
}

// parseMiles elimina cualquier carácter no numérico
function parseMiles(str) {
    if (!str) return 0;
    const digits = String(str).replace(/[^\d]/g, '');
    return parseInt(digits) || 0;
}

// Actualizar subtotales y total del arqueo
function actualizarArqueo() {
    let total = 0;
    document.querySelectorAll(".den-input").forEach(input => {
        let raw = (input.value || '').replace(/\./g,'');
        let cantidad = parseInt(raw) || 0;
        let valor = parseInt(input.dataset.valor) || 0;
        let subtotal = valor * cantidad;
        const td = input.closest("tr").querySelector(".subtotal");
        if (td) td.textContent = "$" + formatMiles(subtotal);
        total += subtotal;
    });
    const totalSpan = document.getElementById("total-caja");
    if (totalSpan) totalSpan.textContent = formatMiles(total);

    // Copiar el total al campo de apertura o cierre con formato
    let apertura = document.getElementById("opening_amount");
    let cierre   = document.getElementById("closing_amount");
    if (apertura) apertura.value = formatMiles(total);
    if (cierre) {
        cierre.value = formatMiles(total);
        cierre.dispatchEvent(new Event('input', { bubbles: true }));
    }
}







    // Recalcular diferencia (UI del panel en cierre)
function recalcularDiferencia() {
    const diffBox = document.getElementById("diff-box");
    if (!diffBox) return;

    const aperturaTexto = document.getElementById("apertura_ref")?.textContent || "0";
    const apertura = parseMiles(aperturaTexto);
    const cierreTexto = document.getElementById("closing_amount")?.value || "0";
    const cierre = parseMiles(cierreTexto);
    const diferencia = cierre - apertura;

    const elA = document.getElementById("diff-apertura");
    const elC = document.getElementById("diff-cierre");
    const elV = document.getElementById("diff-valor");

    if (elA) elA.textContent = "$" + formatMiles(apertura);
    if (elC) elC.textContent = "$" + formatMiles(cierre);
    if (elV) elV.textContent = (diferencia >= 0 ? "+" : "-") + "$" + formatMiles(Math.abs(diferencia));

    const badge = document.getElementById("diff-badge");
    if (badge) {
        badge.textContent = (diferencia === 0) ? "CUADRE" : (diferencia > 0 ? "SOBRANTE" : "FALTANTE");
        badge.style.background = (diferencia === 0) ? "#0a6" : (diferencia > 0 ? "#1976d2" : "#d32f2f");
        badge.style.color = "#fff";
        badge.style.padding = "4px 8px";
        badge.style.borderRadius = "6px";
        badge.style.fontWeight = "bold";
    }
}

// --- Asignación de listeners dentro de DOMContentLoaded ---
document.addEventListener("DOMContentLoaded", () => {
    // Escuchar cambios y formatear inputs de denominaciones
    document.querySelectorAll(".den-input").forEach(input => {
        input.addEventListener("input", function(e) {
            let raw = (e.target.value || '').replace(/\./g,'');
            e.target.value = raw ? formatMiles(parseInt(raw)) : "0";
            actualizarArqueo();
        });
    });

    // Escuchar cambios en apertura/cierre para recalcular diferencia
    ["opening_amount","closing_amount"].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener("input", (e) => {
                const raw = (e.target.value || '').replace(/\./g,'');
                e.target.value = raw ? formatMiles(parseInt(raw)) : "0";
                recalcularDiferencia();
            });
        }
    });

    // Inicializar al cargar
    actualizarArqueo();
    recalcularDiferencia();
});
</script>

<div style="margin-top:18px;"></div>

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<h3>Estado actual</h3>

<?php if ($currentUserRole == 1): ?> 
    <!-- Solo visible para administrador -->
    <p><strong>Ventas de hoy:</strong> $<?= number_format($todaySales, 0, ",", ".") ?></p>
<?php endif; ?>

<?php if ($currentSession): ?>
    <p><strong>Caja abierta por ti:</strong> 
        Apertura: <span id="apertura_ref">$<?= number_format($currentSession['opening_amount'],0,",",".") ?></span>
        el <?= $currentSession['opened_at'] ?>
    </p>

    <!-- Formulario de Cierre -->
    <form method="POST">
        <input type="hidden" name="action" value="close">
        <label>Monto de cierre:</label>
        <input type="text" name="closing_amount" id="closing_amount" required>
        <button type="submit">Cerrar caja</button>
    </form>

    <!-- Panel de diferencia/descudre -->
    <div id="diff-box" style="margin-top:15px; padding:12px; border:1px solid #ddd; border-radius:8px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <strong>Diferencia / Descudre (cierre - apertura)</strong>
            <span id="diff-badge"></span>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
            <div>
                <div style="font-weight:bold;">Apertura registrada</div>
                <div id="diff-apertura">$0</div>
            </div>
            <div>
                <div style="font-weight:bold;">Cierre contado</div>
                <div id="diff-cierre">$0</div>
            </div>
            <div>
                <div style="font-weight:bold;">Diferencia</div>
                <div id="diff-valor">$0</div>
            </div>
        </div>
        <small style="display:block; margin-top:8px; color:#666;">Para descuadre considerando ventas de la sesión, ver columna “Diferencia” en el historial.</small>
    </div>

    <?php
    if ($reportSession):
        $rep = $reportSession;
        $valor_retirar = floatval($rep['closing_amount']) - floatval($rep['opening_amount']);

        // Asegurar tipos numéricos
        $reportVentas = (float) $reportVentas;
        $reportVirtualPayments = (float) $reportVirtualPayments;
        $reportGastos = (float) $reportGastos;

        // Diferencia: incluir gastos y pagos virtuales
        $repDiff = (float)$rep['closing_amount']
                 - ( (float)$rep['opening_amount'] + $reportVentas + $reportGastos )
                 + $reportVirtualPayments;
    ?>
<div class="report" id="report-cierre">
    <h4>Reporte de Cierre - Caja #<?= htmlspecialchars($rep['id']) ?></h4>

    <div class="row"><div class="bold">Usuario:</div><div><?= htmlspecialchars($rep['user_name']) ?></div></div>
    <div class="row"><div class="bold">Sucursal:</div><div><?= htmlspecialchars($currentBranchName) ?></div></div>
    <div class="row"><div class="bold">Apertura registrada:</div><div>$<?= number_format($rep['opening_amount'],0,",",".") ?> (<?= $rep['opened_at'] ?>)</div></div>
    <div class="row"><div class="bold">Cierre contado:</div><div>$<?= number_format($rep['closing_amount'],0,",",".") ?> (<?= $rep['closed_at'] ?>)</div></div>

    <!-- Mostrar Valor a retirar -->
    <div class="row"><div class="bold">Valor a retirar:</div>
        <div><?= ($valor_retirar >= 0 ? '' : '-') . '$' . number_format(abs($valor_retirar),0,",",".") ?></div>
    </div>

    <div class="row"><div class="bold">Ventas en la sesión:</div><div>$<?= number_format($reportVentas,0,",",".") ?></div></div>
    <div class="row"><div class="bold">Ventas virtuales en la sesión:</div><div>$<?= number_format($reportVirtualPayments,0,",",".") ?></div></div>
    <div class="row"><div class="bold">Gastos en la sesión:</div><div>$<?= number_format($reportGastos,0,",",".") ?></div></div>

    <div class="row"><div class="bold">Diferencia (cierre - (apertura + ventas + gastos) + virtuales):</div>
        <div><?= ($repDiff >= 0 ? '+' : '-') . '$' . number_format(abs($repDiff),0,",",".") ?></div>
    </div>

    <div style="margin-top:8px;">
        <strong>Notas:</strong>
        <ul>
            <li>El monto de cierre es el valor contado por el cajero al momento de cerrar.</li>
            <li>La diferencia considera las ventas y gastos registrados en el periodo de la sesión.</li>
            <li>Los pagos virtuales se suman aparte si no están incluidos en las ventas.</li>
        </ul>
    </div>

    <div style="margin-top:12px; display:flex; gap:8px;">
        <!-- Botón para imprimir -->
        <button onclick="window.open('<?= htmlspecialchars('invoice_print.php?session_id='.$rep['id'].'&width=58') ?>','_blank','noopener')">Imprimir</button>

        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="ack_report">
            <input type="hidden" name="session_id" value="<?= htmlspecialchars($rep['id']) ?>">
            <button type="submit">Confirmar reporte</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
    <p>No tienes una caja abierta actualmente.</p>
    <!-- Formulario de Apertura -->
    <form method="POST">
        <input type="hidden" name="action" value="open">
        <label>Monto de apertura:</label>
        <input type="text" name="opening_amount" id="opening_amount" required>
        <button type="submit">Abrir caja</button>
    </form>
<?php endif; ?>

<?php
// Mostrar "Últimas cajas" solo a administradores (role_id == 1).
if ($currentUserRole == 1):
?>
    <h3>Últimas cajas de esta sucursal</h3>
    <table class="table">
        <thead>
            <tr>
                <th class="id-col">ID</th>
                <th>Usuario</th>
                <th>Apertura</th>
                <th>Cierre</th>
                <th>Estado</th>
                <th>Abierta</th>
                <th>Cerrada</th>
                <th>Ventas</th>
                <th>Gastos</th>
                <th>Diferencia</th>
                <th>Valor a Retirar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
            <?php
                $openedAt = $s['opened_at'];
                $closedAt = $s['closed_at'] ?: date('Y-m-d H:i:s');

                // Ventas en la sesión
                $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
                $ventasSesion = (float)$stmtVentasSesion->fetchColumn();

                // Pagos virtuales en la sesión (solo para mostrar)
                $stmtVirtual->execute([$openedAt, $closedAt, $currentBranchId]);
                $virtualSesion = (float)$stmtVirtual->fetchColumn();

                // Gastos en la sesión
                $stmtGastos->execute([$currentBranchId, $openedAt, $closedAt]);
                $gastosSesion = (float)$stmtGastos->fetchColumn();

                // Calcular diferencia incluyendo gastos
                $diff = null;
                if ($s['closing_amount'] !== null) {
                    $diff = $s['closing_amount'] - ($s['opening_amount'] + $ventasSesion + $gastosSesion);
                }
                $diffTexto = ($diff === null) ? '-' : (($diff >= 0 ? '+' : '-') . '$' . number_format(abs($diff), 0, ",", "."));

                // Valor a retirar por sesión
                $withdrawSesion = null;
                if ($s['closing_amount'] !== null) {
                    $withdrawSesion = floatval($s['closing_amount']) - floatval($s['opening_amount']);
                }
                $withdrawTexto = ($withdrawSesion === null) ? '-' : ('$' . number_format($withdrawSesion, 0, ",", "."));
            ?>
            <tr>
                <td class="id-col"><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['user_name']) ?></td>
                <td>$<?= number_format($s['opening_amount'], 0, ",", ".") ?></td>
                <td><?= $s['closing_amount'] !== null ? '$'.number_format($s['closing_amount'], 0, ",", ".") : '-' ?></td>
                <td><?= htmlspecialchars($s['status']) ?></td>
                <td><?= $s['opened_at'] ?></td>
                <td><?= $s['closed_at'] ?: '-' ?></td>
                <td><?= '$' . number_format($ventasSesion, 0, ",", ".") ?></td>
                <td><?= '$' . number_format($gastosSesion, 0, ",", ".") ?></td>
                <td><?= $diffTexto ?></td>
                <td><?= $withdrawTexto ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php
endif; // fin condicional de rol
?>
</div>
</body>
</html>

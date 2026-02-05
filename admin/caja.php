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

// Ajuste de hora local
date_default_timezone_set('America/Bogota');

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

// --- Handler mínimo para ack_report ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'ack_report')) {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId > 0) {
        try {
            $fechaLocal = date('Y-m-d H:i:s'); // Hora Bogotá
            $stmtAck = $pdo->prepare("UPDATE cash_sessions SET report_acknowledged = 1, report_acknowledged_at = ? WHERE id = ?");
            $stmtAck->execute([$fechaLocal, $sessionId]);
        } catch (Exception $e) {
            // No interrumpir la UX si falla; opcionalmente loguear el error en tu sistema de logs.
        }
    }
    header("Location: caja.php");
    exit;
}
// --- fin handler ack_report ---

// --- Handler mínimo para ack_print_order ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'ack_print_order')) {
    $orderId = intval($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        try {
            $fechaLocal = date('Y-m-d H:i:s'); // Hora Bogotá
            $stmtAckOrder = $pdo->prepare("UPDATE orders SET print_acknowledged = 1, print_acknowledged_at = ? WHERE id = ?");
            $stmtAckOrder->execute([$fechaLocal, $orderId]);
        } catch (Exception $e) {
            // No interrumpir la UX si falla; opcionalmente loguear
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

// Preparar consulta para ventas por sesión
$stmtVentasSesion = $pdo->prepare("
    SELECT SUM(total)
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND created_at BETWEEN ? AND ?
");

$reportSession = null;
$reportVentas  = 0;

// Apertura
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

// Cierre
if ($action === 'close' && $currentSession) {
    $closing = floatval(str_replace('.', '', $_POST['closing_amount'] ?? 0));
    $diferencia = $closing - $currentSession['opening_amount'];

    $fechaLocal = date('Y-m-d H:i:s'); // Hora Bogotá
    $stmt = $pdo->prepare("
        UPDATE cash_sessions
        SET closing_amount=?, closed_at=?, status='cerrada'
        WHERE id=?
    ");
    $stmt->execute([$closing, $fechaLocal, $currentSession['id']]);

    $stmt2 = $pdo->prepare("SELECT c.*, u.name AS user_name FROM cash_sessions c JOIN users u ON u.id = c.user_id WHERE c.id = ? LIMIT 1");
    $stmt2->execute([$currentSession['id']]);
    $reportSession = $stmt2->fetch(PDO::FETCH_ASSOC);

    $openedAt = $reportSession['opened_at'];
    $closedAt = $reportSession['closed_at'] ?: date('Y-m-d H:i:s');
    $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportVentas = $stmtVentasSesion->fetchColumn() ?: 0;

    $msg = "Caja cerrada correctamente. Generando reporte de cierre.";
}

// Ventas del día
$stmt = $pdo->prepare("
    SELECT SUM(total) 
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND DATE(created_at) = CURDATE()
");
$stmt->execute([$currentBranchId]);
$todaySales = $stmt->fetchColumn() ?: 0;

// Últimas sesiones
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
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; text-align:center; }
    th { background:#eee; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
    input { padding:6px; width:100%; box-sizing:border-box; }
    button { padding:10px 15px; margin-top:10px; width:100%; }
    .report { background:#fff; padding:16px; border:1px solid #ccc; margin-top:20px; border-radius:6px; }
    .report h4 { margin:0 0 10px 0; }
    .report .row { display:flex; justify-content:space-between; margin-bottom:6px; }
    .report .bold { font-weight:bold; }
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
    <a href="/mi_tienda/admin/pos.php?mode=tactil">Atras</a>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- BLOQUE NUEVO: mostrar opciones de impresión tras guardar una venta (mínimo cambio) -->
<?php if (!empty($lastOrderId)): ?>
    <div class="report" id="report-venta">
        <h4>Venta #<?= htmlspecialchars($lastOrderId) ?> registrada</h4>
        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <!-- Ajusta la ruta si invoice_print.php está en otra carpeta (ej: admin/invoice_print.php) -->
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>','_blank','noopener')">Vista impresión (seleccionar ancho)</button>
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

// parseMiles ahora elimina cualquier carácter no numérico (incluye $ y puntos)
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

    // Copiar el total al campo de apertura o cierre con formato (si existen)
    let apertura = document.getElementById("opening_amount");
    let cierre   = document.getElementById("closing_amount");
    if (apertura) apertura.value = formatMiles(total);
    if (cierre) {
        cierre.value = formatMiles(total);
        // forzar evento input para que se recalculen diferencias
        cierre.dispatchEvent(new Event('input', { bubbles: true }));
    }

    recalcularDiferencia();
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

// --- MOVIMOS la asignación de listeners dentro de DOMContentLoaded ---
document.addEventListener("DOMContentLoaded", () => {

    // Escuchar cambios y formatear inputs de denominaciones
    document.querySelectorAll(".den-input").forEach(input => {
        input.addEventListener("input", function(e) {
            let raw = (e.target.value || '').replace(/\./g,'');
            if(raw) {
                e.target.value = formatMiles(parseInt(raw));
            } else {
                e.target.value = "0";
            }
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

<div class="container">

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

    <!-- Panel de diferencia/descudre (visible en cierre) -->
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
    // Si acabamos de cerrar y tenemos datos para el reporte, mostrarlo aquí
    if ($reportSession):
        $rep = $reportSession;
        $repDiff = $rep['closing_amount'] - ($rep['opening_amount'] + $reportVentas);
    ?>
    <div class="report" id="report-cierre">
        <h4>Reporte de Cierre - Caja #<?= htmlspecialchars($rep['id']) ?></h4>

        <div class="row"><div class="bold">Usuario:</div><div><?= htmlspecialchars($rep['user_name']) ?></div></div>
        <div class="row"><div class="bold">Sucursal:</div><div><?= htmlspecialchars($currentBranchName) ?></div></div>
        <div class="row"><div class="bold">Apertura registrada:</div><div>$<?= number_format($rep['opening_amount'],0,",",".") ?> (<?= $rep['opened_at'] ?>)</div></div>
        <div class="row"><div class="bold">Cierre contado:</div><div>$<?= number_format($rep['closing_amount'],0,",",".") ?> (<?= $rep['closed_at'] ?>)</div></div>
        <div class="row"><div class="bold">Ventas en la sesión:</div><div>$<?= number_format($reportVentas,0,",",".") ?></div></div>
        <div class="row"><div class="bold">Diferencia (cierre - (apertura + ventas)):</div><div><?= ($repDiff >= 0 ? '+' : '-') . '$' . number_format(abs($repDiff),0,",",".") ?></div></div>

        <hr>

        <div style="margin-top:8px;">
            <strong>Notas:</strong>
            <ul>
                <li>El monto de cierre es el valor contado por el cajero al momento de cerrar.</li>
                <li>La diferencia considera las ventas registradas en el periodo de la sesión.</li>
            </ul>
        </div>

        <div style="margin-top:12px; display:flex; gap:8px;">
            <!-- Botones para abrir la vista de impresión adaptable (invoice_print.php) -->
            <button onclick="window.open('<?= htmlspecialchars('invoice_print.php?session_id='.$rep['id']) ?>','_blank','noopener')">Vista impresión (seleccionar ancho)</button>
            <button onclick="window.open('<?= htmlspecialchars('invoice_print.php?session_id='.$rep['id'].'&width=58') ?>','_blank','noopener')">Imprimir 58mm</button>
            <button onclick="window.open('<?= htmlspecialchars('invoice_print.php?session_id='.$rep['id'].'&width=80') ?>','_blank','noopener')">Imprimir 80mm</button>

            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="ack_report">
                <input type="hidden" name="session_id" value="<?= htmlspecialchars($rep['id']) ?>">
                <button type="submit">Aceptar (No imprimir)</button>
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
                <th>ID</th>
                <th>Usuario</th>
                <th>Apertura</th>
                <th>Cierre</th>
                <th>Estado</th>
                <th>Abierta</th>
                <th>Cerrada</th>
                <th>Diferencia</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
            <?php
                $openedAt = $s['opened_at'];
                $closedAt = $s['closed_at'] ?: date('Y-m-d H:i:s');
                $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
                $ventasSesion = $stmtVentasSesion->fetchColumn() ?: 0;

                $diff = null;
                if ($s['closing_amount'] !== null) {
                    $diff = $s['closing_amount'] - ($s['opening_amount'] + $ventasSesion);
                }
                $diffTexto = ($diff === null) ? '-' : (($diff >= 0 ? '+' : '-') . '$' . number_format(abs($diff), 0, ",", "."));
            ?>
            <tr>
                <td><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['user_name']) ?></td>
                <td>$<?= number_format($s['opening_amount'], 0, ",", ".") ?></td>
                <td><?= $s['closing_amount'] !== null ? '$'.number_format($s['closing_amount'], 0, ",", ".") : '-' ?></td>
                <td><?= htmlspecialchars($s['status']) ?></td>
                <td><?= $s['opened_at'] ?></td>
                <td><?= $s['closed_at'] ?: '-' ?></td>
                <td><?= $diffTexto ?></td>
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

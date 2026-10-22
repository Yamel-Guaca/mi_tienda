<?php
// admin/caja.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1, 3]);

$pdo = DB::getConnection();

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;
$currentUserId     = $_SESSION['user']['id']  ?? $_SESSION['user_id'] ?? null;
$currentUserRole   = $_SESSION['user']['role_id'] ?? $_SESSION['role_id'] ?? null;

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
            // Log opcional
        }
    }
    header("Location: caja.php");
    exit;
}

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
            // Log opcional
        }
    }
    header("Location: caja.php");
    exit;
}

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

// Preparar consulta para ventas por sesión (excluye canceladas)
$stmtVentasSesion = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM orders
    WHERE branch_id = ?
      AND status != 'cancelado'
      AND created_at BETWEEN ? AND ?
");

// Preparar consulta para pagos virtuales (excluye canceladas)
$stmtVirtual = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount),0) 
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    WHERE p.method = 'virtual'
      AND p.status = 'completado'
      AND o.status != 'cancelado'
      AND o.created_at BETWEEN ? AND ?
      AND o.branch_id = ?
");

// Gastos por sesión
$stmtGastos = $pdo->prepare("
    SELECT COALESCE(SUM(valor),0)
    FROM gastos g
    WHERE g.branch_id = ?
      AND CONCAT(g.fecha, ' ', g.hora) BETWEEN ? AND ?
      AND COALESCE(g.anulado, 0) = 0
");

// Compras por sesión (Sincronización con compras.php)
$stmtCompras = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount),0)
    FROM purchases
    WHERE branch_id = ?
      AND created_at BETWEEN ? AND ?
");

// Variables para reporte
$reportSession = null;
$reportVentas  = 0;
$reportVirtualPayments = 0;
$reportGastos = 0;
$reportCompras = 0;

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
    $closing = floatval(str_replace('.', '', $_POST['closing_amount'] ?? 0));
    $diferencia = $closing - (float)$currentSession['opening_amount'];

    $stmt = $pdo->prepare("
        UPDATE cash_sessions
        SET closing_amount=?, closed_at=NOW(), status='cerrada'
        WHERE id=?
    ");
    $stmt->execute([$closing, $currentSession['id']]);

    $stmt2 = $pdo->prepare("
        SELECT c.*, u.name AS user_name 
        FROM cash_sessions c 
        JOIN users u ON u.id = c.user_id 
        WHERE c.id = ? LIMIT 1
    ");
    $stmt2->execute([$currentSession['id']]);
    $reportSession = $stmt2->fetch(PDO::FETCH_ASSOC);

    $openedAt = $reportSession['opened_at'];
    $closedAt = $reportSession['closed_at'] ?: date('Y-m-d H:i:s');

    $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportVentas = (float)$stmtVentasSesion->fetchColumn();

    $stmtVirtual->execute([$openedAt, $closedAt, $currentBranchId]);
    $reportVirtualPayments = (float)$stmtVirtual->fetchColumn();

    $stmtGastos->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportGastos = (float)$stmtGastos->fetchColumn();

    $stmtCompras->execute([$currentBranchId, $openedAt, $closedAt]);
    $reportCompras = (float)$stmtCompras->fetchColumn();

    $msg = "Caja cerrada correctamente. Generando reporte de cierre.";
}

// --- Re-ejecutar consultas de resumen si no hubo cierre ---
$defaultOpenedAt = $currentSession['opened_at'] ?? date('Y-m-d H:i:s');
$defaultClosedAt = $currentSession['closed_at'] ?? date('Y-m-d H:i:s');

$stmtVirtual->execute([$defaultOpenedAt, $defaultClosedAt, $currentBranchId]);
if (empty($reportVirtualPayments)) {
    $reportVirtualPayments = (float)$stmtVirtual->fetchColumn();
}

$stmtGastos->execute([$currentBranchId, $defaultOpenedAt, $defaultClosedAt]);
if (empty($reportGastos)) {
    $reportGastos = (float)$stmtGastos->fetchColumn();
}

$stmtCompras->execute([$currentBranchId, $defaultOpenedAt, $defaultClosedAt]);
if (empty($reportCompras)) {
    $reportCompras = (float)$stmtCompras->fetchColumn();
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
    button { padding:10px 15px; margin-top:10px; width:100%; cursor:pointer; }
    .report { background:#fff; padding:16px; border:1px solid #ccc; margin-top:20px; border-radius:6px; }
    .report h4 { margin:0 0 10px 0; }
    .report .row { display:flex; justify-content:space-between; margin-bottom:6px; }

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
        background: rgba(0, 123, 255, 0.06);
    }
    table tbody tr:nth-child(even)::after {
        background: rgba(220, 53, 69, 0.06);
    }

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
    <a href="pos.php?mode=tactil" style="color:#fff;">Atrás</a>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if (!empty($lastOrderId)): ?>
    <div class="report" id="report-venta">
        <h4>Venta #<?= htmlspecialchars((string)$lastOrderId) ?> registrada</h4>
        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>','_blank','noopener')">Vista impresión</button>
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>&width=58','_blank','noopener')">Imprimir 58mm</button>
            <button onclick="window.open('invoice_print.php?order_id=<?= $lastOrderId ?>&width=80','_blank','noopener')">Imprimir 80mm</button>

            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="ack_print_order">
                <input type="hidden" name="order_id" value="<?= htmlspecialchars((string)$lastOrderId) ?>">
                <button type="submit">No imprimir</button>
            </form>
        </div>
    </div>
<?php endif; ?>

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
function formatMiles(num) {
    return num.toLocaleString('es-CO');
}

function parseMiles(str) {
    if (!str) return 0;
    const digits = String(str).replace(/[^\d]/g, '');
    return parseInt(digits) || 0;
}

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

    let apertura = document.getElementById("opening_amount");
    let cierre   = document.getElementById("closing_amount");
    if (apertura) apertura.value = formatMiles(total);
    if (cierre) {
        cierre.value = formatMiles(total);
        cierre.dispatchEvent(new Event('input', { bubbles: true }));
    }
}

function recalcularDiferencia() {
    const diffBox = document.getElementById("diff-box");
    if (!diffBox) return;

    const aperturaTexto = document.getElementById("apertura_ref")?.textContent || "0";
    const apertura = parseMiles(aperturaTexto);
    const cierreTexto = document.getElementById("closing_amount")?.value || "0";
    const cierre = parseMiles(cierreTexto);

    const valorRetirar = cierre - apertura;

    const ventasTexto = document.getElementById("ventas_sesion")?.textContent || "0";
    const ventas = parseMiles(ventasTexto);

    const virtualTexto = document.getElementById("ventas_virtuales")?.textContent || "0";
    const virtuales = parseMiles(virtualTexto);

    const gastosTexto = document.getElementById("gastos_sesion")?.textContent || "0";
    const gastos = parseMiles(gastosTexto);

    const comprasTexto = document.getElementById("compras_sesion")?.textContent || "0";
    const compras = parseMiles(comprasTexto);

    const diferencia = valorRetirar + virtuales + gastos + compras - ventas;

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

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".den-input").forEach(input => {
        input.addEventListener("input", function(e) {
            let raw = (e.target.value || '').replace(/\./g,'');
            e.target.value = raw ? formatMiles(parseInt(raw)) : "0";
            actualizarArqueo();
        });
    });

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

    actualizarArqueo();
    recalcularDiferencia();
});
</script>

<div style="margin-top:18px;"></div>

<h3>Estado actual</h3>

<?php if ($currentUserRole == 1): ?> 
    <p><strong>Ventas de hoy:</strong> $<?= number_format((float)$todaySales, 0, ",", ".") ?></p>
<?php endif; ?>

<?php if ($currentSession): ?>
    <p><strong>Caja abierta por ti:</strong> 
        Apertura: <span id="apertura_ref">$<?= number_format((float)$currentSession['opening_amount'],0,",",".") ?></span>
        el <?= htmlspecialchars($currentSession['opened_at']) ?>
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
            <strong>Diferencia / Descuadre (cierre - apertura)</strong>
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

    $valor_retirar = (float)$rep['closing_amount'] - (float)$rep['opening_amount'];

    $reportVentas = (float)$reportVentas;
    $reportVirtualPayments = (float)$reportVirtualPayments;
    $reportGastos = (float)$reportGastos;
    $reportCompras = (float)$reportCompras;

    $difference = $valor_retirar + $reportVirtualPayments + $reportGastos + $reportCompras - $reportVentas;
?>
<style>
    .excel-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        font-family: Arial, sans-serif;
        font-size: 14px;
    }
    .excel-table th, .excel-table td {
        border: 1px solid #999;
        padding: 8px;
        text-align: left;
    }
    .excel-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .excel-table tr:nth-child(even) {
        background-color: #fafafa;
    }
</style>

<div class="report" id="report-cierre">
    <h4>Reporte de Cierre - Caja #<?= htmlspecialchars((string)$rep['id']) ?></h4>

    <table class="excel-table">
        <tr><th>Usuario</th><td><?= htmlspecialchars($rep['user_name']) ?></td></tr>
        <tr><th>Sucursal</th><td><?= htmlspecialchars($currentBranchName) ?></td></tr>
        <tr><th>Apertura registrada</th><td>$<?= number_format((float)$rep['opening_amount'],0,",",".") ?> (<?= htmlspecialchars($rep['opened_at']) ?>)</td></tr>
        <tr><th>Cierre contado</th><td>$<?= number_format((float)$rep['closing_amount'],0,",",".") ?> (<?= htmlspecialchars($rep['closed_at']) ?>)</td></tr>
        <tr><th>Valor a retirar</th><td>$<?= number_format($valor_retirar,0,",",".") ?></td></tr>
        <tr><th>Ventas en la sesión</th><td>$<?= number_format($reportVentas,0,",",".") ?></td></tr>
        <tr><th>Ventas virtuales en la sesión</th><td>$<?= number_format($reportVirtualPayments,0,",",".") ?></td></tr>
        <tr><th>Gastos en la sesión</th><td>$<?= number_format($reportGastos,0,",",".") ?></td></tr>
        <tr><th>Compras en la sesión</th><td>$<?= number_format($reportCompras,0,",",".") ?></td></tr>
        <tr><th>Diferencia (efectivo + virtuales + gastos + compras - ventas)</th>
            <td><?= ($difference >= 0 ? '+' : '-') . '$' . number_format(abs($difference),0,",",".") ?></td></tr>
    </table>

    <div style="display:none;">
        <span id="ventas_sesion"><?= number_format($reportVentas,0,",",".") ?></span>
        <span id="ventas_virtuales"><?= number_format($reportVirtualPayments,0,",",".") ?></span>
        <span id="gastos_sesion"><?= number_format($reportGastos,0,",",".") ?></span>
        <span id="compras_sesion"><?= number_format($reportCompras,0,",",".") ?></span>
    </div>

    <div style="margin-top:12px; display:flex; gap:8px;">
        <button onclick="window.open('<?= htmlspecialchars('invoice_print.php?session_id='.$rep['id'].'&width=58') ?>','_blank','noopener')">Imprimir</button>

        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="ack_report">
            <input type="hidden" name="session_id" value="<?= htmlspecialchars((string)$rep['id']) ?>">
            <button type="submit">Confirmar reporte</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
    <p>No tienes una caja abierta actualmente.</p>
    <form method="POST">
        <input type="hidden" name="action" value="open">
        <label>Monto de apertura:</label>
        <input type="text" name="opening_amount" id="opening_amount" required>
        <button type="submit">Abrir caja</button>
    </form>
<?php endif; ?>

<?php if ($currentUserRole == 1): ?>
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
                <th>Compras</th>
                <th>Diferencia</th>
                <th>Valor a Retirar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
            <?php
                $openedAt = $s['opened_at'];
                $closedAt = $s['closed_at'] ?: date('Y-m-d H:i:s');

                $stmtVentasSesion->execute([$currentBranchId, $openedAt, $closedAt]);
                $ventasSesion = (float)$stmtVentasSesion->fetchColumn();

                $stmtVirtual->execute([$openedAt, $closedAt, $currentBranchId]);
                $virtualSesion = (float)$stmtVirtual->fetchColumn();

                $stmtGastos->execute([$currentBranchId, $openedAt, $closedAt]);
                $gastosSesion = (float)$stmtGastos->fetchColumn();

                $stmtCompras->execute([$currentBranchId, $openedAt, $closedAt]);
                $comprasSesion = (float)$stmtCompras->fetchColumn();

                $withdrawSesion = null;
                if ($s['closing_amount'] !== null) {
                    $withdrawSesion = (float)$s['closing_amount'] - (float)$s['opening_amount'];
                }

                $diff = null;
                if ($s['closing_amount'] !== null) {
                    $diff = $withdrawSesion + $virtualSesion + $gastosSesion + $comprasSesion - $ventasSesion;
                }

                $diffTexto = ($diff === null) ? '-' : (($diff >= 0 ? '+' : '-') . '$' . number_format(abs($diff), 0, ",", "."));
                $withdrawTexto = ($withdrawSesion === null) ? '-' : ('$' . number_format($withdrawSesion, 0, ",", "."));
            ?>
            <tr>
                <td class="id-col"><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['user_name']) ?></td>
                <td>$<?= number_format((float)$s['opening_amount'], 0, ",", ".") ?></td>
                <td><?= $s['closing_amount'] !== null ? '$'.number_format((float)$s['closing_amount'], 0, ",", ".") : '-' ?></td>
                <td><?= htmlspecialchars($s['status']) ?></td>
                <td><?= htmlspecialchars($s['opened_at']) ?></td>
                <td><?= htmlspecialchars($s['closed_at'] ?: '-') ?></td>
                <td><?= '$' . number_format($ventasSesion, 0, ",", ".") ?></td>
                <td><?= '$' . number_format($gastosSesion, 0, ",", ".") ?></td>
                <td><?= '$' . number_format($comprasSesion, 0, ",", ".") ?></td>
                <td><?= $diffTexto ?></td>
                <td><?= $withdrawTexto ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
</body>
</html>
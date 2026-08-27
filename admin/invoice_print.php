<?php
// admin/invoice_print.php
// Versión corregida: todo HTML se arma en $html para evitar errores de sintaxis
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// Sincronizar zona horaria
date_default_timezone_set('America/Bogota');
$fecha = new DateTime();
$offset = $fecha->format('P');
try { $pdo->exec("SET time_zone = '$offset';"); } catch (Exception $e) {}

// Cargar configuración por defecto de la empresa
$company = [
    'name'    => 'Mi Negocio S.A.',
    'nit'     => '900123456-7',
    'address' => 'Cll 123 #45-67',
    'legend'  => 'Conserve este comprobante.',
    'logo'    => '',
    'phone'   => ''
];

try {
    $stmtSet = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('company_name','company_nit','company_address','company_legend','company_logo','company_phone') ");
    $stmtSet->execute();
    $rows = $stmtSet->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        switch ($r['key']) {
            case 'company_name': $company['name'] = $r['value']; break;
            case 'company_nit': $company['nit'] = $r['value']; break;
            case 'company_address': $company['address'] = $r['value']; break;
            case 'company_legend': $company['legend'] = $r['value']; break;
            case 'company_logo': $company['logo'] = $r['value']; break;
            case 'company_phone': $company['phone'] = $r['value']; break;
        }
    }
} catch (Exception $e) {
    // ignore
}

// Parámetros
$sessionId  = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$orderId    = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$gastoId    = isset($_GET['gasto_id']) ? intval($_GET['gasto_id']) : 0;

$cart = [];
$invoiceData = [
    'number'   => date('YmdHis'),
    'date'     => date('Y-m-d H:i:s'),
    'cashier'  => $_SESSION['user']['name'] ?? 'Cajero',
    'customer' => ''
];

$branchIdForQr = null;
$taxRate = 0.0;

/* ---------------------------
/* ---------------------------
   BLOQUE: Reporte de Cierre
   --------------------------- */
$closureId = $sessionId;
if ($closureId > 0 && $orderId === 0 && $gastoId === 0) {
    $stmt = $pdo->prepare("
      SELECT c.*, u.name AS user_name, b.name AS branch_name
      FROM cash_sessions c
      LEFT JOIN users u ON u.id = c.user_id
      LEFT JOIN branches b ON b.id = c.branch_id
      WHERE c.id = ?
      LIMIT 1
    ");
    $stmt->execute([$closureId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo "Cierre no encontrado."; exit; }

    if (($c['branch_id'] ?? null) != ($_SESSION['branch_id'] ?? null) && ($_SESSION['user']['role_id'] ?? 0) != 1) {
        echo "Acceso denegado."; exit;
    }

    $opening_at = $c['opened_at'] ?? $c['opening_at'] ?? $c['created_at'] ?? date('Y-m-d H:i:s');
    $closing_at = $c['closed_at'] ?? $c['closed_at'] ?? $c['updated_at'] ?? date('Y-m-d H:i:s');

    // Ventas
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(total),0) FROM orders
      WHERE branch_id = ? AND status != 'cancelado' AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_total = floatval($stmt->fetchColumn());

    // Pagos virtuales
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(p.amount),0) FROM payments p
      INNER JOIN orders o ON o.id = p.order_id
      WHERE o.branch_id = ? AND p.method = 'virtual' AND p.status = 'completado' AND o.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_virtual = floatval($stmt->fetchColumn());

    // 👉 NUEVO: Gastos en la sesión (comparando por DATETIME)
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(valor),0) 
      FROM gastos g
      WHERE g.branch_id = ? 
        AND CONCAT(g.fecha,' ',g.hora) BETWEEN ? AND ?
        AND g.anulado = 0
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_gastos = floatval($stmt->fetchColumn());

    $opening_amount = floatval($c['opening_amount'] ?? 0);
    $closing_amount = floatval($c['closing_amount'] ?? 0);

    // Ajustar diferencia incluyendo gastos
    $difference = $closing_amount - ($opening_amount + $sales_total + $sales_gastos) + $sales_virtual;
    $withdraw_amount = $closing_amount - $opening_amount;

    $width = (isset($_GET['width']) && intval($_GET['width']) === 80) ? '80mm' : '58mm';
    $fmt = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };
    $opening_display = htmlspecialchars($opening_at);
    $closing_display = htmlspecialchars($closing_at);

    // Construir HTML del cierre
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Reporte de Cierre</title>';
    $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<style>:root{--pad:8px;--mono:"Courier New",Courier,monospace}html,body{margin:0;padding:0;background:#fff;color:#000;font-family:var(--mono);font-size:12px;font-weight:700}.ticket{width:100%;max-width:320px;padding:var(--pad);box-sizing:border-box}.title{font-weight:900;text-align:center;font-size:14px;margin-bottom:6px}.subtitle{font-weight:800;font-size:12px;text-align:center;margin-bottom:6px}.sep{border-top:1px dashed #000;margin:8px 0}.row{display:flex;justify-content:space-between;align-items:flex-start;margin:4px 0;line-height:1.25}.label{flex:1;font-size:12px}.value{flex:1;text-align:right;font-weight:900}@media print{body{width:'.$width.'}.no-print{display:none}}</style>';
    $html .= '</head><body><div class="ticket">';
    $html .= '<div class="title">Reporte de Cierre</div>';
    $html .= '<div class="subtitle">Caja #'.htmlspecialchars($c['id']).'</div>';
    $html .= '<div class="row"><div class="label">Usuario</div><div class="value">'.htmlspecialchars($c['user_name']).'</div></div>';
    $html .= '<div class="row"><div class="label">Sucursal</div><div class="value">'.htmlspecialchars($c['branch_name']).'</div></div>';
    $html .= '<div class="sep"></div>';
    $html .= '<div class="row"><div class="label">Apertura registrada</div><div class="value">'.$fmt($opening_amount).'</div></div>';
    $html .= '<div style="font-size:11px;margin-top:6px;font-weight:900">'.$opening_display.'</div>';
    $html .= '<div class="row"><div class="label">Cierre contado</div><div class="value">'.$fmt($closing_amount).'</div></div>';
    $html .= '<div style="font-size:11px;margin-top:6px;font-weight:900">'.$closing_display.'</div>';
    $html .= '<div class="sep"></div>';
    $html .= '<div class="row"><div class="label">Valor a retirar</div><div class="value">'.$fmt($withdraw_amount).'</div></div>';
    $html .= '<div class="row"><div class="label">Ventas en la sesión</div><div class="value">'.$fmt($sales_total).'</div></div>';
    $html .= '<div class="row"><div class="label">Ventas virtuales en la sesión</div><div class="value">'.$fmt($sales_virtual).'</div></div>';
    $html .= '<div class="row"><div class="label">Gastos en la sesión</div><div class="value">'.$fmt($sales_gastos).'</div></div>';
    $html .= '<div class="sep"></div>';
    $html .= '<div class="row"><div class="label">Diferencia (cierre - (apertura + ventas + gastos))</div><div class="value">'.(($difference>=0?'+':'-').$fmt(abs($difference))).'</div></div>';
    $html .= '<div style="text-align:center;margin-top:10px;font-weight:700">Gracias</div>';
    $html .= '<div class="no-print" style="text-align:center;margin-top:10px"><button onclick="window.print()" style="padding:8px 12px;border-radius:6px;background:#0078d4;color:#fff;border:0;cursor:pointer">Imprimir</button> <button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button></div>';
    $html .= '</div></body></html>';

    echo $html;
    exit;
}

/* ---------------------------
   BLOQUE: Impresión de Gasto
   --------------------------- */
if ($gastoId > 0 && $orderId === 0 && $closureId === 0) {
    // SELECT con fallback a la sucursal del usuario
    $stmt = $pdo->prepare("
        SELECT g.*,
               u.name AS usuario_nombre,
               COALESCE(b.name, ub.name, '') AS branch_name,
               COALESCE(b.nit, ub.nit, '') AS branch_nit,
               COALESCE(b.address, ub.address, '') AS branch_address,
               COALESCE(b.phone, ub.phone, '') AS branch_phone,
               COALESCE(b.invoice_legend, ub.invoice_legend, '') AS invoice_legend,
               COALESCE(b.company_logo, ub.company_logo, '') AS company_logo,
               COALESCE(g.branch_id, u.branch_id) AS resolved_branch_id
        FROM gastos g
        LEFT JOIN users u ON u.id = g.usuario_id
        LEFT JOIN branches b ON b.id = g.branch_id
        LEFT JOIN branches ub ON ub.id = u.branch_id
        WHERE g.id = ? LIMIT 1
    ");
    $stmt->execute([$gastoId]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) { echo "Gasto no encontrado."; exit; }

    // Seguridad por sucursal (usar resolved_branch_id)
    $resolvedBranchId = $g['resolved_branch_id'] ?? null;
    if (($resolvedBranchId ?? null) != ($_SESSION['branch_id'] ?? null) && ($_SESSION['user']['role_id'] ?? 0) != 1) {
        echo "Acceso denegado."; exit;
    }

    // Preparar datos
    $invoiceData['number'] = 'G' . $g['id'];
    $invoiceData['date']   = trim(($g['fecha'] ?? '') . ' ' . ($g['hora'] ?? ''));
    $invoiceData['cashier'] = $g['usuario_nombre'] ?? ($_SESSION['user']['name'] ?? 'Cajero');
    $invoiceData['customer'] = $g['referencia'] ?? '';
    $branchIdForQr = $resolvedBranchId;

    // Si hay branchIdForQr, recargar datos de branch para consistencia
    if ($branchIdForQr) {
        try {
            $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmtBranch->execute([$branchIdForQr]);
            $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
            if ($branch) {
                $company = [
                    'name'    => $branch['name'] ?? $company['name'],
                    'nit'     => $branch['nit'] ?? $company['nit'],
                    'address' => $branch['address'] ?? $company['address'],
                    'phone'   => $branch['phone'] ?? $company['phone'],
                    'legend'  => $branch['invoice_legend'] ?? $company['legend'],
                    'logo'    => $branch['company_logo'] ?? $company['logo']
                ];
                $g['branch_name'] = $branch['name'] ?? ($g['branch_name'] ?? $company['name']);
                $g['branch_nit'] = $branch['nit'] ?? ($g['branch_nit'] ?? $company['nit']);
                $g['branch_address'] = $branch['address'] ?? ($g['branch_address'] ?? $company['address']);
                $g['branch_phone'] = $branch['phone'] ?? ($g['branch_phone'] ?? $company['phone']);
                $g['company_logo'] = $branch['company_logo'] ?? ($g['company_logo'] ?? $company['logo']);
                $g['invoice_legend'] = $branch['invoice_legend'] ?? ($g['invoice_legend'] ?? $company['legend']);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // Construir HTML del gasto (ancho 58mm)
    $width = '58mm';
    $fmtMoney = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };

    $branchDisplayName = trim($g['branch_name'] ?? '') ?: $company['name'];
    $branchDisplayNit  = trim($g['branch_nit'] ?? '') ?: $company['nit'];
    $branchDisplayAddr = trim($g['branch_address'] ?? '') ?: $company['address'];
    $branchDisplayPhone= trim($g['branch_phone'] ?? '') ?: $company['phone'];

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Gasto #'.htmlspecialchars($g['id']).'</title>';
    $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<style>:root{--pad:6px;--mono:\'Courier New\',Courier,monospace}html,body{margin:0;padding:0;background:#fff;color:#000;font-family:var(--mono);font-weight:700}.ticket{max-width:320px;padding:var(--pad);box-sizing:border-box}.center{text-align:center;font-weight:800;color:#000}.small{font-size:11px;font-weight:900;color:#000}.sep{border-top:1px dashed #000;margin:6px 0}.no-print{display:block}@media print{body{width:'.$width.'}.no-print{display:none}}img.logo{max-width:160px;max-height:60px;margin-bottom:6px}</style>';
    $html .= '</head><body><div class="ticket">';

    if (!empty($g['company_logo'])) {
        $logoEsc = htmlspecialchars($g['company_logo']);
        $html .= '<div class="center"><img src="'.$logoEsc.'" alt="Logo" class="logo"></div>';
    } elseif (!empty($company['logo'])) {
        $logoEsc = htmlspecialchars($company['logo']);
        $html .= '<div class="center"><img src="'.$logoEsc.'" alt="Logo" class="logo"></div>';
    }

    $html .= '<div class="center" style="font-weight:bold;font-size:14px;color:#000;">'.htmlspecialchars($branchDisplayName).'</div>';
    $html .= '<div class="center small" style="color:#000;">NIT: '.htmlspecialchars($branchDisplayNit).'</div>';
    $html .= '<div class="center small" style="color:#000;">'.htmlspecialchars($branchDisplayAddr).'</div>';
    if (!empty($branchDisplayPhone)) $html .= '<div class="center small" style="color:#000;">Tel: '.htmlspecialchars($branchDisplayPhone).'</div>';

    $qrContent = "Gasto:".($invoiceData['number'] ?? '')." | Fecha:".($invoiceData['date'] ?? '');
    if ($branchIdForQr) $qrContent .= " | Sucursal:{$branchIdForQr}";
    $qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";
    $html .= '<div class="center" style="margin-top:6px;"><img src="'.$qrUrl.'" alt="QR" style="width:70px;height:70px;"></div>';

    $html .= '<div class="sep"></div>';
    $html .= '<div class="small">Gasto ID: <strong>'.htmlspecialchars($g['id']).'</strong></div>';
    $html .= '<div class="small">Fecha: <strong style="color:#000;">'.htmlspecialchars($invoiceData['date']).'</strong></div>';
    $html .= '<div class="small">Usuario: <strong style="color:#000;">'.htmlspecialchars($invoiceData['cashier']).'</strong></div>';
    $html .= '<div class="small">Sucursal: <strong style="color:#000;">'.htmlspecialchars($branchDisplayName).'</strong></div>';
    $html .= '<div class="sep"></div>';
    $html .= '<div class="small">Descripción:</div>';
    $html .= '<div class="small" style="margin-bottom:6px;">'.nl2br(htmlspecialchars($g['descripcion'] ?? '')).'</div>';
    $html .= '<div class="small">Referencia: <strong>'.htmlspecialchars($g['referencia'] ?? '-').'</strong></div>';
    $html .= '<div class="small">Valor: <strong>'.$fmtMoney($g['valor']).'</strong></div>';
    $html .= '<div class="sep"></div>';
    $html .= '<div class="small" style="color:#000;">'.htmlspecialchars($g['invoice_legend'] ?? $company['legend']).'</div>';
    $html .= '<div style="height:20px;"></div>';
    $html .= '<div class="no-print" style="margin-top:10px;text-align:center;"><button onclick="window.print()" style="padding:10px 14px;border-radius:6px;">Imprimir</button> <button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button></div>';
    $html .= '</div></body></html>';

    // Guardar copia HTML si la columna existe
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM gastos LIKE 'printed_invoice_html'")->fetchAll();
        if (!empty($cols)) {
            $stmtSave = $pdo->prepare("UPDATE gastos SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
            $stmtSave->execute([$html, $gastoId]);
        }
    } catch (Exception $e) {
        // ignore
    }

    echo $html;
    exit;
}

/* ---------------------------
   BLOQUE: Impresión de Orden (order_id)
   --------------------------- */
if ($orderId > 0 && $gastoId === 0 && $closureId === 0) {
    try {
        $stmtO = $pdo->prepare("SELECT id, total, created_at, COALESCE(customer_name,'') AS customer_name, branch_id, user_id FROM orders WHERE id = ? LIMIT 1");
        $stmtO->execute([$orderId]);
        $orderRow = $stmtO->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $orderRow = false;
    }

    if ($orderRow) {
        $invoiceData['number']  = 'O' . $orderRow['id'];
        $invoiceData['date']    = $orderRow['created_at'];
        $invoiceData['customer']= $orderRow['customer_name'] ?: $invoiceData['customer'];
        $branchIdForQr = $orderRow['branch_id'] ?? null;

        // Cargar items
        $items = [];
        try {
            $stmtItems = $pdo->prepare("
                SELECT 
                    oi.id,
                    oi.order_id,
                    oi.product_id,
                    oi.quantity AS qty,
                    oi.price AS unit_price,
                    oi.subtotal AS line_total,
                    COALESCE(oi.product_name, p.name, CONCAT('Producto ', oi.product_id)) AS product_name
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $items = [];
        }

        if (empty($items)) {
            $items[] = [
                'product_name' => "Venta #{$orderRow['id']}",
                'qty' => 1,
                'unit_price' => floatval($orderRow['total']),
                'line_total' => floatval($orderRow['total']),
                'product_id' => 0
            ];
        }

        foreach ($items as $it) {
            $cart[] = [
                'qty' => intval($it['qty'] ?? 1),
                'code' => isset($it['product_id']) ? (string)$it['product_id'] : '',
                'name' => $it['product_name'] ?? 'Producto',
                'unit_price' => floatval($it['unit_price'] ?? ($it['line_total'] ?? 0))
            ];
        }
    }

    // Cargar datos de branch si aplica
    if ($branchIdForQr) {
        try {
            $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmtBranch->execute([$branchIdForQr]);
            $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
            if ($branch) {
                $company = [
                    'name'    => $branch['name'] ?? $company['name'],
                    'nit'     => $branch['nit'] ?? $company['nit'],
                    'address' => $branch['address'] ?? $company['address'],
                    'phone'   => $branch['phone'] ?? $company['phone'],
                    'legend'  => $branch['invoice_legend'] ?? $company['legend'],
                    'logo'    => $branch['company_logo'] ?? $company['logo']
                ];
                $taxRate = isset($branch['tax_rate']) ? floatval($branch['tax_rate']) : 0.0;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

// Render final para orden (o fallback)
// Construcción de items y totales
$itemsHtml = '';
$subtotal  = 0;
foreach ($cart as $it) {
    $qty = intval($it['qty']);
    $name = htmlspecialchars($it['name'] ?? '');
    $unit = floatval($it['unit_price']);
    $lineTotal = $qty * $unit;
    $subtotal += $lineTotal;
    $nameShort = (mb_strlen($name) > 28) ? mb_substr($name, 0, 25) . '...' : $name;
    $itemsHtml .= "<tr><td>{$qty}x</td><td>{$nameShort}</td><td style='text-align:right;'>$" . number_format($lineTotal, 0, ",", ".") . "</td></tr>";
}
$tax = round($subtotal * $taxRate);
$total = $subtotal + $tax;

// Pagos
$cashReceived    = 0.0;
$virtualReceived = 0.0;
$changeGiven     = 0.0;

if ($orderId > 0) {
    try {
        $stmtPay = $pdo->prepare("
            SELECT method, amount, cash_received, change_given 
            FROM payments 
            WHERE order_id = ?
        ");
        $stmtPay->execute([$orderId]);
        foreach ($stmtPay->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['method'] ?? '') === 'efectivo') {
                $cashReceived = floatval($r['cash_received'] ?? $r['amount'] ?? 0);
                $changeGiven  = floatval($r['change_given'] ?? 0);
            }
            if (($r['method'] ?? '') === 'virtual') {
                $virtualReceived = floatval($r['amount'] ?? 0);
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}

// QR para orden
$qrContent = "Factura:".($invoiceData['number'] ?? '')." | Fecha:".($invoiceData['date'] ?? '');
if ($branchIdForQr) $qrContent .= " | Sucursal:{$branchIdForQr}";
$qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";

// HTML final para orden (fallback simple si no hay plantilla por branch)
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Factura</title>';
$html .= '<style>body{font-family:monospace;margin:0;padding:6px;color:#000;font-weight:700}.print-area{max-width:320px;margin:0 auto}.items{width:100%;border-collapse:collapse;margin-top:6px}.items td{padding:2px 0;vertical-align:top}.sep{border-top:1px dashed #000;margin:6px 0}.total{font-weight:900;font-size:13px}.logo{max-width:160px;max-height:60px;margin-bottom:6px}.qr{width:70px;height:70px}.center{text-align:center}.small{font-size:11px}.tiny{font-size:10px}.no-print{display:block}@media print{.no-print{display:none}}</style>';
$html .= '</head><body><div class="print-area">';

if (!empty($company['logo'])) {
    $logoEsc = htmlspecialchars($company['logo']);
    $html .= '<div class="center"><img src="'.$logoEsc.'" alt="Logo" class="logo"></div>';
}
$html .= '<div class="center" style="font-weight:bold;font-size:14px;color:#000;">'.htmlspecialchars($company['name']).'</div>';
$html .= '<div class="center small" style="color:#000;">NIT: '.htmlspecialchars($company['nit']).'</div>';
$html .= '<div class="center small" style="color:#000;">'.htmlspecialchars($company['address']).'</div>';
if (!empty($company['phone'])) $html .= '<div class="center small" style="color:#000;">Tel: '.htmlspecialchars($company['phone']).'</div>';
$html .= '<div class="center"><img src="'.$qrUrl.'" alt="QR" class="qr"></div>';
$html .= '<div class="sep"></div>';
$html .= '<div class="small">Factura: <strong>'.htmlspecialchars($invoiceData['number']).'</strong></div>';
$html .= '<div class="small">Fecha: <strong>'.htmlspecialchars($invoiceData['date']).'</strong></div>';
$html .= '<div class="small">Cajero: <strong>'.htmlspecialchars($invoiceData['cashier']).'</strong></div>';
$html .= '<div class="small">Cliente: <strong>'.htmlspecialchars($invoiceData['customer']).'</strong></div>';
$html .= '<table class="items tiny"><tbody>'.$itemsHtml.'</tbody></table>';
$html .= '<div class="sep"></div>';
$html .= '<div class="small">Subtotal: $'.number_format($subtotal,0,",",".").'</div>';
if ($taxRate > 0) $html .= '<div class="small">IVA: $'.number_format($tax,0,",",".").'</div>';
$html .= '<div class="total">TOTAL: $'.number_format($total,0,",",".").'</div>';
if ($cashReceived > 0) $html .= '<div class="small">Efectivo recibido: $'.number_format($cashReceived,0,",",".").'</div>';
if ($virtualReceived > 0) $html .= '<div class="small">Pago virtual: $'.number_format($virtualReceived,0,",",".").'</div>';
if ($changeGiven <= 0 && ($cashReceived > 0 || $virtualReceived > 0)) $changeGiven = max(0, $cashReceived + $virtualReceived - $total);
if ($changeGiven > 0) $html .= '<div class="small">Vuelto: $'.number_format($changeGiven,0,",",".").'</div>';
$html .= '<div class="sep"></div>';
$html .= '<div class="tiny" style="color:#000;">'.htmlspecialchars($company['legend']).'</div>';
$html .= '<div style="height:20px;"></div>';
$html .= '<div class="no-print" style="margin-top:10px;text-align:center;"><button onclick="window.print()" style="padding:10px 14px;border-radius:6px;">Imprimir</button> <button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button></div>';
$html .= '</div></body></html>';

echo $html;
exit;

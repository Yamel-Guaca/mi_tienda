<?php
// admin/invoice_print.php
// ... (comentarios iniciales) ...

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

 $pdo = DB::getConnection();

// =================================================================
// --- INICIO: SINCRONIZACIÓN DE HORA (SOLUCIÓN AL DILEMA) ---
// =================================================================

// 1. Establecer la zona horaria en PHP.
// CAMBIA ESTO según tu país. Ejemplos:
// Colombia/México/Perú: 'America/Bogota'
// Argentina/Chile/Paraguay: 'America/Argentina/Buenos_Aires' o 'America/Santiago'
// España: 'Europe/Madrid'
date_default_timezone_set('America/Bogota'); 

// 2. Sincronizar MySQL con la zona horaria de PHP.
// Calculamos el desfase (ej. -05:00) y se lo enviamos a MySQL.
// Esto hace que las columnas TIMESTAMP (como created_at) y la función NOW() 
// se conviertan automáticamente a tu hora local.
 $fecha = new DateTime();
 $offset = $fecha->format('P'); // Obtiene formato +/-HH:MM

try {
    $pdo->exec("SET time_zone = '$offset';");
} catch (Exception $e) {
    // Si falla, continuamos, pero es raro que falle.
}

// =================================================================
// --- FIN: SINCRONIZACIÓN DE HORA ---
// =================================================================

// --- Cargar configuración de la empresa desde settings si existe ---
 $company = [
    'name'    => 'Mi Negocio S.A.',
    'nit'     => '900123456-7',
    'address' => 'Cll 123 #45-67',
    'legend'  => 'Factura de venta. Conserve este comprobante.',
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
} catch (Exception $e) {}

// Parámetros
 $sessionId  = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
 $orderId    = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

 $cart = [];
// Nota: Al estar sincronizado PHP y MySQL, date() y $orderRow['created_at'] coincidirán.
 $invoiceData = [
    'number'   => date('YmdHis'),
    'date'     => date('Y-m-d H:i:s'),
    'cashier'  => $_SESSION['user']['name'] ?? 'Cajero',
    'customer' => ''
];

 $branchIdForQr = null;

// --- Cargar orden si existe ---
if ($orderId > 0) {
    try {
        // Al hacer el SELECT después de SET time_zone, MySQL convertirá el created_at 
        // de UTC a tu hora local automáticamente.
        $stmtO = $pdo->prepare("SELECT id, total, created_at, COALESCE(customer_name,'') AS customer_name, branch_id, user_id FROM orders WHERE id = ? LIMIT 1");
        $stmtO->execute([$orderId]);
        $orderRow = $stmtO->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $orderRow = false;
    }

    if ($orderRow) {
        $invoiceData['number']  = 'O' . $orderRow['id'];
        $invoiceData['date']    = $orderRow['created_at']; // Ahora vendrá con la hora correcta
        $invoiceData['customer']= $orderRow['customer_name'] ?: $invoiceData['customer'];
        $branchIdForQr = $orderRow['branch_id'] ?? null;

        // --- Carga robusta de items ---
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
}

// --- Ajuste: cargar datos de la sucursal desde branches si existe branchIdForQr ---
 $taxRate = 0.0;
if ($branchIdForQr) {
    try {
        $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
        $stmtBranch->execute([$branchIdForQr]);
        $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
        if ($branch) {
            $company = [
                'name'    => $branch['name'] ?? '',
                'nit'     => $branch['nit'] ?? '',
                'address' => $branch['address'] ?? '',
                'phone'   => $branch['phone'] ?? '',
                'legend'  => $branch['invoice_legend'] ?? '',
                'logo'    => $branch['company_logo'] ?? ''
            ];
            $taxRate = isset($branch['tax_rate']) ? floatval($branch['tax_rate']) : 0.0;
        }
    } catch (Exception $e) {}
}

// --- Items y totales ---
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

// --- Pagos ---
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
    } catch (Exception $e) {}
}

// --- QR ---
 $qrContent = "Factura:".($invoiceData['number'] ?? '')." | Fecha:".($invoiceData['date'] ?? '');
if ($branchIdForQr) $qrContent .= " | Sucursal:{$branchIdForQr}";
 $qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";

// --- HTML inicio (fijo a 80mm) ---
 $html = "<!doctype html><html><head><meta charset='utf-8'><title>Factura</title>";
 $html .= "<style>
body{font-family:monospace;margin:0;padding:6px;color:#000}
.print-area{max-width:320px;margin:0 auto}
.items{width:100%;border-collapse:collapse;margin-top:6px}
.items td{padding:2px 0;vertical-align:top}
.sep{border-top:1px dashed #000;margin:6px 0}
.total{font-weight:bold;font-size:13px}
.logo{max-width:160px;max-height:60px;margin-bottom:6px;}
.qr{width:70px;height:70px;}
.center{text-align:center}
.small{font-size:11px}
.tiny{font-size:10px}
.no-print{display:block}
@media print {.no-print{display:none}}
</style>";
 $html .= "</head><body>";
 $html .= "<div class='print-area'>";

// Encabezado con logo y datos
if (!empty($company['logo'] ?? '')) {
    $logoEsc = htmlspecialchars($company['logo'] ?? '');
    $html .= "<div class='center'><img src='{$logoEsc}' alt='Logo' class='logo'></div>";
}
 $html .= "<div class='center' style='font-weight:bold;font-size:14px;'>".htmlspecialchars($company['name'] ?? '')."</div>";
 $html .= "<div class='center small'>NIT: ".htmlspecialchars($company['nit'] ?? '')."</div>";
 $html .= "<div class='center small'>".htmlspecialchars($company['address'] ?? '')."</div>";
if (!empty($company['phone'] ?? '')) {
    $html .= "<div class='center small'>Tel: ".htmlspecialchars($company['phone'] ?? '')."</div>";
}
 $html .= "<div class='center'><img src='{$qrUrl}' alt='QR' class='qr'></div>";

 $html .= "<div class='sep'></div>";
 $html .= "<div class='small'>Factura: <strong>".htmlspecialchars($invoiceData['number'] ?? '')."</strong></div>";
 $html .= "<div class='small'>Fecha: ".htmlspecialchars($invoiceData['date'] ?? '')."</div>";
 $html .= "<div class='small'>Cajero: ".htmlspecialchars($invoiceData['cashier'] ?? '')."</div>";
 $html .= "<div class='small'>Cliente: ".htmlspecialchars($invoiceData['customer'] ?? '')."</div>";

// Items
 $html .= "<table class='items tiny'><tbody>{$itemsHtml}</tbody></table>";
 $html .= "<div class='sep'></div>";

// Totales
 $html .= "<div class='small'>Subtotal: $".number_format($subtotal,0,",",".")."</div>";
if ($taxRate > 0) {
    $html .= "<div class='small'>IVA: $".number_format($tax,0,",",".")."</div>";
}
 $html .= "<div class='total'>TOTAL: $".number_format($total,0,",",".")."</div>";

// Mostrar pagos
if ($cashReceived > 0) {
    $html .= "<div class='small'>Efectivo recibido: $".number_format($cashReceived,0,",",".")."</div>";
}
if ($virtualReceived > 0) {
    $html .= "<div class='small'>Pago virtual: $".number_format($virtualReceived,0,",",".")."</div>";
}
if ($changeGiven <= 0 && ($cashReceived > 0 || $virtualReceived > 0)) {
    $changeGiven = max(0, $cashReceived + $virtualReceived - $total);
}
if ($changeGiven > 0) {
    $html .= "<div class='small'>Vuelto: $".number_format($changeGiven,0,",",".")."</div>";
}

 $html .= "<div class='sep'></div>";
 $html .= "<div class='tiny'>".htmlspecialchars($company['legend'] ?? '')."</div>";
 $html .= "<div style='height:20px;'></div>";

// Botones de acción (ocultos al imprimir)
 $html .= "<div class='no-print' style='margin-top:10px; text-align:center;'>
           <button onclick='window.print()' style='padding:10px 14px;border-radius:6px;'>Imprimir</button>
           <button onclick='window.close()' style='padding:8px 12px;border-radius:6px;'>Cerrar</button>
         </div>";

 $html .= "</div>";
 $html .= "</body></html>";

// Guardar copia HTML en cash_sessions u orders según el origen
try {
    if (!empty($orderId) && $orderId > 0) {
        // Nota: Al tener SET time_zone activo, NOW() guardará la hora correcta en la DB.
        $stmtSaveOrder = $pdo->prepare("UPDATE orders SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSaveOrder->execute([$html, $orderId]);
    } elseif (!empty($sessionId) && $sessionId > 0) {
        $stmtSave = $pdo->prepare("UPDATE cash_sessions SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSave->execute([$html, $sessionId]);
    }
} catch (Exception $e) {
    // No interrumpir la impresión si falla el guardado
}

// Entregar HTML al navegador
echo $html;
exit;
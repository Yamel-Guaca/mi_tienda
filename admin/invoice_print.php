<?php
// invoice_print.php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Ajuste opcional de zona horaria si la BD lo requiere
try {
    $pdo->exec("SET time_zone = '-05:00'");
} catch (Exception $e) {}

// Parámetros
$orderId   = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$sessionId = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$gastoId   = isset($_GET['gasto_id']) ? intval($_GET['gasto_id']) : 0;
$purchaseId = isset($_GET['purchase_id']) ? intval($_GET['purchase_id']) : 0; // NUEVO: Para Compras

// --- Parámetro para compras ---
$purchaseId = isset($_GET['purchase_id']) ? intval($_GET['purchase_id']) : 0;

/* ===========================================================
   NUEVO BLOQUE: Impresión de Soporte de Compra (purchase_id)
   =========================================================== */
if ($purchaseId > 0) {
    // 1. Obtener datos de la compra
    $stmtP = $pdo->prepare("
        SELECT p.*, u.name AS cashier_name, b.name AS branch_name
        FROM purchases p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN branches b ON b.id = p.branch_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmtP->execute([$purchaseId]);
    $purchaseRow = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$purchaseRow) {
        echo "Compra no encontrada.";
        exit;
    }

    // Cargar datos de la sucursal si existen
    if (!empty($purchaseRow['branch_id'])) {
        try {
            $stmtBranchP = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $stmtBranchP->execute([$purchaseRow['branch_id']]);
            $branchForPurchase = $stmtBranchP->fetch(PDO::FETCH_ASSOC);
            if ($branchForPurchase) {
                $company['name']    = $branchForPurchase['name'] ?? $company['name'];
                $company['nit']     = $branchForPurchase['nit'] ?? $company['nit'];
                $company['address'] = $branchForPurchase['address'] ?? $company['address'];
                $company['phone']   = $branchForPurchase['phone'] ?? $company['phone'];
                $company['logo']    = $branchForPurchase['company_logo'] ?? $company['logo'];
                $company['legend']  = $branchForPurchase['invoice_legend'] ?? $company['legend'];
            }
        } catch (Exception $e) {}
    }

    // 2. Obtener items de la compra
    $stmtItemsP = $pdo->prepare("
        SELECT pi.*, COALESCE(pr.name, CONCAT('Producto ', pi.product_id)) AS product_name
        FROM purchase_items pi
        LEFT JOIN products pr ON pi.product_id = pr.id
        WHERE pi.purchase_id = ?
    ");
    $stmtItemsP->execute([$purchaseId]);
    $purchaseItems = $stmtItemsP->fetchAll(PDO::FETCH_ASSOC);

    // Formatear la lista de productos comprados
    $itemsHtml = '';
    foreach ($purchaseItems as $it) {
        $qtyName = $it['qty_purchased'] . 'x';
        if ($it['packaging_qty'] > 1) {
            $qtyName .= " (Emb: {$it['packaging_qty']})";
        }
        $name = htmlspecialchars($it['product_name']);
        if (!empty($it['is_gift'])) {
            $name .= " [REGALO]";
        }
        $nameShort = (mb_strlen($name) > 28) ? mb_substr($name, 0, 25) . '...' : $name;
        $lineTotal = floatval($it['line_total']);

        $itemsHtml .= "<tr><td>{$qtyName}</td><td>{$nameShort}</td><td style='text-align:right;'>$" . number_format($lineTotal, 0, ",", ".") . "</td></tr>";
    }

    // Generar código QR
    $qrContent = "Soporte Compra: CMP-{$purchaseRow['id']} | Proveedor: {$purchaseRow['provider_name']} | Factura: {$purchaseRow['invoice_number']}";
    $qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";

    // Maquetar usando tus mismos estilos CSS
    $html = "<!doctype html><html><head><meta charset='utf-8'><title>Soporte de Compra</title>";
    $html .= "<style>
    body{font-family:monospace;margin:0;padding:6px;color:#000;font-weight:700;}
    .print-area{max-width:320px;margin:0 auto;font-weight:700;color:#000;}
    .items{width:100%;border-collapse:collapse;margin-top:6px;font-weight:700;color:#000;}
    .items td{padding:2px 0;vertical-align:top;font-weight:700;color:#000;}
    .sep{border-top:1px dashed #000;margin:6px 0;}
    .total{font-weight:900;font-size:13px;color:#000;}
    .logo{max-width:160px;max-height:60px;margin-bottom:6px;}
    .qr{width:70px;height:70px;}
    .center{text-align:center;font-weight:800;color:#000;}
    .small{font-size:11px;font-weight:900;color:#000;}
    .tiny{font-size:10px;font-weight:900;color:#000;}
    .no-print{display:block;}
    @media print {.no-print{display:none}}
    </style>";
    $html .= "</head><body><div class='print-area'>";

    if (!empty($company['logo'])) {
        $html .= "<div class='center'><img src='".htmlspecialchars($company['logo'])."' alt='Logo' class='logo'></div>";
    }
    $html .= "<div class='center' style='font-weight:bold;font-size:14px;color:#000;'>".htmlspecialchars($company['name'])."</div>";
    $html .= "<div class='center small'>NIT: ".htmlspecialchars($company['nit'])."</div>";
    $html .= "<div class='center small'>".htmlspecialchars($company['address'])."</div>";
    if (!empty($company['phone'])) {
        $html .= "<div class='center small'>Tel: ".htmlspecialchars($company['phone'])."</div>";
    }
    $html .= "<div class='center'><img src='{$qrUrl}' alt='QR' class='qr'></div>";

    $html .= "<div class='sep'></div>";
    $html .= "<div class='center' style='font-size:13px;'>SOPORTE DE COMPRA</div>";
    $html .= "<div class='small'>Soporte N°: <strong>CMP-".htmlspecialchars($purchaseRow['id'])."</strong></div>";
    $html .= "<div class='small'>N° Factura Prov: <strong>".htmlspecialchars($purchaseRow['invoice_number'])."</strong></div>";
    $html .= "<div class='small'>Proveedor: <strong>".htmlspecialchars($purchaseRow['provider_name'])."</strong></div>";
    $html .= "<div class='small'>Fecha: <strong>".htmlspecialchars($purchaseRow['purchase_date'])."</strong></div>";
    $html .= "<div class='small'>Registrado por: <strong>".htmlspecialchars($purchaseRow['cashier_name'] ?? 'Sistema')."</strong></div>";

    $html .= "<table class='items tiny'><tbody>{$itemsHtml}</tbody></table>";
    $html .= "<div class='sep'></div>";

    $html .= "<div class='total'>TOTAL COMPRA: $".number_format(floatval($purchaseRow['total_amount']), 0, ",", ".")."</div>";

    $html .= "<div class='sep'></div>";
    $html .= "<div class='tiny' style='color:#000;'>Comprobante de ingreso de mercancía a inventario.</div>";
    $html .= "<div style='height:20px;'></div>";

    $html .= "<div class='no-print' style='margin-top:10px; text-align:center;'>
               <button onclick='window.print()' style='padding:10px 14px;border-radius:6px;'>Imprimir</button>
               <button onclick='window.close()' style='padding:8px 12px;border-radius:6px;'>Cerrar</button>
             </div>";

    $html .= "<script>setTimeout(function(){try{window.close();}catch(e){}},60000);</script>";
    $html .= "</div></body></html>";

    // Guardado opcional en BD
    try {
        $stmtSaveP = $pdo->prepare("UPDATE purchases SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSaveP->execute([$html, $purchaseId]);
    } catch (Exception $e) {}

    echo $html;
    exit;
}

// Datos de la empresa por defecto
$company = [
    'name'    => 'MI TIENDA',
    'nit'     => '123456789-0',
    'address' => 'Calle Principal # 123',
    'phone'   => '300 000 0000',
    'legend'  => '¡Gracias por su compra!',
    'logo'    => ''
];

/* ===========================================================
   NUEVO BLOQUE: Impresión de Soporte de Compra (purchase_id)
   =========================================================== */
if ($purchaseId > 0) {
    // 1. Obtener datos de la compra
    $stmtP = $pdo->prepare("
        SELECT p.*, u.name AS cashier_name, b.name AS branch_name
        FROM purchases p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN branches b ON b.id = p.branch_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmtP->execute([$purchaseId]);
    $purchaseRow = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$purchaseRow) {
        echo "Compra no encontrada.";
        exit;
    }

    // Cargar datos de la sucursal para la cabecera si aplica
    if (!empty($purchaseRow['branch_id'])) {
        try {
            $stmtBranchP = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $stmtBranchP->execute([$purchaseRow['branch_id']]);
            $branchForPurchase = $stmtBranchP->fetch(PDO::FETCH_ASSOC);
            if ($branchForPurchase) {
                $company['name']    = $branchForPurchase['name'] ?? $company['name'];
                $company['nit']     = $branchForPurchase['nit'] ?? $company['nit'];
                $company['address'] = $branchForPurchase['address'] ?? $company['address'];
                $company['phone']   = $branchForPurchase['phone'] ?? $company['phone'];
                $company['logo']    = $branchForPurchase['company_logo'] ?? $company['logo'];
                $company['legend']  = $branchForPurchase['invoice_legend'] ?? $company['legend'];
            }
        } catch (Exception $e) {}
    }

    // 2. Obtener items de la compra
    $stmtItemsP = $pdo->prepare("
        SELECT pi.*, COALESCE(pr.name, CONCAT('Producto ', pi.product_id)) AS product_name
        FROM purchase_items pi
        LEFT JOIN products pr ON pi.product_id = pr.id
        WHERE pi.purchase_id = ?
    ");
    $stmtItemsP->execute([$purchaseId]);
    $purchaseItems = $stmtItemsP->fetchAll(PDO::FETCH_ASSOC);

    // Preparar filas de la tabla de items
    $itemsHtml = '';
    foreach ($purchaseItems as $it) {
        $qtyName = $it['qty_purchased'] . 'x';
        if ($it['packaging_qty'] > 1) {
            $qtyName .= " (Emb: {$it['packaging_qty']})";
        }
        $name = htmlspecialchars($it['product_name']);
        if (!empty($it['is_gift'])) {
            $name .= " [REGALO]";
        }
        $nameShort = (mb_strlen($name) > 28) ? mb_substr($name, 0, 25) . '...' : $name;
        $lineTotal = floatval($it['line_total']);

        $itemsHtml .= "<tr><td>{$qtyName}</td><td>{$nameShort}</td><td style='text-align:right;'>$" . number_format($lineTotal, 0, ",", ".") . "</td></tr>";
    }

    // Generar QR
    $qrContent = "Soporte Compra: CMP-{$purchaseRow['id']} | Proveedor: {$purchaseRow['provider_name']} | Factura: {$purchaseRow['invoice_number']}";
    $qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";

    // Generar HTML con exactamente la misma plantilla de invoice_print.php
    $html = "<!doctype html><html><head><meta charset='utf-8'><title>Soporte de Compra</title>";
    $html .= "<style>
    body{font-family:monospace;margin:0;padding:6px;color:#000;font-weight:700;}
    .print-area{max-width:320px;margin:0 auto;font-weight:700;color:#000;}
    .items{width:100%;border-collapse:collapse;margin-top:6px;font-weight:700;color:#000;}
    .items td{padding:2px 0;vertical-align:top;font-weight:700;color:#000;}
    .sep{border-top:1px dashed #000;margin:6px 0;}
    .total{font-weight:900;font-size:13px;color:#000;}
    .logo{max-width:160px;max-height:60px;margin-bottom:6px;}
    .qr{width:70px;height:70px;}
    .center{text-align:center;font-weight:800;color:#000;}
    .small{font-size:11px;font-weight:900;color:#000;}
    .tiny{font-size:10px;font-weight:900;color:#000;}
    .no-print{display:block;}
    @media print {.no-print{display:none}}
    </style>";
    $html .= "</head><body><div class='print-area'>";

    if (!empty($company['logo'])) {
        $html .= "<div class='center'><img src='".htmlspecialchars($company['logo'])."' alt='Logo' class='logo'></div>";
    }
    $html .= "<div class='center' style='font-weight:bold;font-size:14px;color:#000;'>".htmlspecialchars($company['name'])."</div>";
    $html .= "<div class='center small'>NIT: ".htmlspecialchars($company['nit'])."</div>";
    $html .= "<div class='center small'>".htmlspecialchars($company['address'])."</div>";
    if (!empty($company['phone'])) {
        $html .= "<div class='center small'>Tel: ".htmlspecialchars($company['phone'])."</div>";
    }
    $html .= "<div class='center'><img src='{$qrUrl}' alt='QR' class='qr'></div>";

    $html .= "<div class='sep'></div>";
    $html .= "<div class='center' style='font-size:13px;'>SOPORTE DE COMPRA</div>";
    $html .= "<div class='small'>Soporte N°: <strong>CMP-".htmlspecialchars($purchaseRow['id'])."</strong></div>";
    $html .= "<div class='small'>N° Factura Prov: <strong>".htmlspecialchars($purchaseRow['invoice_number'])."</strong></div>";
    $html .= "<div class='small'>Proveedor: <strong>".htmlspecialchars($purchaseRow['provider_name'])."</strong></div>";
    $html .= "<div class='small'>Fecha: <strong>".htmlspecialchars($purchaseRow['purchase_date'])."</strong></div>";
    $html .= "<div class='small'>Registrado por: <strong>".htmlspecialchars($purchaseRow['cashier_name'] ?? 'Sistema')."</strong></div>";

    $html .= "<table class='items tiny'><tbody>{$itemsHtml}</tbody></table>";
    $html .= "<div class='sep'></div>";

    $html .= "<div class='total'>TOTAL COMPRA: $".number_format(floatval($purchaseRow['total_amount']), 0, ",", ".")."</div>";

    $html .= "<div class='sep'></div>";
    $html .= "<div class='tiny' style='color:#000;'>Comprobante de ingreso de mercancía a inventario.</div>";
    $html .= "<div style='height:20px;'></div>";

    $html .= "<div class='no-print' style='margin-top:10px; text-align:center;'>
               <button onclick='window.print()' style='padding:10px 14px;border-radius:6px;'>Imprimir</button>
               <button onclick='window.close()' style='padding:8px 12px;border-radius:6px;'>Cerrar</button>
             </div>";

    $html .= "<script>setTimeout(function(){try{window.close();}catch(e){}},60000);</script>";
    $html .= "</div></body></html>";

    try {
        $stmtSaveP = $pdo->prepare("UPDATE purchases SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSaveP->execute([$html, $purchaseId]);
    } catch (Exception $e) {}

    echo $html;
    exit;
}

// Variables iniciales para el resto del flujo
$invoiceData = [
    'number'   => '',
    'date'     => date('Y-m-d H:i:s'),
    'cashier'  => $_SESSION['user_name'] ?? 'Cajero',
    'customer' => 'Cliente general'
];

$cart = [];
$branchIdForQr = null;

// --- Cargar orden ---
if ($orderId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT o.*, u.name AS cashier_name, c.name AS customer_name, c.nit AS customer_nit
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            $invoiceData['number']  = $order['invoice_number'] ?? $order['id'];
            $invoiceData['date']    = $order['created_at'] ?? $invoiceData['date'];
            $invoiceData['cashier'] = $order['cashier_name'] ?? $invoiceData['cashier'];
            if (!empty($order['customer_name'])) {
                $invoiceData['customer'] = $order['customer_name'] . ($order['customer_nit'] ? " ({$order['customer_nit']})" : "");
            }
            $branchIdForQr = $order['branch_id'] ?? null;

            $stmtItems = $pdo->prepare("
                SELECT oi.*, p.name 
                FROM order_items oi 
                LEFT JOIN products p ON p.id = oi.product_id 
                WHERE oi.order_id = ?
            ");
            $stmtItems->execute([$orderId]);
            $cart = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// --- Cargar desde sesión de caja o gasto si corresponde ---
if ($sessionId > 0 && empty($cart)) {
    try {
        $stmtSession = $pdo->prepare("
            SELECT cs.*, u.name AS cashier_name 
            FROM cash_sessions cs 
            LEFT JOIN users u ON u.id = cs.user_id 
            WHERE cs.id = ?
        ");
        $stmtSession->execute([$sessionId]);
        $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            $invoiceData['number']  = 'CIERRE-' . $session['id'];
            $invoiceData['date']    = $session['closed_at'] ?? date('Y-m-d H:i:s');
            $invoiceData['cashier'] = $session['cashier_name'] ?? $invoiceData['cashier'];
            $branchIdForQr          = $session['branch_id'] ?? null;
        }
    } catch (Exception $e) {}
}

if ($gastoId > 0 && empty($cart)) {
    try {
        $stmtGasto = $pdo->prepare("
            SELECT g.*, u.name AS cashier_name 
            FROM gastos g 
            LEFT JOIN users u ON u.id = g.user_id 
            WHERE g.id = ?
        ");
        $stmtGasto->execute([$gastoId]);
        $gasto = $stmtGasto->fetch(PDO::FETCH_ASSOC);

        if ($gasto) {
            $invoiceData['number']  = 'GASTO-' . $gasto['id'];
            $invoiceData['date']    = $gasto['created_at'] ?? date('Y-m-d H:i:s');
            $invoiceData['cashier'] = $gasto['cashier_name'] ?? $invoiceData['cashier'];
            $branchIdForQr          = $gasto['branch_id'] ?? null;

            $cart[] = [
                'qty'        => 1,
                'name'       => 'GASTO: ' . ($gasto['description'] ?? 'Gasto vario'),
                'unit_price' => $gasto['amount'] ?? 0
            ];
        }
    } catch (Exception $e) {}
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
                'name'    => $branch['name'] ?? $company['name'],
                'nit'     => $branch['nit'] ?? $company['nit'],
                'address' => $branch['address'] ?? $company['address'],
                'phone'   => $branch['phone'] ?? $company['phone'],
                'legend'  => $branch['invoice_legend'] ?? $company['legend'],
                'logo'    => $branch['company_logo'] ?? $company['logo']
            ];
            $taxRate = isset($branch['tax_rate']) ? floatval($branch['tax_rate']) : $taxRate;
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
/* Forzar negrilla en todo el recibo, incluyendo fechas y horas, y asegurar color negro oscuro */
body{font-family:monospace;margin:0;padding:6px;color:#000;font-weight:700;}
.print-area{max-width:320px;margin:0 auto;font-weight:700;color:#000;}
.items{width:100%;border-collapse:collapse;margin-top:6px;font-weight:700;color:#000;}
.items td{padding:2px 0;vertical-align:top;font-weight:700;color:#000;}
.sep{border-top:1px dashed #000;margin:6px 0;}
.total{font-weight:900;font-size:13px;color:#000;}
.logo{max-width:160px;max-height:60px;margin-bottom:6px;}
.qr{width:70px;height:70px;}
.center{text-align:center;font-weight:800;color:#000;}
.small{font-size:11px;font-weight:900;color:#000;}
.tiny{font-size:10px;font-weight:900;color:#000;}
.no-print{display:block;}
@media print {.no-print{display:none}}
</style>";
$html .= "</head><body>";
$html .= "<div class='print-area'>";

// Encabezado con logo y datos
if (!empty($company['logo'] ?? '')) {
    $logoEsc = htmlspecialchars($company['logo'] ?? '');
    $html .= "<div class='center'><img src='{$logoEsc}' alt='Logo' class='logo'></div>";
}
$html .= "<div class='center' style='font-weight:bold;font-size:14px;color:#000;'>".htmlspecialchars($company['name'] ?? '')."</div>";
$html .= "<div class='center small' style='color:#000;'>NIT: ".htmlspecialchars($company['nit'] ?? '')."</div>";
$html .= "<div class='center small' style='color:#000;'>".htmlspecialchars($company['address'] ?? '')."</div>";
if (!empty($company['phone'] ?? '')) {
    $html .= "<div class='center small' style='color:#000;'>Tel: ".htmlspecialchars($company['phone'] ?? '')."</div>";
}
$html .= "<div class='center'><img src='{$qrUrl}' alt='QR' class='qr'></div>";

$html .= "<div class='sep'></div>";
$html .= "<div class='small'>Factura: <strong>".htmlspecialchars($invoiceData['number'] ?? '')."</strong></div>";
$html .= "<div class='small'>Fecha: <strong style='color:#000;'>".htmlspecialchars($invoiceData['date'] ?? '')."</strong></div>";
$html .= "<div class='small'>Cajero: <strong style='color:#000;'>".htmlspecialchars($invoiceData['cashier'] ?? '')."</strong></div>";
$html .= "<div class='small'>Cliente: <strong style='color:#000;'>".htmlspecialchars($invoiceData['customer'] ?? '')."</strong></div>";

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
$html .= "<div class='tiny' style='color:#000;'>".htmlspecialchars($company['legend'] ?? '')."</div>";
$html .= "<div style='height:20px;'></div>";

// Botones de acción (ocultos al imprimir)
$html .= "<div class='no-print' style='margin-top:10px; text-align:center;'>
           <button onclick='window.print()' style='padding:10px 14px;border-radius:6px;'>Imprimir</button>
           <button onclick='window.close()' style='padding:8px 12px;border-radius:6px;'>Cerrar</button>
         </div>";

// --- Opción A mínima: cerrar la ventana automáticamente después de 60 segundos ---
$html .= "<script>setTimeout(function(){try{window.close();}catch(e){}},60000);</script>";

$html .= "</div>";
$html .= "</body></html>";

// Guardar copia HTML en cash_sessions u orders según el origen
try {
    if (!empty($orderId) && $orderId > 0) {
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

// Si no hay parámetros válidos
echo "Parámetro inválido. Use order_id, session_id, gasto_id o purchase_id.";
exit;
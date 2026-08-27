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
try { 
    $pdo->exec("SET time_zone = '$offset';"); 
} catch (Exception $e) {}

// Cargar configuración por defecto de la empresa
$company = [
    'name'    => 'Mi Negocio S.A.',
    'nit'     => '900123456-7',
    'address' => 'Cll 123 #45-67',
    'legend'  => 'Factura de venta. Conserve este comprobante.',
    'logo'    => '',
    'phone'   => ''
];

try {
    $stmtSet = $pdo->prepare("SELECT `key`, `value` 
                              FROM settings 
                              WHERE `key` IN ('company_name','company_nit','company_address','company_legend','company_logo','company_phone')");
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
    // ignorar error si no existe tabla settings
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

    if (!$c) { 
        echo "Cierre no encontrado."; 
        exit; 
    }

    // Seguridad: permitir solo imprimir cierres de la misma sucursal (salvo admin)
    if (($c['branch_id'] ?? null) != ($_SESSION['branch_id'] ?? null) && ($_SESSION['user']['role_id'] ?? 0) != 1) {
        echo "Acceso denegado."; 
        exit;
    }

    // Fechas de apertura y cierre (usar las claves que usas en caja.php)
    $opening_at = $c['opened_at'] ?? $c['opening_at'] ?? $c['created_at'] ?? date('Y-m-d H:i:s');
    $closing_at = $c['closed_at'] ?? $c['updated_at'] ?? date('Y-m-d H:i:s');

    // Ventas completadas en el rango apertura->cierre
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(total),0) FROM orders
      WHERE branch_id = ? AND status != 'cancelado' AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_total = floatval($stmt->fetchColumn());

    // Ventas virtuales (pagos virtuales) en el mismo rango
    // Ajuste: sumar pagos vinculados a órdenes cuya orden fue creada en el rango
    // Esto evita perder pagos cuyo payments.created_at difiera del created_at de la orden
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(p.amount),0) FROM payments p
      INNER JOIN orders o ON o.id = p.order_id
      WHERE o.branch_id = ? AND p.method = 'virtual' AND p.status = 'completado' AND o.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_virtual = floatval($stmt->fetchColumn());

    // NUEVO: Gastos en la sesión (comparando por DATETIME)
    // Se asume que g.fecha tiene formato 'YYYY-MM-DD' y g.hora 'HH:MM:SS'.
    // Usamos STR_TO_DATE para convertir a DATETIME y comparar correctamente.
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(valor),0)
      FROM gastos g
      WHERE g.branch_id = ?
        AND g.anulado = 0
        AND STR_TO_DATE(CONCAT(g.fecha, ' ', g.hora), '%Y-%m-%d %H:%i:%s') BETWEEN ? AND ?
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_gastos = floatval($stmt->fetchColumn());

    $opening_amount = floatval($c['opening_amount'] ?? 0);
    $closing_amount = floatval($c['closing_amount'] ?? 0);

    // Ajustar diferencia incluyendo gastos y pagos virtuales
    // Interpretación: efectivo esperado = apertura + ventas en efectivo + pagos virtuales? 
    // Aquí se asume que $sales_total incluye todas las ventas (efectivo + otros) y $sales_virtual
    // son pagos que no pasan por caja física; la fórmula puede ajustarse según la lógica del negocio.
    $difference = $closing_amount - ($opening_amount + $sales_total - $sales_virtual + $sales_gastos);
    $withdraw_amount = $closing_amount - $opening_amount;

    $width = (isset($_GET['width']) && intval($_GET['width']) === 80) ? '80mm' : '58mm';
    $fmt = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };
    $opening_display = htmlspecialchars($opening_at, ENT_QUOTES, 'UTF-8');
    $closing_display = htmlspecialchars($closing_at, ENT_QUOTES, 'UTF-8');

    // Construir HTML del cierre (ahora dentro del bloque de cierre)
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Reporte de Cierre</title>';
    $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<style>:root{--pad:8px;--mono:"Courier New",Courier,monospace}html,body{margin:0;padding:0;background:#fff;color:#000;font-family:var(--mono);font-size:12px;font-weight:700}.ticket{width:100%;max-width:320px;padding:var(--pad);box-sizing:border-box}.title{font-weight:900;text-align:center;font-size:14px;margin-bottom:6px}.subtitle{font-weight:800;font-size:12px;text-align:center;margin-bottom:6px}.sep{border-top:1px dashed #000;margin:8px 0}.row{display:flex;justify-content:space-between;align-items:flex-start;margin:4px 0;line-height:1.25}.label{flex:1;font-size:12px}.value{flex:1;text-align:right;font-weight:900}@media print{body{width:'.$width.'}.no-print{display:none}}</style>';
    $html .= '</head><body><div class="ticket">';
    $html .= '<div class="title">Reporte de Cierre</div>';
    $html .= '<div class="subtitle">Caja #'.htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8').'</div>';
    $html .= '<div class="row"><div class="label">Usuario</div><div class="value">'.htmlspecialchars($c['user_name'] ?? ($_SESSION['user']['name'] ?? ''), ENT_QUOTES, 'UTF-8').'</div></div>';
    $html .= '<div class="row"><div class="label">Sucursal</div><div class="value">'.htmlspecialchars($c['branch_name'] ?? '', ENT_QUOTES, 'UTF-8').'</div></div>';
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
    $html .= '<div class="row"><div class="label">Diferencia (cierre - (apertura + ventas - virtuales + gastos))</div><div class="value">'.(($difference>=0?'+':'-').$fmt(abs($difference))).'</div></div>';
    $html .= '<div style="text-align:center;margin-top:10px;font-weight:700">Gracias</div>';
    $html .= '<div class="no-print" style="text-align:center;margin-top:10px"><button onclick="window.print()" style="padding:8px 12px;border-radius:6px;background:#0078d4;color:#fff;border:0;cursor:pointer">Imprimir</button> <button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button></div>';
    $html .= '</div></body></html>';

    echo $html;
    exit;
}
























// Si no fue cierre, continuar con otros bloques (gasto, orden, etc.)

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
                    'name'    => $branch['name'] ?? ($company['name'] ?? ''),
                    'nit'     => $branch['nit'] ?? ($company['nit'] ?? ''),
                    'address' => $branch['address'] ?? ($company['address'] ?? ''),
                    'phone'   => $branch['phone'] ?? ($company['phone'] ?? ''),
                    'legend'  => $branch['invoice_legend'] ?? ($company['legend'] ?? ''),
                    'logo'    => $branch['company_logo'] ?? ($company['logo'] ?? '')
                ];
                $g['branch_name'] = $branch['name'] ?? ($g['branch_name'] ?? $company['name']);
                $g['branch_nit'] = $branch['nit'] ?? ($g['branch_nit'] ?? $company['nit']);
                $g['branch_address'] = $branch['address'] ?? ($g['branch_address'] ?? $company['address']);
                $g['branch_phone'] = $branch['phone'] ?? ($g['branch_phone'] ?? $company['phone']);
                $g['company_logo'] = $branch['company_logo'] ?? ($g['company_logo'] ?? $company['logo']);
                $g['invoice_legend'] = $branch['invoice_legend'] ?? ($g['invoice_legend'] ?? $company['legend']);
            }
        } catch (Exception $e) {
            // ignore errores al recargar branch
        }
    }


    // Aquí continúa la lógica para imprimir el gasto (generar HTML/PDF/plantilla)
    // ...










   
// Construir HTML del gasto (ancho 58mm)
$width = '58mm';
$fmtMoney = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };

$branchDisplayName  = trim($g['branch_name'] ?? '') ?: ($company['name'] ?? '');
$branchDisplayNit   = trim($g['branch_nit'] ?? '') ?: ($company['nit'] ?? '');
$branchDisplayAddr  = trim($g['branch_address'] ?? '') ?: ($company['address'] ?? '');
$branchDisplayPhone = trim($g['branch_phone'] ?? '') ?: ($company['phone'] ?? '');

$html  = '<!doctype html><html><head><meta charset="utf-8"><title>Gasto #'.htmlspecialchars($g['id'] ?? '').'</title>';
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
if (!empty($branchDisplayPhone)) {
    $html .= '<div class="center small" style="color:#000;">Tel: '.htmlspecialchars($branchDisplayPhone).'</div>';
}

$qrContent = "Gasto:".($invoiceData['number'] ?? '')." | Fecha:".($invoiceData['date'] ?? '');
if (!empty($branchIdForQr)) $qrContent .= " | Sucursal:{$branchIdForQr}";
$qrUrl = "https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=" . rawurlencode($qrContent) . "&choe=UTF-8";
$html .= '<div class="center" style="margin-top:6px;"><img src="'.$qrUrl.'" alt="QR" style="width:70px;height:70px;"></div>';

$html .= '<div class="sep"></div>';
$html .= '<div class="small">Gasto ID: <strong>'.htmlspecialchars($g['id'] ?? '').'</strong></div>';
$html .= '<div class="small">Fecha: <strong style="color:#000;">'.htmlspecialchars($invoiceData['date'] ?? '').'</strong></div>';
$html .= '<div class="small">Usuario: <strong style="color:#000;">'.htmlspecialchars($invoiceData['cashier'] ?? '').'</strong></div>';
$html .= '<div class="small">Sucursal: <strong style="color:#000;">'.htmlspecialchars($branchDisplayName).'</strong></div>';
$html .= '<div class="sep"></div>';
$html .= '<div class="small">Descripción:</div>';
$html .= '<div class="small" style="margin-bottom:6px;">'.nl2br(htmlspecialchars($g['descripcion'] ?? '')).'</div>';
$html .= '<div class="small">Referencia: <strong>'.htmlspecialchars($g['referencia'] ?? '-').'</strong></div>';
$html .= '<div class="small">Valor: <strong>'.$fmtMoney($g['valor'] ?? 0).'</strong></div>';
$html .= '<div class="sep"></div>';
$html .= '<div class="small" style="color:#000;">'.htmlspecialchars($g['invoice_legend'] ?? ($company['legend'] ?? '')).'</div>';
$html .= '<div style="height:20px;"></div>';
$html .= '<div class="no-print" style="margin-top:10px;text-align:center;"><button onclick="window.print()" style="padding:10px 14px;border-radius:6px;">Imprimir</button> <button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button></div>';
$html .= '</div></body></html>';

// Guardar copia HTML si la columna existe
try {
    $cols = $pdo->query("SHOW COLUMNS FROM gastos LIKE 'printed_invoice_html'")->fetchAll();
    if (!empty($cols)) {
        $stmtSave = $pdo->prepare("UPDATE gastos SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSave->execute([$html, $gastoId ?? 0]);
    }
} catch (Exception $e) {
    // ignore
}

echo $html;
exit;

/* ---------------------------
   BLOQUE: Impresión de Orden (order_id)
   --------------------------- */
if (($orderId ?? 0) > 0 && ($gastoId ?? 0) === 0 && ($closureId ?? 0) === 0) {
    try {
        // Diferencia: cierre - (apertura + ventas)  + ventas_virtual (si corresponde)
        $opening_amount = floatval($c['opening_amount'] ?? 0);
        $closing_amount = floatval($c['closing_amount'] ?? 0);

        // CORRECCIÓN: incluir ventas virtuales que no estén ya incluidas en orders.total
        $difference = $closing_amount - ($opening_amount + ($sales_total ?? 0)) + ($sales_virtual ?? 0);

        // --- NUEVO: valor a retirar (dejar la apertura en caja) ---
        $withdraw_amount = $closing_amount - $opening_amount;

        // Ancho de impresión
        $width = (isset($_GET['width']) && intval($_GET['width']) === 80) ? '80mm' : '58mm';

        // Preparar displays
        $fmt = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };
        $opening_display = htmlspecialchars($opening_at ?? '');
        $closing_display = htmlspecialchars($closing_at ?? '');
        ?>

    <!doctype html>        
    <html>
    <head>
      <meta charset="utf-8">
      <title>Reporte de Cierre - Caja #<?= htmlspecialchars($c['id']) ?></title>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <style>
        /* Base y ancho para impresora térmica - TODO EN NEGRILLA Y FECHAS EN NEGRO OSCURO */
        :root { --pad:8px; --small:11px; --mono: "Courier New", Courier, monospace; }
        html,body{margin:0;padding:0;background:#fff;color:#000;font-family:var(--mono);font-size:12px;font-weight:700;}
        .ticket{width:100%;max-width:320px;padding:var(--pad);box-sizing:border-box;font-weight:700;}
        @media print { body{width:<?= $width ?>;} .no-print{display:none;} }

        /* Encabezado */
        .title{font-weight:900;text-align:center;font-size:14px;margin-bottom:6px;color:#000;}
        .subtitle{font-weight:800;font-size:12px;text-align:center;margin-bottom:6px;color:#000;}

        /* Líneas y bloques */
        .sep{border-top:1px dashed #000;margin:8px 0;}
        .row{display:flex;justify-content:space-between;align-items:flex-start;margin:4px 0;line-height:1.25;font-weight:700;color:#000;}
        .label{flex:1;color:#000;font-size:12px;font-weight:700;}
        .value{flex:1;text-align:right;font-weight:900;color:#000;}
        /* Asegurar que las fechas y horas también salgan en negrilla y en negro oscuro */
        .small{font-size:var(--small);color:#000;font-weight:900;}
        .muted{font-size:11px;color:#000;margin-top:4px;font-weight:700;}

        /* Fecha/hora en bloque compacto */
        .meta{display:flex;flex-direction:column;gap:2px;font-size:11px;color:#000;margin-top:6px;font-weight:900;}
        .meta .time{color:#000;font-size:10px;font-weight:900;}

        /* Ajustes para textos largos */
        .wrap{white-space:normal;word-break:break-word;font-weight:700;color:#000;}
        .center{ text-align:center;font-weight:800;color:#000; }

        /* Footer */
        .thanks{margin-top:10px;text-align:center;font-size:12px;font-weight:700;color:#000;}
        .no-print{margin-top:10px;text-align:center;}
        button{padding:8px 12px;border-radius:6px;border:0;background:#0078d4;color:#fff;cursor:pointer;font-weight:700;}
      </style>
    </head>
    <body>
      <div class="ticket">
        <div class="title">Reporte de Cierre</div>
        <div class="subtitle">Caja #<?= htmlspecialchars($c['id']) ?></div>

        <div class="row"><div class="label">Usuario</div><div class="value"><?= htmlspecialchars($c['user_name']) ?></div></div>
        <div class="row"><div class="label">Sucursal</div><div class="value"><?= htmlspecialchars($c['branch_name']) ?></div></div>

        <div class="sep"></div>

        <div class="row">
          <div class="label wrap">Apertura registrada</div>
          <div class="value"><?= $fmt($opening_amount) ?></div>
        </div>
        <div class="meta">
          <div class="small"><?= $opening_display ?></div>
        </div>

        <div class="row">
          <div class="label wrap">Cierre contado</div>
          <div class="value"><?= $fmt($closing_amount) ?></div>
        </div>
        <div class="meta">
          <div class="small"><?= $closing_display ?></div>
        </div>

        <div class="sep"></div>

        <div class="row"><div class="label">Valor a retirar</div><div class="value"><?= $fmt($withdraw_amount) ?></div></div>

        <div class="row"><div class="label">Ventas en la sesión</div><div class="value"><?= $fmt($sales_total) ?></div></div>
        <div class="row"><div class="label">Ventas virtuales en la sesión</div><div class="value"><?= $fmt($sales_virtual) ?></div></div>

        <div class="sep"></div>

        <div class="row">
          <div class="label">Diferencia (cierre - (apertura + ventas))</div>
          <div class="value"><?= ($difference >= 0 ? '+' : '-') . $fmt(abs($difference)) ?></div>
        </div>

        <div class="thanks">Gracias</div>

        <div class="no-print">
          <div style="display:flex;gap:8px;justify-content:center;margin-top:10px;">
            <button onclick="window.print()">Imprimir</button>
            <button onclick="window.close()">Cerrar</button>
          </div>
        </div>
      </div>

      <script>
        // Forzar salto de línea y mejorar legibilidad en impresoras térmicas
        (function(){
          try {
            // Ajuste opcional: reducir tamaño si el contenido excede la altura
            var ticket = document.querySelector('.ticket');
            if (ticket && ticket.scrollHeight > 1200) {
              ticket.style.fontSize = '11px';
            }
          } catch(e){}
          // Auto print already present in server-side block; keep this as fallback
          // window.print();
        })();
      </script>
    </body>
    </html>
    <?php
    exit;
}





/* ===========================================================
   FIN BLOQUE ADICIONAL: Reporte de Cierre
   =========================================================== */
// --- Cargar orden si existe ---
if (!isset($cart) || !is_array($cart)) $cart = [];
if (!isset($invoiceData) || !is_array($invoiceData)) $invoiceData = [];

// --- Asegurar variables por defecto ---
$pdo        = $pdo        ?? null;
$company    = $company    ?? ['name'=>'','nit'=>'','address'=>'','phone'=>'','legend'=>'','logo'=>''];
$invoiceData= $invoiceData?? [];
$cart       = $cart       ?? [];
$branchIdForQr = $branchIdForQr ?? null;
$orderId    = isset($orderId) ? intval($orderId) : 0;
$gastoId    = isset($gastoId) ? intval($gastoId) : 0;

// --- Cargar orden si existe ---
$orderRow = false;
if ($orderId > 0 && ($pdo instanceof PDO)) {
    try {
        // Si necesitas conversión de zona, ejecuta SET time_zone en la misma conexión antes
        $stmtO = $pdo->prepare("
            SELECT id, total, created_at, 
                   COALESCE(customer_name,'') AS customer_name, 
                   branch_id, user_id
            FROM orders
            WHERE id = ? LIMIT 1
        ");
        $stmtO->execute([$orderId]);
        $orderRow = $stmtO->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // opcional: error_log($e->getMessage());
        $orderRow = false;
    }
}

if ($orderRow) {
    // Datos principales de la orden
    $invoiceData['number']   = 'O' . intval($orderRow['id']);
    $invoiceData['date']     = $orderRow['created_at'] ?? '';
    $invoiceData['customer'] = $orderRow['customer_name'] ?? ($invoiceData['customer'] ?? '');
    $branchIdForQr           = isset($orderRow['branch_id']) ? intval($orderRow['branch_id']) : $branchIdForQr;

    // --- Carga robusta de items ---
    $items = [];
    if ($pdo instanceof PDO) {
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
            // opcional: error_log($e->getMessage());
            $items = [];
        }
    }

    // Fallback si no hay items
    if (empty($items)) {
        $items[] = [
            'product_name' => "Venta #".intval($orderRow['id']),
            'qty'          => 1,
            'unit_price'   => floatval($orderRow['total']),
            'line_total'   => floatval($orderRow['total']),
            'product_id'   => 0
        ];
    }

    // Normalizar y volcar a $cart
    foreach ($items as $it) {
        $cart[] = [
            'qty'        => intval($it['qty'] ?? 1),
            'code'       => isset($it['product_id']) ? (string)$it['product_id'] : '',
            'name'       => (string)($it['product_name'] ?? 'Producto'),
            'unit_price' => floatval($it['unit_price'] ?? ($it['line_total'] ?? 0)),
            'line_total' => floatval($it['line_total'] ?? (intval($it['qty'] ?? 1) * floatval($it['unit_price'] ?? 0)))
        ];
    }
}






   
// --- Asegurar variables por defecto ---
$pdo = $pdo ?? null;
$company = $company ?? ['name'=>'','nit'=>'','address'=>'','phone'=>'','legend'=>'','logo'=>''];
$invoiceData = $invoiceData ?? [];
$cart = $cart ?? [];
$branchIdForQr = $branchIdForQr ?? null;
$orderId = isset($orderId) ? intval($orderId) : 0;
$gastoId = isset($gastoId) ? intval($gastoId) : 0;

// --- Cargar orden si existe ---
$orderRow = false;
if ($orderId > 0 && ($pdo instanceof PDO)) {
    try {
        // Si necesitas conversión de zona, ejecuta SET time_zone en la misma conexión antes
        $stmtO = $pdo->prepare("
            SELECT id, total, created_at, COALESCE(customer_name,'') AS customer_name, branch_id, user_id
            FROM orders
            WHERE id = ? LIMIT 1
        ");
        $stmtO->execute([$orderId]);
        $orderRow = $stmtO->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // opcional: error_log($e->getMessage());
        $orderRow = false;
    }
}

if ($orderRow) {
    $invoiceData['number']  = 'O' . intval($orderRow['id']);
    $invoiceData['date']    = $orderRow['created_at'] ?? '';
    $invoiceData['customer']= $orderRow['customer_name'] ?: ($invoiceData['customer'] ?? '');
    $branchIdForQr = isset($orderRow['branch_id']) ? intval($orderRow['branch_id']) : $branchIdForQr;

    // --- Carga robusta de items ---
    $items = [];
    if ($pdo instanceof PDO) {
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
            // opcional: error_log($e->getMessage());
            $items = [];
        }
    }

    if (empty($items)) {
        $items[] = [
            'product_name' => "Venta #".intval($orderRow['id']),
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
            'name' => (string)($it['product_name'] ?? 'Producto'),
            'unit_price' => floatval($it['unit_price'] ?? ($it['line_total'] ?? 0)),
            'line_total' => floatval($it['line_total'] ?? (intval($it['qty'] ?? 1) * floatval($it['unit_price'] ?? 0)))
        ];
    }
}

// --- Cargar datos de branch si aplica (unificado) ---
$taxRate = 0.0;
if ($branchIdForQr && ($pdo instanceof PDO)) {
    try {
        $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
        $stmtBranch->execute([$branchIdForQr]);
        $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
        if ($branch) {
            $company = [
                'name'    => $branch['name'] ?? ($company['name'] ?? ''),
                'nit'     => $branch['nit'] ?? ($company['nit'] ?? ''),
                'address' => $branch['address'] ?? ($company['address'] ?? ''),
                'phone'   => $branch['phone'] ?? ($company['phone'] ?? ''),
                'legend'  => $branch['invoice_legend'] ?? ($company['legend'] ?? ''),
                'logo'    => $branch['company_logo'] ?? ($company['logo'] ?? '')
            ];
            $taxRate = isset($branch['tax_rate']) ? floatval($branch['tax_rate']) : 0.0;
        }
    } catch (Exception $e) {
        // opcional: error_log($e->getMessage());
    }
}

// --- Items y totales (render HTML de items) ---
$itemsHtml = '';
$subtotal  = 0.0;
foreach ($cart as $it) {
    $qty = intval($it['qty'] ?? 0);
    $name = htmlspecialchars($it['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $unit = floatval($it['unit_price'] ?? 0);
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

if ($orderId > 0 && ($pdo instanceof PDO)) {
    try {
        $stmtPay = $pdo->prepare("
            SELECT method, amount, cash_received, change_given 
            FROM payments 
            WHERE order_id = ?
        ");
        $stmtPay->execute([$orderId]);
        $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payments as $r) {
            $method = $r['method'] ?? '';
            if ($method === 'efectivo') {
                $cashReceived = floatval($r['cash_received'] ?? $r['amount'] ?? 0);
                $changeGiven  = floatval($r['change_given'] ?? 0);
            } elseif ($method === 'virtual') {
                $virtualReceived += floatval($r['amount'] ?? 0);
            } else {
                // otros métodos: sumar si lo necesitas
            }
        }
    } catch (Exception $e) {
        // opcional: error_log($e->getMessage());
    }
}


// --- QR y HTML final unificado para factura/orden/gasto ---
// Preparar variables por defecto para evitar notices
$pdo = $pdo ?? null;
$company = $company ?? ['name'=>'','nit'=>'','address'=>'','phone'=>'','legend'=>'','logo'=>''];
$invoiceData = $invoiceData ?? [];
$cart = $cart ?? [];
$branchIdForQr = $branchIdForQr ?? null;
$orderId = isset($orderId) ? intval($orderId) : 0;
$sessionId = isset($sessionId) ? intval($sessionId) : 0;
$gastoId = isset($gastoId) ? intval($gastoId) : 0;
$taxRate = isset($taxRate) ? floatval($taxRate) : 0.0;

// --- QR ---
$qrContent = 'Factura:' . ($invoiceData['number'] ?? '') . ' | Fecha:' . ($invoiceData['date'] ?? '');
if (!empty($branchIdForQr)) {
    $qrContent .= ' | Sucursal:' . intval($branchIdForQr);
}
$qrUrl = 'https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=' . rawurlencode($qrContent) . '&choe=UTF-8';

// --- Construir HTML final (plantilla fallback, ancha 58/80 según preferencia) ---
$width = (isset($_GET['width']) && intval($_GET['width']) === 80) ? '80mm' : '58mm';

// Totales ya calculados: $itemsHtml, $subtotal, $tax, $total, $cashReceived, $virtualReceived, $changeGiven
// Asegurar variables numéricas por defecto
$subtotal = isset($subtotal) ? floatval($subtotal) : 0.0;
$tax = isset($tax) ? floatval($tax) : round($subtotal * $taxRate);
$total = isset($total) ? floatval($total) : ($subtotal + $tax);
$cashReceived = isset($cashReceived) ? floatval($cashReceived) : 0.0;
$virtualReceived = isset($virtualReceived) ? floatval($virtualReceived) : 0.0;
$changeGiven = isset($changeGiven) ? floatval($changeGiven) : 0.0;
$itemsHtml = $itemsHtml ?? '';

// Calcular vuelto si no fue provisto
if ($changeGiven <= 0 && ($cashReceived > 0 || $virtualReceived > 0)) {
    $changeGiven = max(0, $cashReceived + $virtualReceived - $total);
}

// Escapes seguros
$company_name = htmlspecialchars($company['name'] ?? '', ENT_QUOTES, 'UTF-8');
$company_nit  = htmlspecialchars($company['nit'] ?? '', ENT_QUOTES, 'UTF-8');
$company_addr = htmlspecialchars($company['address'] ?? '', ENT_QUOTES, 'UTF-8');
$company_phone= htmlspecialchars($company['phone'] ?? '', ENT_QUOTES, 'UTF-8');
$company_legend = htmlspecialchars($company['legend'] ?? '', ENT_QUOTES, 'UTF-8');
$company_logo = trim($company['logo'] ?? '');

// Validar logo (permitir http(s) y data URIs)
$logoTag = '';
if ($company_logo !== '') {
    $candidate = filter_var($company_logo, FILTER_SANITIZE_URL);
    if (preg_match('#^(https?:|data:)#i', $candidate)) {
        $logoTag = '<div class="center"><img src="' . htmlspecialchars($candidate, ENT_QUOTES, 'UTF-8') . '" alt="Logo" class="logo"></div>';
    }
}

// Construcción del HTML
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Factura</title>';
$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
$html .= '<style>
  :root{--pad:6px;--mono:\'Courier New\',Courier,monospace}
  html,body{margin:0;padding:0;background:#fff;color:#000;font-family:var(--mono);font-weight:700}
  .print-area{max-width:320px;padding:var(--pad);box-sizing:border-box}
  .center{text-align:center;font-weight:800;color:#000}
  .small{font-size:11px;font-weight:900;color:#000}
  .tiny{font-size:10px;font-weight:900;color:#000}
  .sep{border-top:1px dashed #000;margin:6px 0}
  .items{width:100%;border-collapse:collapse;margin-top:6px}
  .items td{padding:2px 0;vertical-align:top}
  .total{font-weight:900;font-size:13px}
  .logo{max-width:160px;max-height:60px;margin-bottom:6px;object-fit:contain}
  .qr{width:70px;height:70px}
  .no-print{display:block}
  @media print{body{width:' . $width . '}.no-print{display:none}}
</style>';
$html .= '</head><body><div class="print-area">';

// Encabezado
$html .= $logoTag;
$html .= '<div class="center" style="font-weight:bold;font-size:14px;color:#000;">' . $company_name . '</div>';
if ($company_nit !== '') $html .= '<div class="center small" style="color:#000;">NIT: ' . $company_nit . '</div>';
if ($company_addr !== '') $html .= '<div class="center small" style="color:#000;">' . $company_addr . '</div>';
if ($company_phone !== '') $html .= '<div class="center small" style="color:#000;">Tel: ' . $company_phone . '</div>';
$html .= '<div class="center" style="margin-top:6px;"><img src="' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '" alt="QR" class="qr"></div>';

$html .= '<div class="sep"></div>';
$html .= '<div class="small">Factura: <strong>' . htmlspecialchars($invoiceData['number'] ?? '-', ENT_QUOTES, 'UTF-8') . '</strong></div>';
$html .= '<div class="small">Fecha: <strong style="color:#000;">' . htmlspecialchars($invoiceData['date'] ?? '-', ENT_QUOTES, 'UTF-8') . '</strong></div>';
$html .= '<div class="small">Cajero: <strong style="color:#000;">' . htmlspecialchars($invoiceData['cashier'] ?? '-', ENT_QUOTES, 'UTF-8') . '</strong></div>';
$html .= '<div class="small">Cliente: <strong style="color:#000;">' . htmlspecialchars($invoiceData['customer'] ?? '-', ENT_QUOTES, 'UTF-8') . '</strong></div>';

// Items
$html .= '<table class="items tiny"><tbody>' . $itemsHtml . '</tbody></table>';
$html .= '<div class="sep"></div>';

// Totales y pagos
$html .= '<div class="small">Subtotal: $' . number_format($subtotal, 0, ",", ".") . '</div>';
if ($taxRate > 0) {
    $html .= '<div class="small">IVA: $' . number_format($tax, 0, ",", ".") . '</div>';
}
$html .= '<div class="total">TOTAL: $' . number_format($total, 0, ",", ".") . '</div>';

if ($cashReceived > 0) {
    $html .= '<div class="small">Efectivo recibido: $' . number_format($cashReceived, 0, ",", ".") . '</div>';
}
if ($virtualReceived > 0) {
    $html .= '<div class="small">Pago virtual: $' . number_format($virtualReceived, 0, ",", ".") . '</div>';
}
if ($changeGiven > 0) {
    $html .= '<div class="small">Vuelto: $' . number_format($changeGiven, 0, ",", ".") . '</div>';
}

$html .= '<div class="sep"></div>';
if ($company_legend !== '') {
    $html .= '<div class="tiny" style="color:#000;">' . $company_legend . '</div>';
}
$html .= '<div style="height:20px;"></div>';

// Botones (ocultos al imprimir)
$html .= '<div class="no-print" style="margin-top:10px;text-align:center;">';
$html .= '<button onclick="window.print()" style="padding:10px 14px;border-radius:6px;">Imprimir</button> ';
$html .= '<button onclick="window.close()" style="padding:8px 12px;border-radius:6px;">Cerrar</button>';
$html .= '</div>';

$html .= '</div></body></html>';

// --- Guardar copia HTML según origen (orders o cash_sessions) ---
if ($pdo instanceof PDO) {
    try {
        if ($orderId > 0) {
            $stmtSaveOrder = $pdo->prepare("UPDATE orders SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
            $stmtSaveOrder->execute([$html, $orderId]);
        } elseif ($sessionId > 0) {
            $stmtSave = $pdo->prepare("UPDATE cash_sessions SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
            $stmtSave->execute([$html, $sessionId]);
        } elseif ($gastoId > 0) {
            // Si corresponde, también guardar en gastos
            $stmtSaveG = $pdo->prepare("UPDATE gastos SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
            $stmtSaveG->execute([$html, $gastoId]);
        }
    } catch (Exception $e) {
        // No interrumpir la entrega del HTML por un fallo al guardar
        // Opcional: error_log($e->getMessage());
    }
}

    // Entregar HTML al navegador
echo $html;
exit;

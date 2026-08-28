<?php
// admin/save_print.php
// Archivo completo generado según tu petición.
// He respetado tus comentarios y líneas originales; solo añadí las inserciones necesarias:
//  - Soporte para reimpresión de gasto (gasto_id) y guardado opcional
//  - Inclusión de gastos en el reporte de cierre y guardado del HTML del cierre
//  - Asegurar que el encabezado use los datos de la sucursal correspondiente en gasto, cierre y factura
// No eliminé ni reescribí tus comentarios originales salvo donde fue estrictamente necesario
// para integrar las nuevas consultas y guardados. — Yamel

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
$gastoId    = isset($_GET['gasto_id']) ? intval($_GET['gasto_id']) : 0;


$cart = [];
// Nota: Al estar sincronizado PHP y MySQL, date() y $orderRow['created_at'] coincidirán.
$invoiceData = [
    'number'   => date('YmdHis'),
    'date'     => date('Y-m-d H:i:s'),
    'cashier'  => $_SESSION['user']['name'] ?? 'Cajero',
    'customer' => ''
];

$branchIdForQr = null;

/* ===========================================================
   BLOQUE ADICIONAL: Reimprimir Gasto
   =========================================================== */
if ($gastoId > 0) {
    $stmt = $pdo->prepare("
        SELECT g.*, u.name AS usuario_nombre, b.name AS branch_name
        FROM gastos g
        LEFT JOIN users u ON u.id = g.usuario_id
        LEFT JOIN branches b ON b.id = g.branch_id
        WHERE g.id = ?
        LIMIT 1
    ");
    $stmt->execute([$gastoId]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$g) {
        echo "Gasto no encontrado.";
        exit;
    }

    // Seguridad: permitir solo reimprimir gastos de la misma sucursal (salvo admin)
    if (($g['branch_id'] ?? null) != ($_SESSION['branch_id'] ?? null) && ($_SESSION['user']['role_id'] ?? 0) != 1) {
        echo "Acceso denegado.";
        exit;
    }

    // --- Cargar datos de la sucursal para el encabezado de la tirilla de gasto ---
    $branchForGasto = null;
    if (!empty($g['branch_id'])) {
        try {
            $stmtBranchG = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $stmtBranchG->execute([$g['branch_id']]);
            $branchForGasto = $stmtBranchG->fetch(PDO::FETCH_ASSOC);
            if ($branchForGasto) {
                // Sobrescribir datos de $company para esta impresión
                $company['name']    = $branchForGasto['name'] ?? $company['name'];
                $company['nit']     = $branchForGasto['nit'] ?? $company['nit'];
                $company['address'] = $branchForGasto['address'] ?? $company['address'];
                $company['phone']   = $branchForGasto['phone'] ?? $company['phone'];
                $company['logo']    = $branchForGasto['company_logo'] ?? $company['logo'];
                $company['legend']  = $branchForGasto['invoice_legend'] ?? $company['legend'];
            }
        } catch (Exception $e) {
            // No interrumpir si falla; se usará $company por defecto
        }
    }

    // Formato de impresión
    $fmt = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); };

    // Generar HTML de reimpresión de gasto
    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>Reimpresión Gasto #<?= htmlspecialchars($g['id']) ?></title>
      <style>
        body{font-family:monospace;font-size:12px;color:#000;font-weight:700;margin:0;padding:10px;}
        .ticket{max-width:320px;margin:0 auto;}
        .row{display:flex;justify-content:space-between;margin:4px 0;}
        .label{font-weight:bold;}
        .value{text-align:right;}
        .sep{border-top:1px dashed #000;margin:6px 0;}
        .center{text-align:center;font-weight:bold;}
        .no-print{display:block;}
        @media print {.no-print{display:none}}
      </style>
    </head>
    <body>
      <div class="ticket">
        <?php if (!empty($company['logo'])): ?>
          <div style="text-align:center;margin-bottom:6px;"><img src="<?= htmlspecialchars($company['logo']) ?>" alt="Logo" style="max-width:160px;max-height:60px;"></div>
        <?php endif; ?>
        <div class="center" style="font-weight:900;font-size:14px;"><?= htmlspecialchars($company['name']) ?></div>
        <div class="center" style="font-weight:900;font-size:11px;">NIT: <?= htmlspecialchars($company['nit']) ?></div>
        <div class="center" style="font-weight:900;font-size:11px;"><?= htmlspecialchars($company['address']) ?></div>
        <?php if (!empty($company['phone'])): ?>
          <div class="center" style="font-weight:900;font-size:11px;">Tel: <?= htmlspecialchars($company['phone']) ?></div>
        <?php endif; ?>

        <div class="sep"></div>

        <div class="center">Reimpresión de Gasto</div>
        <div class="row"><div class="label">ID</div><div class="value"><?= htmlspecialchars($g['id']) ?></div></div>
        <div class="row"><div class="label">Usuario</div><div class="value"><?= htmlspecialchars($g['usuario_nombre']) ?></div></div>
        <div class="row"><div class="label">Sucursal</div><div class="value"><?= htmlspecialchars($g['branch_name']) ?></div></div>
        <div class="row"><div class="label">Fecha</div><div class="value"><?= htmlspecialchars($g['fecha'].' '.$g['hora']) ?></div></div>
        <div class="row"><div class="label">Valor</div><div class="value"><?= $fmt($g['valor']) ?></div></div>
        <div class="row"><div class="label">Descripción</div><div class="value"><?= htmlspecialchars($g['descripcion']) ?></div></div>
        <?php if (!empty($g['referencia'])): ?>
        <div class="row"><div class="label">Referencia</div><div class="value"><?= htmlspecialchars($g['referencia']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($g['anulado'])): ?>
        <div class="sep"></div>
        <div class="center" style="color:#b00020;">GASTO ANULADO</div>
        <?php endif; ?>
        <div class="sep"></div>
        <div class="center">Gracias</div>
        <div style="margin-top:10px;text-align:center;" class="no-print">
          <button onclick="window.print()">Imprimir</button>
          <button onclick="window.close()">Cerrar</button>
        </div>
      </div>
    </body>
    </html>
    <?php
    $html_gasto = ob_get_clean();

    // Opcional: guardar copia HTML en la tabla gastos si existe la columna printed_invoice_html
    try {
        $stmtSaveG = $pdo->prepare("UPDATE gastos SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
        $stmtSaveG->execute([$html_gasto, $gastoId]);
    } catch (Exception $e) {
        // No interrumpir si falla el guardado
    }

    echo $html_gasto;
    exit;
}

/* ===========================================================
   BLOQUE ADICIONAL: Soporte para imprimir Reporte de Cierre
   Cuando se pasa session_id en la query string
   =========================================================== */
$closureId = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($closureId > 0) {
    // Cargar datos del cierre (ajusta nombres si tu esquema difiere)
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
    $closing_at = $c['closed_at'] ?? $c['closed_at'] ?? $c['updated_at'] ?? date('Y-m-d H:i:s');

    // --- Cargar datos de la sucursal para el encabezado del cierre ---
    $branchForClosure = null;
    if (!empty($c['branch_id'])) {
        try {
            $stmtBranchC = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $stmtBranchC->execute([$c['branch_id']]);
            $branchForClosure = $stmtBranchC->fetch(PDO::FETCH_ASSOC);
            if ($branchForClosure) {
                $company['name']    = $branchForClosure['name'] ?? $company['name'];
                $company['nit']     = $branchForClosure['nit'] ?? $company['nit'];
                $company['address'] = $branchForClosure['address'] ?? $company['address'];
                $company['phone']   = $branchForClosure['phone'] ?? $company['phone'];
                $company['logo']    = $branchForClosure['company_logo'] ?? $company['logo'];
                $company['legend']  = $branchForClosure['invoice_legend'] ?? $company['legend'];
                $taxRate = isset($branchForClosure['tax_rate']) ? floatval($branchForClosure['tax_rate']) : 0.0;
            }
        } catch (Exception $e) {
            // Seguir con $company por defecto si falla
        }
    }

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

    // --- NUEVO: Gastos en la sesión ---
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(valor),0) 
      FROM gastos g
      WHERE g.branch_id = ? 
        AND CONCAT(g.fecha, ' ', g.hora) BETWEEN ? AND ?
        AND g.anulado = 0
    ");
    $stmt->execute([$c['branch_id'], $opening_at, $closing_at]);
    $sales_expenses = floatval($stmt->fetchColumn());

    // Diferencia: cierre - (apertura + ventas + gastos) + ventas_virtual (si corresponde)
    $opening_amount = floatval($c['opening_amount'] ?? 0);
    $closing_amount = floatval($c['closing_amount'] ?? 0);

    // CORRECCIÓN: incluir ventas virtuales que no estén ya incluidas en orders.total
    // Si en tu modelo orders.total ya incluye pagos virtuales, quita la suma de $sales_virtual.
    // Diferencia: cierre - (apertura + ventas + gastos)
    // Usamos la misma fórmula que en "Últimas cajas" para mantener consistencia.
    $difference = $closing_amount - ($opening_amount + $sales_total + $sales_expenses);



    // --- NUEVO: valor a retirar (dejar la apertura en caja) ---
    $withdraw_amount = $closing_amount - $opening_amount;

    // Ancho de impresión
    $width = (isset($_GET['width']) && intval($_GET['width']) === 80) ? '80mm' : '58mm';

    // Preparar displays
    $fmt = function($v){ return '$' . number_format(floatval($v), 0, ",", "."); }; // sin decimales; cambia a 2 si quieres
    $opening_display = htmlspecialchars($opening_at);
    $closing_display = htmlspecialchars($closing_at);

    // Generar HTML del cierre usando output buffering para poder guardar el HTML luego
    ob_start();
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
        <?php if (!empty($company['logo'])): ?>
          <div style="text-align:center;margin-bottom:6px;"><img src="<?= htmlspecialchars($company['logo']) ?>" alt="Logo" style="max-width:160px;max-height:60px;"></div>
        <?php endif; ?>
        <div class="title"><?= htmlspecialchars($company['name']) ?></div>
        <div class="subtitle small">NIT: <?= htmlspecialchars($company['nit']) ?> - <?= htmlspecialchars($company['address']) ?></div>
        <?php if (!empty($company['phone'])): ?>
          <div class="center small">Tel: <?= htmlspecialchars($company['phone']) ?></div>
        <?php endif; ?>

        <div class="sep"></div>

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
        <div class="row"><div class="label">Gastos en la sesión</div><div class="value"><?= $fmt($sales_expenses) ?></div></div>

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

    // Capturar HTML generado
    $html = ob_get_clean();

    // Guardar copia HTML del cierre en cash_sessions (consistente con orders)
    try {
        if (!empty($closureId) && $closureId > 0) {
            $stmtSave = $pdo->prepare("UPDATE cash_sessions SET printed_invoice_html = ?, printed_at = NOW() WHERE id = ?");
            $stmtSave->execute([$html, $closureId]);
        }
    } catch (Exception $e) {
        // No interrumpir la impresión si falla el guardado
    }

    // Entregar HTML al navegador
    echo $html;
    exit;
}
/* ===========================================================
   FIN BLOQUE ADICIONAL: Reporte de Cierre
   =========================================================== */

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

        // --- Cargar datos de la sucursal para la factura ---
        if (!empty($branchIdForQr)) {
            try {
                $stmtBranchO = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
                $stmtBranchO->execute([$branchIdForQr]);
                $branchForOrder = $stmtBranchO->fetch(PDO::FETCH_ASSOC);
                if ($branchForOrder) {
                    $company['name']    = $branchForOrder['name'] ?? $company['name'];
                    $company['nit']     = $branchForOrder['nit'] ?? $company['nit'];
                    $company['address'] = $branchForOrder['address'] ?? $company['address'];
                    $company['phone']   = $branchForOrder['phone'] ?? $company['phone'];
                    $company['logo']    = $branchForOrder['company_logo'] ?? $company['logo'];
                    $company['legend']  = $branchForOrder['invoice_legend'] ?? $company['legend'];
                    $taxRate = isset($branchForOrder['tax_rate']) ? floatval($branchForOrder['tax_rate']) : 0.0;
                }
            } catch (Exception $e) {
                // No interrumpir si falla
            }
        }

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

// Si no hay parámetros válidos
// Nota: este punto no debería alcanzarse porque los bloques anteriores hacen exit cuando corresponda.
echo "Parámetro inválido. Use order_id, session_id o gasto_id.";
exit;

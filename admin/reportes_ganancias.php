<?php
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1]); // Solo administrador

$pdo = DB::getConnection();

// Sucursal desde sesión
$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? "No seleccionada";

// Filtros
$desde = $_GET['desde'] ?? date("Y-m-d");
$hasta = $_GET['hasta'] ?? date("Y-m-d");

// Consulta principal corregida
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        SUM(oi.quantity) AS total_qty,
        SUM(oi.subtotal) AS total_ingreso,
        
        /* 
           CÁLCULO DEL COSTO REAL CON IVA Y EMBALAJE:
           1. Costo Unitario Base = (cost_initial / packaging_qty)
           2. Costo Unitario + IVA = Costo Base * (1 + (iva_percent / 100))
           3. Costo Total = Costo Unitario + IVA * Cantidad Vendida
        */
        SUM(
            (
                (COALESCE(p.cost_initial, 0) / GREATEST(COALESCE(p.packaging_qty, 1), 1)) * 
                (1 + (COALESCE(p.iva_percent, 0) / 100))
            ) * oi.quantity
        ) AS total_costo,

        /* GANANCIA = Ingreso Total - Costo Total (+IVA) */
        (
            SUM(oi.subtotal) - 
            SUM(
                (
                    (COALESCE(p.cost_initial, 0) / GREATEST(COALESCE(p.packaging_qty, 1), 1)) * 
                    (1 + (COALESCE(p.iva_percent, 0) / 100))
                ) * oi.quantity
            )
        ) AS ganancia,

        /* MARGEN % SOBRE LA VENTA (INGRESO) */
        ROUND(
            (
                SUM(oi.subtotal) - 
                SUM(
                    (
                        (COALESCE(p.cost_initial, 0) / GREATEST(COALESCE(p.packaging_qty, 1), 1)) * 
                        (1 + (COALESCE(p.iva_percent, 0) / 100))
                    ) * oi.quantity
                )
            ) / NULLIF(SUM(oi.subtotal), 0) * 100, 
        2) AS margen

    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.branch_id = ?
      AND (o.status IS NULL OR o.status != 'cancelado') -- Excluir ventas canceladas
    GROUP BY p.id, p.name
    ORDER BY ganancia DESC
");

$stmt->execute([$desde, $hasta, $currentBranchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales
$total_ingreso  = 0;
$total_costo    = 0;
$total_ganancia = 0;

foreach ($rows as $r) {
    $total_ingreso  += (float)$r['total_ingreso'];
    $total_costo    += (float)$r['total_costo'];
    $total_ganancia += (float)$r['ganancia'];
}

// Margen promedio total
$total_margen = ($total_ingreso > 0) ? (($total_ganancia / $total_ingreso) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ganancias</title>
    <link rel="stylesheet" href="../public/css/admin.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f9;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 12px;
        }

        .header-title h2 {
            margin: 0;
            color: #1a1a1a;
        }

        .branch-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Formulario de Filtros */
        .filter-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .filter-form {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #4b5563;
        }

        .form-group input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }

        .form-group input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }

        .btn-filter {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-filter:hover {
            background: #1d4ed8;
        }

        /* Tarjetas de Totales / Casillas */
        .totals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .summary-card .title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-top: 8px;
        }

        .summary-card.positive .value {
            color: #16a34a;
        }

        /* Estilos de Tabla */
        .table-responsive {
            overflow-x: auto;
        }

        table.styled-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 14px;
        }

        table.styled-table thead tr {
            background-color: #f8fafc;
            color: #475569;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        table.styled-table th, 
        table.styled-table td {
            padding: 12px 16px;
        }

        table.styled-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.15s;
        }

        table.styled-table tbody tr:hover {
            background-color: #f1f5f9;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-positive {
            color: #16a34a;
            font-weight: 600;
        }

        .text-negative {
            color: #dc2626;
            font-weight: 600;
        }

        @media print {
            .filter-card { display: none; }
            body { background: #fff; padding: 0; }
            .container { box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="header-title">
        <h2>📊 Reporte de Ganancias</h2>
        <span class="branch-badge">Sucursal: <?= htmlspecialchars($currentBranchName) ?></span>
        <a href="../admin/dashboard.php" class="btn-back">
            ← Ir a la Tienda
        </a>
        <style>
            .btn-back {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                background-color: #f3f4f6;
                color: #374151;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                transition: background-color 0.2s, border-color 0.2s;
            }

            .btn-back:hover {
                background-color: #e5e7eb;
                border-color: #9ca3af;
            }
        </style>
    </div>

    <!-- Filtros de Fecha -->
    <div class="filter-card">
        <form method="GET" class="filter-form">
            <div class="form-group">
                <label for="desde">Desde:</label>
                <input type="date" id="desde" name="desde" value="<?= htmlspecialchars($desde) ?>">
            </div>

            <div class="form-group">
                <label for="hasta">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
            </div>

            <button type="submit" class="btn-filter">Filtrar</button>
        </form>
    </div>

    <!-- Casillas de Resumen (Totales) -->
    <div class="totals-grid">
        <div class="summary-card">
            <div class="title">Total Ingreso</div>
            <div class="value">$<?= number_format($total_ingreso, 2) ?></div>
        </div>

        <div class="summary-card">
            <div class="title">Total Costo (+IVA)</div>
            <div class="value">$<?= number_format($total_costo, 2) ?></div>
        </div>

        <div class="summary-card positive">
            <div class="title">Ganancia Total</div>
            <div class="value">$<?= number_format($total_ganancia, 2) ?></div>
        </div>

        <div class="summary-card">
            <div class="title">Margen % Promedio</div>
            <div class="value"><?= number_format($total_margen, 2) ?>%</div>
        </div>
    </div>

    <!-- Tabla Detallada -->
    <div class="table-responsive">
        <table class="styled-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="text-center">Cant.</th>
                    <th class="text-right">Ingreso</th>
                    <th class="text-right">Costo (+IVA)</th>
                    <th class="text-right">Ganancia</th>
                    <th class="text-right">Margen %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                        <td class="text-center"><?= (int)$r['total_qty'] ?></td>
                        <td class="text-right">$<?= number_format($r['total_ingreso'], 2) ?></td>
                        <td class="text-right">$<?= number_format($r['total_costo'], 2) ?></td>
                        <td class="text-right <?= ($r['ganancia'] >= 0) ? 'text-positive' : 'text-negative' ?>">
                            $<?= number_format($r['ganancia'], 2) ?>
                        </td>
                        <td class="text-right"><?= number_format((float)($r['margen'] ?? 0), 2) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 24px; color: #6b7280;">
                            No se encontraron registros de ventas para el período seleccionado.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr style="font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0;">
                    <td>TOTALES</td>
                    <td class="text-center"><?= array_sum(array_column($rows, 'total_qty')) ?></td>
                    <td class="text-right">$<?= number_format($total_ingreso, 2) ?></td>
                    <td class="text-right">$<?= number_format($total_costo, 2) ?></td>
                    <td class="text-right text-positive">$<?= number_format($total_ganancia, 2) ?></td>
                    <td class="text-right"><?= number_format($total_margen, 2) ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
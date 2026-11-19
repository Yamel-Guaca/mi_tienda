<?php
// admin/reporte_corresponsal.php - Reporte Contable de Recaudos del Corresponsal
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo Administradores / Supervisores (rol 1) acceden a reportes contables completos
require_role([1]);
$pdo = DB::getConnection();

$currentBranchName = $_SESSION['branch_name'] ?? 'Principal';

// Filtros por defecto: mes actual completo
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['date_to'] ?? date('Y-m-d'));
$statusFilter = trim($_GET['status'] ?? 'all'); // all, positive, negative

// Validar formato de fechas
if (!DateTime::createFromFormat('Y-m-d', $dateFrom)) $dateFrom = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $dateTo))   $dateTo = date('Y-m-d');

// Construir consulta con filtros
$where = ["closure_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($statusFilter === 'positive') {
    $where[] = "recaudo >= 0";
} elseif ($statusFilter === 'negative') {
    $where[] = "recaudo < 0";
}

$sqlWhere = implode(' AND ', $where);

// Consultar cierres ordenados cronológicamente
$stmt = $pdo->prepare("SELECT * FROM corresponsal_closures WHERE $sqlWhere ORDER BY closure_date ASC");
$stmt->execute($params);
$closures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las columnas (activas e históricas con datos)
$allColumns = $pdo->query("SELECT * FROM corresponsal_columns ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Mapear valores de plataformas por cierre
$closureValuesMap = [];
if (!empty($closures)) {
    $cIds = array_column($closures, 'id');
    $in = implode(',', array_fill(0, count($cIds), '?'));
    $stmtMap = $pdo->prepare("SELECT closure_id, column_id, amount FROM corresponsal_closure_values WHERE closure_id IN ($in)");
    $stmtMap->execute($cIds);
    foreach ($stmtMap->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $closureValuesMap[$r['closure_id']][$r['column_id']] = (float)$r['amount'];
    }
}

// Calcular KPIs y Totales Contables del Período
$totalRecaudoNeto = 0;
$totalGanancias   = 0;
$totalPerdidas    = 0;
$diasConGanancia  = 0;
$diasConPerdida   = 0;
$totalTransacciones = 0;
$sumaEfectivo     = 0;
$sumaSistema      = 0;
$sumaPlataformasCol = [];

foreach ($closures as $cl) {
    $rec = (float)$cl['recaudo'];
    $totalRecaudoNeto += $rec;
    $totalTransacciones += (int)$cl['transactions_count'];
    $sumaEfectivo += (float)$cl['total_cash'];
    $sumaSistema += (float)$cl['total_system'];

    if ($rec >= 0) {
        $totalGanancias += $rec;
        $diasConGanancia++;
    } else {
        $totalPerdidas += $rec; // valor negativo
        $diasConPerdida++;
    }

    foreach ($allColumns as $col) {
        $cId = (int)$col['id'];
        $sumaPlataformasCol[$cId] = ($sumaPlataformasCol[$cId] ?? 0) + ($closureValuesMap[$cl['id']][$cId] ?? 0);
    }
}

$promedioDiario = count($closures) > 0 ? ($totalRecaudoNeto / count($closures)) : 0;

// ==========================================
// EXPORTAR A EXCEL (CSV) PARA CONTABILIDAD
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "reporte_recaudos_corresponsal_{$dateFrom}_a_{$dateTo}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para que Excel abra tildes y signos $ correctamente
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Encabezados
    $headers = ['FECHA', 'BASE CORRESPONSAL', 'TOTAL EFECTIVO'];
    foreach ($allColumns as $col) {
        $headers[] = $col['name'];
    }
    $headers = array_merge($headers, [
        'TOTAL (EFECTIVO + PLAT)',
        'TOTAL SISTEMA',
        'TOTAL GENERAL',
        'RECAUDO (GANANCIA/PERDIDA)',
        'ESTADO',
        'TRANSACCIONES',
        'OBSERVACIONES'
    ]);
    fputcsv($out, $headers, ';');

    // Filas
    foreach ($closures as $cl) {
        $rec = (float)$cl['recaudo'];
        $row = [
            date('d/m/Y', strtotime($cl['closure_date'])),
            number_format((float)$cl['base_snapshot'], 2, ',', ''),
            number_format((float)$cl['total_cash'], 2, ',', '')
        ];
        foreach ($allColumns as $col) {
            $val = $closureValuesMap[$cl['id']][$col['id']] ?? 0;
            $row[] = number_format($val, 2, ',', '');
        }
        $row[] = number_format((float)$cl['subtotal_cash_platforms'], 2, ',', '');
        $row[] = number_format((float)$cl['total_system'], 2, ',', '');
        $row[] = number_format((float)$cl['grand_total'], 2, ',', '');
        $row[] = number_format($rec, 2, ',', '');
        $row[] = $rec >= 0 ? 'GANANCIA / CUADRADO' : 'DESCUADRE / PERDIDA';
        $row[] = (int)$cl['transactions_count'];
        $row[] = $cl['notes'] ?? '';
        fputcsv($out, $row, ';');
    }

    // Fila de Resumen Final en el CSV
    fputcsv($out, [], ';');
    fputcsv($out, ['RESUMEN CONTABLE DEL PERIODO', "$dateFrom al $dateTo"], ';');
    fputcsv($out, ['TOTAL GANANCIAS (DIAS POSITIVOS)', number_format($totalGanancias, 2, ',', '')], ';');
    fputcsv($out, ['TOTAL DESCUADRES (DIAS NEGATIVOS)', number_format($totalPerdidas, 2, ',', '')], ';');
    fputcsv($out, ['RECAUDO NETO A CONTABILIDAD', number_format($totalRecaudoNeto, 2, ',', '')], ';');
    fputcsv($out, ['TOTAL TRANSACCIONES', $totalTransacciones], ';');

    fclose($out);
    exit;
}

// Datos para gráfico simple en CSS/HTML (últimos 31 días del filtro)
$maxAbsRecaudo = 1;
foreach ($closures as $cl) {
    $absVal = abs((float)$cl['recaudo']);
    if ($absVal > $maxAbsRecaudo) $maxAbsRecaudo = $absVal;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte Contable de Recaudos - Corresponsal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: Arial, Helvetica, sans-serif; background: #f4f6f9; color: #222; margin: 0; padding: 0; }
  header { background: #0B2545; padding: 16px 24px; color: #fff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
  header h2 { margin: 0; font-size: 20px; }
  header a { color: #EEB902; text-decoration: none; font-weight: 600; font-size: 13px; margin-left: 14px; }
  .container { max-width: 1400px; margin: 18px auto; padding: 0 16px; }
  .card { background: #fff; padding: 18px; border-radius: 8px; box-shadow: 0 4px 14px rgba(0,0,0,.06); margin-bottom: 20px; }
  h3 { margin-top: 0; color: #0B2545; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }

  /* Filtros */
  .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
  .filter-group { min-width: 160px; }
  label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; text-transform: uppercase; }
  input[type="date"], select { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; }

  .btn { display: inline-block; padding: 9px 15px; border-radius: 6px; border: 0; cursor: pointer; font-weight: 700; text-decoration: none; font-size: 13px; }
  .btn-primary { background: #0B2545; color: #fff; }
  .btn-success { background: #059669; color: #fff; }
  .btn-outline { background: #e2e8f0; color: #1e293b; font-size: 12px; padding: 6px 10px; }
  .btn-print { background: #475569; color: #fff; }

  /* Tarjetas KPI Contables */
  .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 20px; }
  .kpi-card { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.05); border-left: 5px solid #0B2545; }
  .kpi-card.neto-pos { border-left-color: #059669; background: #f0fdf4; }
  .kpi-card.neto-neg { border-left-color: #dc2626; background: #fef2f2; }
  .kpi-card.ganancia { border-left-color: #10b981; }
  .kpi-card.perdida { border-left-color: #ef4444; }
  .kpi-title { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; }
  .kpi-value { font-size: 22px; font-weight: 800; color: #0B2545; margin: 6px 0 4px 0; }
  .kpi-card.neto-pos .kpi-value, .kpi-card.ganancia .kpi-value { color: #059669; }
  .kpi-card.neto-neg .kpi-value, .kpi-card.perdida .kpi-value { color: #dc2626; }
  .kpi-sub { font-size: 12px; color: #64748b; }

  /* Gráfico visual de barras */
  .chart-wrap { display: flex; align-items: flex-end; gap: 6px; height: 150px; padding: 14px 8px 6px 8px; border-bottom: 2px solid #cbd5e1; overflow-x: auto; }
  .bar-col { flex: 1; min-width: 28px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
  .bar { width: 100%; max-width: 32px; border-radius: 4px 4px 0 0; transition: height .3s; min-height: 4px; }
  .bar.pos { background: #10b981; }
  .bar.neg { background: #ef4444; }
  .bar-lbl { font-size: 10px; color: #475569; margin-top: 4px; writing-mode: vertical-rl; transform: rotate(180deg); height: 42px; }

  /* Tabla Contable */
  .table-responsive { overflow-x: auto; }
  .report-table { width: 100%; border-collapse: collapse; font-size: 13px; white-space: nowrap; }
  .report-table th, .report-table td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: right; }
  .report-table th { background: #0B2545; color: #fff; font-weight: 700; text-align: center; }
  .report-table th.plat-col { background: #1e3a8a; }
  .report-table td:first-child { text-align: center; font-weight: 700; background: #f8fafc; }
  .report-table tr:hover td { background: #f1f5f9; }
  .report-table tfoot td { background: #e2e8f0; font-weight: 800; color: #0B2545; border-top: 2px solid #0B2545; }

  .badge-pos { background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 12px; font-weight: 700; font-size: 11px; }
  .badge-neg { background: #fee2e2; color: #991b1b; padding: 3px 8px; border-radius: 12px; font-weight: 700; font-size: 11px; }
  .text-pos { color: #059669; font-weight: 800; }
  .text-neg { color: #dc2626; font-weight: 800; }

  @media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .card, .kpi-card { box-shadow: none; border: 1px solid #ccc; }
  }
</style>
</head>
<body>

<header class="no-print">
  <div>
    <h2>Reporte Contable de Recaudos - Corresponsal</h2>
    <small style="opacity:.85;">Sucursal: <?= htmlspecialchars($currentBranchName) ?> | Control de Utilidad y Descuadres</small>
  </div>
  <div>
    <a href="cierre_corresponsal.php">📝 Ir a Cierre Diario</a>
    <a href="/mi_tienda/admin/dashboard.php">← Volver al Dashboard</a>
  </div>
</header>

<div class="container">

  <!-- BARRA DE FILTROS Y ATAJOS DE FECHA -->
  <div class="card no-print">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
      <strong style="color:#0B2545;">Filtrar Período Contable:</strong>
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="?date_from=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline">Hoy</a>
        <a href="?date_from=<?= date('Y-m-d', strtotime('monday this week')) ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline">Esta Semana</a>
        <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-t') ?>" class="btn btn-outline">Este Mes</a>
        <a href="?date_from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&date_to=<?= date('Y-m-t', strtotime('last day of last month')) ?>" class="btn btn-outline">Mes Anterior</a>
      </div>
    </div>

    <form method="GET" class="filter-bar">
      <div class="filter-group">
        <label>Desde:</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required>
      </div>
      <div class="filter-group">
        <label>Hasta:</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" required>
      </div>
      <div class="filter-group">
        <label>Resultado del Día:</label>
        <select name="status">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos los días</option>
          <option value="positive" <?= $statusFilter === 'positive' ? 'selected' : '' ?>>Solo Ganancias / Cuadrados (+)</option>
          <option value="negative" <?= $statusFilter === 'negative' ? 'selected' : '' ?>>Solo Descuadres / Pérdidas (-)</option>
        </select>
      </div>
      <div>
        <button type="submit" class="btn btn-primary">🔍 Generar Reporte</button>
        <a href="?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status=<?= urlencode($statusFilter) ?>&export=csv" class="btn btn-success">📥 Descargar Excel (CSV)</a>
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Imprimir</button>
      </div>
    </form>
  </div>

  <!-- TARJETAS RESUMEN PARA CONTABILIDAD -->
  <div class="kpi-grid">
    <div class="kpi-card <?= $totalRecaudoNeto >= 0 ? 'neto-pos' : 'neto-neg' ?>">
      <div class="kpi-title">Recaudo Neto del Período</div>
      <div class="kpi-value">
        <?= $totalRecaudoNeto < 0 ? '$(' . number_format(abs($totalRecaudoNeto), 0, ',', '.') . ')' : '$' . number_format($totalRecaudoNeto, 0, ',', '.') ?>
      </div>
      <div class="kpi-sub">Dinero real ganado/perdido tras descontar base</div>
    </div>

    <div class="kpi-card ganancia">
      <div class="kpi-title">Total Ingresos / Ganancias</div>
      <div class="kpi-value">$<?= number_format($totalGanancias, 0, ',', '.') ?></div>
      <div class="kpi-sub">En <?= $diasConGanancia ?> día(s) positivos</div>
    </div>

    <div class="kpi-card perdida">
      <div class="kpi-title">Total Descuadres / Pérdidas</div>
      <div class="kpi-value">
        <?= $totalPerdidas < 0 ? '$(' . number_format(abs($totalPerdidas), 0, ',', '.') . ')' : '$0' ?>
      </div>
      <div class="kpi-sub">En <?= $diasConPerdida ?> día(s) negativos</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Promedio Diario de Recaudo</div>
      <div class="kpi-value">
        <?= $promedioDiario < 0 ? '$(' . number_format(abs($promedioDiario), 0, ',', '.') . ')' : '$' . number_format($promedioDiario, 0, ',', '.') ?>
      </div>
      <div class="kpi-sub">Sobre <?= count($closures) ?> cierre(s) registrados</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Operaciones Realizadas</div>
      <div class="kpi-value"><?= number_format($totalTransacciones, 0, ',', '.') ?></div>
      <div class="kpi-sub">Transacciones acumuladas en el rango</div>
    </div>
  </div>

  <!-- GRÁFICO RÁPIDO DE COMPORTAMIENTO DIARIO -->
  <?php if (!empty($closures)): ?>
    <div class="card no-print">
      <h3>📈 Comportamiento Diario del Recaudo (<?= date('d/m/Y', strtotime($dateFrom)) ?> al <?= date('d/m/Y', strtotime($dateTo)) ?>)</h3>
      <div class="chart-wrap">
        <?php foreach ($closures as $cl): 
          $rec = (float)$cl['recaudo'];
          $pct = min(100, max(6, round((abs($rec) / $maxAbsRecaudo) * 100)));
          $labelMoney = $rec < 0 ? '$(' . number_format(abs($rec), 0, ',', '.') . ')' : '$' . number_format($rec, 0, ',', '.');
        ?>
          <div class="bar-col" title="<?= date('d/m/Y', strtotime($cl['closure_date'])) ?>: <?= $labelMoney ?>">
            <div class="bar <?= $rec >= 0 ? 'pos' : 'neg' ?>" style="height: <?= $pct ?>%;"></div>
            <div class="bar-lbl"><?= date('d/m', strtotime($cl['closure_date'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- TABLA DETALLADA CONTABLE -->
  <div class="card">
    <h3>🧾 Detalle Diario de Recaudos (<?= date('d/m/Y', strtotime($dateFrom)) ?> al <?= date('d/m/Y', strtotime($dateTo)) ?>)</h3>
    <div class="table-responsive">
      <table class="report-table">
        <thead>
          <tr>
            <th>FECHA</th>
            <th>BASE DEL DÍA</th>
            <th>TOTAL EFECTIVO</th>
            <?php foreach ($allColumns as $col): ?>
              <th class="plat-col"><?= htmlspecialchars($col['name']) ?></th>
            <?php endforeach; ?>
            <th>TOTAL SISTEMA</th>
            <th>TOTAL GENERAL</th>
            <th>RECAUDO DEL DÍA</th>
            <th>RECAUDO ACUMULADO</th>
            <th>ESTADO</th>
            <th>TRANS.</th>
            <th>OBSERVACIONES</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($closures)): ?>
            <tr>
              <td colspan="<?= count($allColumns) + 10 ?>" style="text-align:center; padding:24px; color:#64748b;">
                No se encontraron cierres en las fechas seleccionadas.
              </td>
            </tr>
          <?php else: ?>
            <?php 
              $acumulado = 0;
              foreach ($closures as $cl): 
                $clId = (int)$cl['id'];
                $rec = (float)$cl['recaudo'];
                $acumulado += $rec;
            ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($cl['closure_date'])) ?></td>
                <td>$<?= number_format((float)$cl['base_snapshot'], 0, ',', '.') ?></td>
                <td>$<?= number_format((float)$cl['total_cash'], 0, ',', '.') ?></td>

                <?php foreach ($allColumns as $col): 
                  $val = $closureValuesMap[$clId][$col['id']] ?? 0;
                ?>
                  <td>$<?= number_format($val, 0, ',', '.') ?></td>
                <?php endforeach; ?>

                <td>$<?= number_format((float)$cl['total_system'], 0, ',', '.') ?></td>
                <td style="font-weight:700;">$<?= number_format((float)$cl['grand_total'], 0, ',', '.') ?></td>
                <td class="<?= $rec >= 0 ? 'text-pos' : 'text-neg' ?>">
                  <?= $rec < 0 ? '$(' . number_format(abs($rec), 0, ',', '.') . ')' : '$' . number_format($rec, 0, ',', '.') ?>
                </td>
                <td style="font-weight:700; color:#0B2545;">
                  <?= $acumulado < 0 ? '$(' . number_format(abs($acumulado), 0, ',', '.') . ')' : '$' . number_format($acumulado, 0, ',', '.') ?>
                </td>
                <td style="text-align:center;">
                  <?php if ($rec >= 0): ?>
                    <span class="badge-pos">UTILIDAD</span>
                  <?php else: ?>
                    <span class="badge-neg">DESCUADRE</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;"><?= (int)$cl['transactions_count'] ?></td>
                <td style="text-align:left; white-space:normal; min-width:160px; font-size:12px; color:#475569;">
                  <?= htmlspecialchars($cl['notes'] ?? '') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($closures)): ?>
          <tfoot>
            <tr>
              <td style="text-align:center;">TOTALES</td>
              <td>—</td>
              <td>$<?= number_format($sumaEfectivo, 0, ',', '.') ?></td>
              <?php foreach ($allColumns as $col): ?>
                <td>$<?= number_format($sumaPlataformasCol[$col['id']] ?? 0, 0, ',', '.') ?></td>
              <?php endforeach; ?>
              <td>$<?= number_format($sumaSistema, 0, ',', '.') ?></td>
              <td>—</td>
              <td class="<?= $totalRecaudoNeto >= 0 ? 'text-pos' : 'text-neg' ?>" style="font-size:15px;">
                <?= $totalRecaudoNeto < 0 ? '$(' . number_format(abs($totalRecaudoNeto), 0, ',', '.') . ')' : '$' . number_format($totalRecaudoNeto, 0, ',', '.') ?>
              </td>
              <td>—</td>
              <td>—</td>
              <td style="text-align:center;"><?= number_format($totalTransacciones, 0, ',', '.') ?></td>
              <td></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

</div>
</body>
</html>
<?php
// admin/invoices_list.php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Validar sesión iniciada
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo = DB::getConnection();

// Datos del usuario logueado
$userRoleId   = (int)($_SESSION['user']['role_id'] ?? 0);
$userBranchId = (int)($_SESSION['branch_id'] ?? $_SESSION['user']['branch_id'] ?? 0);

// --- Paginación y Filtros ---
$limit = 20; // Límite de facturas por página
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');
$search    = trim($_GET['search'] ?? '');

// Construir cláusula WHERE
$where  = [];
$params = [];

// RESTRICCIÓN POR SUCURSAL:
// Si el usuario NO es Administrador (1) ni Supervisor (2), solo ve las facturas de su sucursal
if (!in_array($userRoleId, [1, 2])) {
    $where[] = "branch_id = :user_branch_id";
    $params[':user_branch_id'] = $userBranchId;
}

if (!empty($startDate)) {
    $where[] = "DATE(created_at) >= :start_date";
    $params[':start_date'] = $startDate;
}

if (!empty($endDate)) {
    $where[] = "DATE(created_at) <= :end_date";
    $params[':end_date'] = $endDate;
}

if (!empty($search)) {
    // Extraer solo los números por si el usuario escribe prefijos como "O12786", "#12786", "F-12786"
    $cleanId = preg_replace('/[^0-9]/', '', $search);

    if (!empty($cleanId) && is_numeric($cleanId)) {
        $where[] = "(id = :clean_id OR customer_name LIKE :search_like)";
        $params[':clean_id'] = (int)$cleanId;
        $params[':search_like'] = '%' . $search . '%';
    } else {
        $where[] = "customer_name LIKE :search_like";
        $params[':search_like'] = '%' . $search . '%';
    }
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// 1. Contar total de registros filtrados
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders $whereClause");
foreach ($params as $key => $val) {
    if ($key === ':clean_id' || $key === ':user_branch_id') {
        $countStmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $countStmt->bindValue($key, $val);
    }
}
$countStmt->execute();
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalOrders / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// 2. Consultar facturas de la página actual
$sql = "SELECT id, customer_name, total, status, created_at, branch_id 
        FROM orders 
        $whereClause 
        ORDER BY created_at DESC, id DESC 
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $val) {
    if ($key === ':clean_id' || $key === ':user_branch_id') {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val);
    }
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Función auxiliar para mantener todos los filtros activos al cambiar de página
function buildPageUrl(int $pageNum, string $start, string $end, string $search): string {
    $query = ['page' => $pageNum];
    if (!empty($start))  $query['start_date'] = $start;
    if (!empty($end))    $query['end_date']   = $end;
    if (!empty($search)) $query['search']     = $search;
    return '?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Listado de Facturas</title>
<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8fafc; color: #333; }
.container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
h1 { margin-top: 0; color: #1e293b; }
table { border-collapse: collapse; width: 100%; margin-top: 15px; }
th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; font-size: 14px; }
th { background: #f1f5f9; font-weight: 600; }
.btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; margin-right: 4px; display: inline-block; font-size: 13px; font-weight: 600; }
.btn-danger { background: #d9534f; color: #fff; }
.btn-danger:hover { background: #c9302c; }
.btn-secondary { background: #0078d4; color: #fff; }
.btn-secondary:hover { background: #005a9e; }
.btn-filter { background: #28a745; color: #fff; border: 0; cursor: pointer; padding: 8px 12px; border-radius: 4px; font-weight: 600; }
.btn-clear { background: #6c757d; color: #fff; }

/* Filtros y Paginación */
.filter-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; margin-bottom: 15px; display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: 13px; font-weight: 600; color: #475569; }
.filter-group input { padding: 7px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 14px; }

.pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; }
.pagination-nav { display: flex; gap: 8px; align-items: center; }
.btn-page { background: #0078d4; color: #fff; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-weight: bold; }
.btn-page.disabled { background: #cbd5e1; color: #94a3b8; pointer-events: none; cursor: default; }
.page-info { font-size: 14px; color: #475569; font-weight: 600; }
</style>
</head>
<body>

<div class="container">
<h1>📋 Listado de Facturas</h1>

<p class="small">Enlaces rápidos: <a href="pos.php" class="btn btn-clear">⬅ Volver al POS</a></p>

<!-- Formulario de Búsqueda y Filtros -->
<form method="GET" class="filter-box">
    <div class="filter-group" style="flex: 1; min-width: 200px;">
        <label for="search">N° Factura / Cliente:</label>
        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Ej: O12786, 12786 o nombre cliente" autocomplete="off">
    </div>
    <div class="filter-group">
        <label for="start_date">Fecha Desde:</label>
        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
    </div>
    <div class="filter-group">
        <label for="end_date">Fecha Hasta:</label>
        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
    </div>
    <button type="submit" class="btn-filter">🔍 Buscar</button>

    <?php if (!empty($startDate) || !empty($endDate) || !empty($search)): ?>
        <a href="invoices_list.php" class="btn btn-clear">Limpiar filtros</a>
    <?php endif; ?>
</form>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Cliente</th>
      <th>Total</th>
      <th>Estado</th>
      <th>Fecha</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!empty($orders)): ?>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td><strong>#<?= htmlspecialchars($order['id']) ?></strong></td>
          <td><?= htmlspecialchars($order['customer_name'] ?? 'Cliente General') ?></td>
          <td>$<?= number_format((float)$order['total'], 2, ",", ".") ?></td>
          <td>
            <?php if ($order['status'] === 'cancelado'): ?>
              <span style="color:#d9534f; font-weight:bold;">Anulada</span>
            <?php else: ?>
              <span style="color:#28a745; font-weight:bold;"><?= htmlspecialchars(ucfirst($order['status'])) ?></span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($order['created_at']) ?></td>
          <td>
            <!-- Botón de reimpresión -->
            <a href="invoice_print.php?order_id=<?= $order['id'] ?>" 
               target="_blank" 
               class="btn btn-secondary">Reimprimir</a>

            <!-- Botón de anular solo para Administrador (1) o Supervisor (2) -->
            <?php if ($order['status'] !== 'cancelado'): ?>
              <?php if (in_array($userRoleId, [1, 2])): ?>
                <a href="invoice_delete.php?order_id=<?= $order['id'] ?>"
                   onclick="return confirm('¿Seguro que deseas anular la factura #<?= $order['id'] ?>?');"
                   class="btn btn-danger">Anular</a>
              <?php else: ?>
                <span style="color:gray; font-size:12px;">Sin permisos</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr>
        <td colspan="6" style="text-align:center; color:#64748b; padding:20px;">
          No se encontraron facturas que coincidan con la búsqueda o con su sucursal asignada.
        </td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- Paginación con Flechas -->
<div class="pagination">
    <div class="page-info">
        Mostrando página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (Total: <?= $totalOrders ?> facturas)
    </div>
    <div class="pagination-nav">
        <!-- Flecha Izquierda (Anterior) -->
        <a href="<?= buildPageUrl($page - 1, $startDate, $endDate, $search) ?>" 
           class="btn-page <?= $page <= 1 ? 'disabled' : '' ?>">
            ◀ Anterior
        </a>

        <!-- Flecha Derecha (Siguiente) -->
        <a href="<?= buildPageUrl($page + 1, $startDate, $endDate, $search) ?>" 
           class="btn-page <?= $page >= $totalPages ? 'disabled' : '' ?>">
            Siguiente ▶
        </a>
    </div>
</div>

</div>

</body>
</html>
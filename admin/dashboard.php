<?php
// admin/dashboard.php

// Mostrar errores solo en desarrollo; quítalo en producción
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ NO usar session_start() aquí (ya está en auth_functions.php)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Validar sesión iniciada
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// ✅ Restringir acceso: solo Administrador (role_id = 1)
if ((int)($_SESSION['user']['role_id'] ?? 0) !== 1) {
    die("Acceso denegado. Solo el Administrador puede entrar al Dashboard.");
}

// Conexión PDO
$pdo = DB::getConnection();

/* ============================================================
   1. CARGAR SUCURSALES Y MANEJAR SELECCIÓN
============================================================ */

// Obtener sucursales activas
$stmt = $pdo->query("SELECT id, name FROM branches WHERE active = 1 ORDER BY name");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sucursal actual en sesión
$currentBranchId = $_SESSION['branch_id'] ?? null;

// Si el admin cambia la sucursal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['branch_id'])) {
    $branchId = (int) $_POST['branch_id'];

    // Validar que exista
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
    $stmt->execute([$branchId]);

    if ($stmt->fetch()) {
        $_SESSION['branch_id'] = $branchId;
        $currentBranchId = $branchId;
    }

    header("Location: dashboard.php");
    exit;
}

// Si no hay sucursal seleccionada, asignar la primera
if ($currentBranchId === null && !empty($branches)) {
    $_SESSION['branch_id'] = $branches[0]['id'];
    $currentBranchId = $branches[0]['id'];
}

/* ============================================================
   2. CONSULTAS PARA ESTADÍSTICAS (NO SE TOCAN)
============================================================ */

try {
    // Total de productos
    $stmt = $pdo->query("SELECT COUNT(*) AS total_products FROM products WHERE active = 1");
    $totalProducts = $stmt->fetchColumn();

    // Stock total (suma de inventory.quantity)
    $stmt = $pdo->query("SELECT IFNULL(SUM(quantity),0) AS total_stock FROM inventory");
    $totalStock = $stmt->fetchColumn();

    // Ventas del día
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(total),0) AS sales_today FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelado'");
    $stmt->execute();
    $salesToday = $stmt->fetchColumn();

    // Pedidos del día
    $stmt = $pdo->prepare("SELECT COUNT(*) AS orders_today FROM orders WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $ordersToday = $stmt->fetchColumn();

    // Top productos
    $stmt = $pdo->query("
        SELECT p.id, p.name, p.sku, IFNULL(SUM(oi.quantity),0) AS sold_qty, IFNULL(SUM(oi.subtotal),0) AS revenue
        FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.status != 'cancelado'
        GROUP BY p.id
        ORDER BY sold_qty DESC
        LIMIT 5
    ");
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard Administrador - Mi Tienda</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 0; background:#f4f6f8; color:#222; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .container { padding:20px; max-width:1100px; margin:0 auto; }
    .cards { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
    .card { background:#fff; padding:16px; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08); flex:1 1 220px; min-width:180px; }
    .card h3 { margin:0 0 8px 0; font-size:14px; color:#666; }
    .card p { margin:0; font-size:20px; font-weight:700; }
    table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
    th, td { padding:10px 12px; border-bottom:1px solid #eee; text-align:left; }
    th { background:#fafafa; font-weight:600; }
    .small { font-size:13px; color:#666; }
    .top-actions { display:flex; gap:8px; align-items:center; }
    a.button { background:#0a6; color:#fff; padding:8px 12px; border-radius:6px; text-decoration:none; }
    .error { background:#ffe6e6; color:#900; padding:10px; border-radius:6px; margin-bottom:12px; }
    .branch-select { margin-right:20px; }
    .branch-select select { padding:6px; border-radius:6px; border:1px solid #ccc; }
  </style>
</head>
<body>
  <header>
    <div>
      <strong>Mi Tienda</strong> — Panel Administrador      
    </div>

    <div class="top-actions">

      <!-- ✅ SELECTOR DE SUCURSAL -->
      <form method="POST" action="dashboard.php" class="branch-select">
        <select name="branch_id" onchange="this.form.submit()">
          <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= ($b['id'] == $currentBranchId) ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <span class="small">Usuario: <strong><?=htmlspecialchars($_SESSION['user']['email'] ?? '---')?></strong></span>
      <a class="button" href="/mi_tienda/admin/login.php?logout=1" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Cerrar sesión</a>
      <form id="logout-form" method="post" action="/mi_tienda/admin/login.php" style="display:none;">
        <input type="hidden" name="logout" value="1">
      </form>
    </div>
  </header>

  <!-- ✅ ENLACES RÁPIDOS COMPLETOS -->
    <p class="small" style="margin-top:12px;">Enlaces rápidos: 
      <a href="/mi_tienda/admin/productos.php">Gestión de productos</a> · 
      <a href="/mi_tienda/admin/inventario.php">Inventario</a> · 
     <a href="/mi_tienda/admin/inventario_bajo.php">Inventario bajo</a> · 
     <a href="/mi_tienda/admin/kardex.php">Kardex</a> · 
     <a href="/mi_tienda/admin/categorias.php">Categorías</a> · 
      <a href="/mi_tienda/admin/subcategorias.php">Subcategorías</a> · 
      <a href="/mi_tienda/admin/pedidos.php">Pedidos</a> · 
      <a href="/mi_tienda/admin/cierres_diarios.php">Cierres diarios</a> · 
      <a href="/mi_tienda/admin/usuarios.php">Usuarios</a> · 
      <a href="/mi_tienda/admin/sucursales.php">Sucursales</a> · 
      <a href="/mi_tienda/admin/caja.php">Caja</a> · 
      <a href="/mi_tienda/admin/reportes.php">Reportes</a> · 
      <a href="/mi_tienda/admin/pos.php">POS</a> ·
      <a href="/mi_tienda/admin/reportes_ganancias.php" class="btn">Reporte de Ganancias</a>       
      <a href="/mi_tienda/admin/invoices_list.php" class="btn">Borrar Factura</a>
      <a href="/mi_tienda/admin/branch_visibility.php" class="btn">Visibilidad de Categorías y Subcategorías por Sucursal</a>
      
    </p>

  <div class="container">
    <?php if (!empty($errorMsg)): ?>
      <div class="error">Error al cargar estadísticas: <?=htmlspecialchars($errorMsg)?></div>
    <?php endif; ?>

    <div class="cards">
      <div class="card">
        <h3>Total de productos activos</h3>
        <p><?=intval($totalProducts)?></p>
      </div>
      <div class="card">
        <h3>Stock total</h3>
        <p><?=intval($totalStock)?></p>
      </div>
      <div class="card">
        <h3>Ventas hoy</h3>
        <p>$<?=number_format((float)$salesToday,2,'.',',')?></p>
      </div>
      <div class="card">
        <h3>Pedidos hoy</h3>
        <p><?=intval($ordersToday)?></p>
      </div>
    </div>

    <h2>Productos más vendidos</h2>
    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th>SKU</th>
          <th>Cantidad vendida</th>
          <th>Ingresos</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($topProducts)): ?>
          <?php foreach ($topProducts as $p): ?>
            <tr>
              <td><?=htmlspecialchars($p['name'])?></td>
              <td><?=htmlspecialchars($p['sku'])?></td>
              <td><?=intval($p['sold_qty'])?></td>
              <td>$<?=number_format((float)$p['revenue'],2,'.',',')?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4" class="small">No hay datos de ventas aún.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

  </div>
</body>
</html>

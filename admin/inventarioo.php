<?php
// admin/inventario.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo Administrador (1) o Auxiliar (4) pueden entrar
require_role([1, 4]);

$pdo = DB::getConnection();

$msg = "";
$action = $_GET['action'] ?? null;

/* ============================================================
   1. OBTENER SUCURSAL Y USUARIO (Soporte Selección Dinámica)
============================================================ */

// Permite seleccionar la sucursal desde la URL o usa la de la sesión activa por defecto
$currentBranchId = intval($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 0);

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// Consultar nombre de la sucursal seleccionada y cargar lista completa para el selector
$stmtB = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
$stmtB->execute([$currentBranchId]);
$currentBranchName = $stmtB->fetchColumn() ?: ($_SESSION['branch_name'] ?? "Seleccionada");

$allBranches = $pdo->query("SELECT id, name FROM branches WHERE active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Lectura flexible del ID del usuario logueado
$user_id = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;

if (!$user_id) {
    die("Error de sesión: No se identificó el usuario actual.");
}

/* ============================================================
   2. OBTENER PRODUCTOS PARA LOS SELECTS
============================================================ */

$products = $pdo->query("
    SELECT id, name, sku 
    FROM products 
    WHERE active = 1 
    ORDER BY name
")->fetchAll();

/* ============================================================
   3. PROCESAR ENTRADA DE INVENTARIO
============================================================ */

if ($action === 'entrada' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $qty        = intval($_POST['quantity'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if ($product_id > 0 && $qty > 0) {
        try {
            $pdo->beginTransaction();

            // Validar que el producto existe
            $stmtProductCheck = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmtProductCheck->execute([$product_id]);
            
            if ($stmtProductCheck->fetchColumn()) {
                // Actualizar o Insertar Stock
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (product_id, branch_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $stmt->execute([$product_id, $currentBranchId, $qty]);

                // Registrar Historial de Movimiento
                $stmtMov = $pdo->prepare("
                    INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                    VALUES (?, ?, ?, 'entrada', ?, ?)
                ");
                $stmtMov->execute([$product_id, $currentBranchId, $user_id, $qty, $note]);

                $pdo->commit();
                $msg = "Entrada registrada correctamente.";
            } else {
                $pdo->rollBack();
                $msg = "El producto no existe.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error al registrar la entrada: " . $e->getMessage();
        }
    } else {
        $msg = "Todos los campos obligatorios deben ser válidos.";
    }
}

/* ============================================================
   4. PROCESAR SALIDA DE INVENTARIO
============================================================ */

if ($action === 'salida' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $qty        = intval($_POST['quantity'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if ($product_id > 0 && $qty > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id=? AND branch_id=?");
            $stmt->execute([$product_id, $currentBranchId]);
            $stock = $stmt->fetchColumn();

            if ($stock === false || $stock < $qty) {
                $pdo->rollBack();
                $msg = "No hay suficiente stock disponible para realizar la salida.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET quantity = quantity - ? 
                    WHERE product_id=? AND branch_id=?
                ");
                $stmt->execute([$qty, $product_id, $currentBranchId]);

                $stmtMov = $pdo->prepare("
                    INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                    VALUES (?, ?, ?, 'salida', ?, ?)
                ");
                $stmtMov->execute([$product_id, $currentBranchId, $user_id, $qty, $note]);

                $pdo->commit();
                $msg = "Salida registrada correctamente.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error al registrar la salida: " . $e->getMessage();
        }
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

/* ============================================================
   4.b PROCESAR ACTUALIZACIÓN MASIVA DE INVENTARIO
============================================================ */

if ($action === 'actualizarMasivo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantities = $_POST['quantities'] ?? [];
    if (!is_array($quantities) || count($quantities) === 0) {
        $msg = "No se recibieron cantidades para actualizar.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmtProductCheck = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmtCheck        = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND branch_id = ?");
            $updateStmt       = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND branch_id = ?");
            $insertStmt       = $pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, ?)");
            $movementStmt     = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                VALUES (?, ?, ?, 'ajuste', ?, ?)
            ");

            foreach ($quantities as $productId => $newQty) {
                $productId = intval($productId);
                $newQty = intval($newQty);
                if ($productId <= 0 || $newQty < 0) continue;

                $stmtProductCheck->execute([$productId]);
                if (!$stmtProductCheck->fetchColumn()) {
                    continue;
                }

                $stmtCheck->execute([$productId, $currentBranchId]);
                $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    if (intval($row['quantity']) !== $newQty) {
                        $updateStmt->execute([$newQty, $row['id'], $currentBranchId]);
                        $note = "Ajuste masivo por usuario ID: " . $user_id;
                        $movementStmt->execute([$productId, $currentBranchId, $user_id, $newQty, $note]);
                    }
                } else {
                    $insertStmt->execute([$productId, $currentBranchId, $newQty]);
                    $note = "Registro inicial masivo por usuario ID: " . $user_id;
                    $movementStmt->execute([$productId, $currentBranchId, $user_id, $newQty, $note]);
                }
            }

            $pdo->commit();
            $msg = "Inventario actualizado masivamente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error al actualizar inventario masivamente: " . $e->getMessage();
        }
    }
}

/* ============================================================
   5. PAGINACIÓN Y CONSULTA DE INVENTARIO (CON BÚSQUEDA PHP)
============================================================ */

$search = trim($_GET['search'] ?? '');

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Construir condición de búsqueda SQL
$whereClause = "WHERE p.active = 1";
$paramsCount = [];
$paramsSelect = [$currentBranchId];

if ($search !== '') {
    $whereClause .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $paramsCount[] = "%$search%";
    $paramsCount[] = "%$search%";
    $paramsSelect[] = "%$search%";
    $paramsSelect[] = "%$search%";
}

// Conteo total con filtro
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM products p $whereClause");
$stmtCount->execute($paramsCount);
$total = intval($stmtCount->fetchColumn());
$totalPages = max(1, (int) ceil($total / $perPage));

// Consulta principal
$sql = "
    SELECT 
        p.id AS product_id,
        p.name AS product_name,
        p.sku,
        COALESCE(i.id, 0) AS inventory_id,
        COALESCE(i.quantity, 0) AS quantity,
        COALESCE(i.min_quantity, 0) AS min_quantity
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ?
    $whereClause
    ORDER BY p.name
    LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);

$paramIndex = 1;
foreach ($paramsSelect as $val) {
    $stmt->bindValue($paramIndex++, $val, PDO::PARAM_STR);
}
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

$stmt->execute();
$inventory = $stmt->fetchAll();

function buildPageUrl($pageNumber) {
    $params = $_GET;
    $params['page'] = $pageNumber;
    return htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($params));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-between; align-items:center; }
    header h2 { margin:0; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; text-align:left; }
    .msg { background:#dff0d8; color:#3c763d; padding:10px; border-radius:6px; margin-bottom:10px; font-weight:bold; }
    .error { background:#f8d7da; color:#721c24; padding:10px; border-radius:6px; margin-bottom:10px; }
    form { margin-top:20px; background:#fafafa; padding:15px; border-radius:6px; }
    input, select, textarea { width:100%; padding:8px; margin-bottom:10px; box-sizing:border-box; }
    button { padding:8px 12px; background:#0a6; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    h3 { margin-top:30px; }
    .top-actions { display:flex; gap:10px; margin-bottom:10px; align-items:center; flex-wrap:wrap; }
    .top-actions button, .top-actions .small-btn { padding:8px 10px; }
    .small-btn { background:#eee; border:1px solid #ccc; cursor:pointer; border-radius:4px; text-decoration:none; color:#333; display:inline-block; }
    .editable-input { width:100%; box-sizing:border-box; padding:6px; }
    nav.pager { margin-top:12px; }
    nav.pager a { margin-right:8px; text-decoration:none; color:#0a6; }
    nav.pager a.current { font-weight:bold; color:#000; }
    .table-scroll { max-height:520px; overflow:auto; border:1px solid #eee; padding:6px; border-radius:6px; }
    .branch-selector { background:#fff; color:#333; font-weight:bold; padding:6px 12px; border-radius:4px; border:1px solid #ccc; width:auto; margin:0; }
</style>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet" />
</head>
<body>

<header>
    <h2>Inventario — Sucursal: <?= htmlspecialchars($currentBranchName) ?></h2>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="top-actions">
  <!-- Selector Dinámico de Sucursal -->
  <form method="GET" style="margin:0; background:none; padding:0; display:inline-flex; align-items:center; gap:5px;">
      <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
      <label style="font-weight:bold;">Sucursal:</label>
      <select name="branch_id" onchange="this.form.submit()" class="branch-selector">
          <?php foreach ($allBranches as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $b['id'] == $currentBranchId ? 'selected' : '' ?>>
                  <?= htmlspecialchars($b['name']) ?>
              </option>
          <?php endforeach; ?>
      </select>
  </form>

  <!-- Buscador en tiempo real por Servidor (GET) -->
  <form method="GET" style="margin:0; background:none; padding:0; flex:1; display:flex; gap:5px;">
      <input type="hidden" name="branch_id" value="<?= $currentBranchId ?>">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar producto o SKU en todo el inventario..." style="margin:0;">
      <button type="submit">Buscar</button>
      <?php if ($search !== ''): ?>
          <a href="/mi_tienda/admin/inventario.php?branch_id=<?= $currentBranchId ?>" class="small-btn">Limpiar</a>
      <?php endif; ?>
  </form>

  <button type="button" onclick="exportTableToCSV('inventario_<?= date('Ymd_His') ?>.csv')">Descargar Inventario</button>
</div>

<h3>Registrar Entrada de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=entrada&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>">
    <label for="entrada-product">Producto</label>
    <select id="entrada-product" name="product_id" required>
        <option value="">Seleccione producto</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
        <?php endforeach; ?>
    </select>

    <input type="number" name="quantity" placeholder="Cantidad" min="1" required>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>

    <button type="submit">Registrar Entrada</button>
</form>

<h3>Registrar Salida de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=salida&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>">
    <label for="salida-product">Producto</label>
    <select id="salida-product" name="product_id" required>
        <option value="">Seleccione producto</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
        <?php endforeach; ?>
    </select>

    <input type="number" name="quantity" placeholder="Cantidad" min="1" required>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>

    <button type="submit">Registrar Salida</button>
</form>

<h3>Inventario Actual (Catálogo completo)</h3>

<form method="POST" action="/mi_tienda/admin/inventario.php?action=actualizarMasivo&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>" id="massUpdateForm">

<div class="table-scroll">
<table id="inventoryTable">
    <thead>
        <tr>
            <th>Producto</th>
            <th>SKU</th>
            <th>Cantidad</th>
            <th>Mínimo</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($inventory)): ?>
        <tr>
            <td colspan="4" style="text-align:center;">No se encontraron productos que coincidan con la búsqueda.</td>
        </tr>
        <?php else: ?>
            <?php foreach ($inventory as $i): ?>
            <tr>
                <td><?= htmlspecialchars($i['product_name']) ?></td>
                <td><?= htmlspecialchars($i['sku']) ?></td>
                <td>
                    <input class="editable-input" type="number" name="quantities[<?= intval($i['product_id']) ?>]" 
                           value="<?= intval($i['quantity']) ?>" min="0" step="1">
                </td>
                <td><?= intval($i['min_quantity']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>

<div style="margin-top:12px;">
    <button type="submit">Guardar cambios masivos</button>
</div>
</form>

<?php if ($totalPages > 1): ?>
  <nav class="pager" aria-label="Paginación inventario">
    <?php if ($page > 1): ?>
      <a href="<?= buildPageUrl($page - 1) ?>" class="small-btn">« Anterior</a>
    <?php endif; ?>

    <?php
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
      if ($start > 1) {
          echo '<a href="' . buildPageUrl(1) . '">1</a>';
          if ($start > 2) echo ' ... ';
      }
      for ($p = $start; $p <= $end; $p++):
    ?>
      <a href="<?= buildPageUrl($p) ?>" class="<?= $p == $page ? 'current' : '' ?>"><?= $p ?></a>
    <?php endfor;
      if ($end < $totalPages) {
          if ($end < $totalPages - 1) echo ' ... ';
          echo '<a href="' . buildPageUrl($totalPages) . '">' . $totalPages . '</a>';
      }
    ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= buildPageUrl($page + 1) ?>" class="small-btn">Siguiente »</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>

<script>
  $(document).ready(function() {
    $('#entrada-product, #salida-product').select2({
      placeholder: "Buscar producto...",
      width: '100%'
    });

    $("#massUpdateForm").on("submit", function(e) {
      if (!confirm("¿Deseas guardar los cambios masivos en el inventario? Esta acción registrará ajustes para cada producto modificado.")) {
        e.preventDefault();
      }
    });
  });

  function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("#inventoryTable tr");
    for (var i = 0; i < rows.length; i++) {
      var row = [], cols = rows[i].querySelectorAll("td, th");
      for (var j = 0; j < cols.length; j++) {
        var text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/,/g, "");
        row.push('"' + text.trim() + '"');
      }
      csv.push(row.join(","));
    }
    var csvFile = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
  }
</script>

</body>
</html>
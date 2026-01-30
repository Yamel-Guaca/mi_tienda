<?php
// admin/inventario.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (1) o Auxiliar (4) pueden entrar
require_role([1, 4]);

$pdo = DB::getConnection();

$msg = "";
$action = $_GET['action'] ?? null;

/* ============================================================
   1. OBTENER SUCURSAL SELECCIONADA
============================================================ */

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}
if (!$currentBranchName) {
    $currentBranchName = "Seleccionada";
}

/* ============================================================
   2. OBTENER PRODUCTOS
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
    $product_id = intval($_POST['product_id']);
    $qty        = intval($_POST['quantity']);
    $note       = trim($_POST['note']);

    if ($product_id && $qty > 0) {
        // Validar que el producto existe
        $stmtProductCheck = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmtProductCheck->execute([$product_id]);
        if ($stmtProductCheck->fetchColumn()) {
            $stmt = $pdo->prepare("
                INSERT INTO inventory (product_id, branch_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmt->execute([$product_id, $currentBranchId, $qty]);

            $stmt = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                VALUES (?, ?, ?, 'entrada', ?, ?)
            ");
            $stmt->execute([$product_id, $currentBranchId, $_SESSION['user']['id'], $qty, $note]);

            $msg = "Entrada registrada correctamente.";
        } else {
            $msg = "El producto no existe.";
        }
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

/* ============================================================
   4. PROCESAR SALIDA DE INVENTARIO
============================================================ */

if ($action === 'salida' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id']);
    $qty        = intval($_POST['quantity']);
    $note       = trim($_POST['note']);

    if ($product_id && $qty > 0) {
        $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id=? AND branch_id=?");
        $stmt->execute([$product_id, $currentBranchId]);
        $stock = $stmt->fetchColumn();

        if ($stock === false || $stock < $qty) {
            $msg = "No hay suficiente stock para realizar la salida.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = quantity - ? 
                WHERE product_id=? AND branch_id=?
            ");
            $stmt->execute([$qty, $product_id, $currentBranchId]);

            $stmt = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                VALUES (?, ?, ?, 'salida', ?, ?)
            ");
            $stmt->execute([$product_id, $currentBranchId, $_SESSION['user']['id'], $qty, $note]);

            $msg = "Salida registrada correctamente.";
        }
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

/* ============================================================
   4.b PROCESAR ACTUALIZACIÓN MASIVA DE INVENTARIO (Opción B)
============================================================ */

if ($action === 'actualizarMasivo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantities = $_POST['quantities'] ?? [];
    if (!is_array($quantities) || count($quantities) === 0) {
        $msg = "No se recibieron cantidades para actualizar.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmtProductCheck = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmtCheck = $pdo->prepare("SELECT id FROM inventory WHERE product_id = ? AND branch_id = ?");
            $updateStmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND branch_id = ?");
            $insertStmt = $pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, ?)");
            $movementStmt = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note)
                VALUES (?, ?, ?, 'ajuste', ?, ?)
            ");

            foreach ($quantities as $productId => $newQty) {
                $productId = intval($productId);
                $newQty = intval($newQty);
                if ($productId <= 0 || $newQty < 0) continue;

                // Validar que el producto existe
                $stmtProductCheck->execute([$productId]);
                if (!$stmtProductCheck->fetchColumn()) {
                    continue; // saltar si no existe
                }

                // Verificar si ya existe fila en inventory
                $stmtCheck->execute([$productId, $currentBranchId]);
                $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $updateStmt->execute([$newQty, $row['id'], $currentBranchId]);
                } else {
                    $insertStmt->execute([$productId, $currentBranchId, $newQty]);
                }

                $note = "Ajuste masivo por usuario " . ($_SESSION['user']['id'] ?? '0');
                $movementStmt->execute([$productId, $currentBranchId, $_SESSION['user']['id'], $newQty, $note]);
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
   PAGINACIÓN (servidor) - Opción B
============================================================ */

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE active = 1");
$stmtCount->execute();
$total = intval($stmtCount->fetchColumn());
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT 
        p.id AS product_id,
        p.name AS product_name,
        p.sku,
        COALESCE(i.id, 0) AS inventory_id,
        COALESCE(i.quantity, 0) AS quantity,
        COALESCE(i.min_quantity, 0) AS min_quantity
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ?
    WHERE p.active = 1
    ORDER BY p.name
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $currentBranchId, PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
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
    header { background:#0a6; color:#fff; padding:12px 20px; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; text-align:left; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
    .error { background:#f8d7da; padding:10px; border-radius    .error { background:#f8d7da; padding:10px; border-radius:6px; margin-bottom:10px; }
    form { margin-top:20px; background:#fafafa; padding:15px; border-radius:6px; }
    input, select, textarea { width:100%; padding:8px; margin-bottom:10px; box-sizing:border-box; }
    button { padding:8px 12px; background:#0a6; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    h3 { margin-top:30px; }
    .top-actions { display:flex; gap:10px; margin-bottom:10px; align-items:center; }
    .top-actions button, .top-actions .small-btn { padding:8px 10px; }
    .small-btn { background:#eee; border:1px solid #ccc; cursor:pointer; border-radius:4px; }
    input[type="number"].inline-qty { width:120px; }
    .editable-input { width:100%; box-sizing:border-box; padding:6px; }
    nav.pager { margin-top:12px; }
    nav.pager a { margin-right:8px; text-decoration:none; color:#0a6; }
    nav.pager a.current { font-weight:bold; color:#000; }
    .table-scroll { max-height:520px; overflow:auto; border:1px solid #eee; padding:6px; border-radius:6px; }
</style>
<!-- ✅ Select2 CSS -->
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
  <!-- Buscador global -->
  <input type="text" id="searchInventory" placeholder="Buscar producto o SKU..." style="flex:1; padding:8px;">
  <!-- Botón exportar CSV -->
  <button type="button" onclick="exportTableToCSV('inventario_<?= date('Ymd_His') ?>.csv')">Descargar Inventario</button>
  <!-- Botón para limpiar filtro -->
  <button type="button" class="small-btn" id="clearFilter">Limpiar</button>
</div>

<h3>Registrar Entrada de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=entrada">
    <label for="entrada-product">Producto</label>
    <select id="entrada-product" name="product_id" required>
        <option value="">Seleccione producto</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
        <?php endforeach; ?>
    </select>

    <input type="number" name="quantity" placeholder="Cantidad" min="1" required>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>

    <button>Registrar Entrada</button>
</form>

<h3>Registrar Salida de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=salida">
    <label for="salida-product">Producto</label>
    <select id="salida-product" name="product_id" required>
        <option value="">Seleccione producto</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
        <?php endforeach; ?>
    </select>

    <input type="number" name="quantity" placeholder="Cantidad" min="1" required>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>

    <button>Registrar Salida</button>
</form>

<h3>Inventario Actual (Catálogo completo)</h3>

<!-- Formulario para edición masiva -->
<form method="POST" action="/mi_tienda/admin/inventario.php?action=actualizarMasivo" id="massUpdateForm">

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
        <?php foreach ($inventory as $i): ?>
        <tr>
            <td><?= htmlspecialchars($i['product_name']) ?></td>
            <td><?= htmlspecialchars($i['sku']) ?></td>
            <td>
                <!-- Usamos product_id como clave para facilitar inserciones si no existe inventory -->
                <input class="editable-input" type="number" name="quantities[<?= intval($i['product_id']) ?>]" 
                       value="<?= intval($i['quantity']) ?>" min="0" step="1">
            </td>
            <td><?= intval($i['min_quantity']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<div style="margin-top:12px;">
    <button type="submit">Guardar cambios masivos</button>
</div>
</form>

<!-- PAGINACIÓN -->
<?php if ($totalPages > 1): ?>
  <nav class="pager" aria-label="Paginación inventario">
    <?php if ($page > 1): ?>
      <a href="<?= buildPageUrl($page - 1) ?>" class="small-btn">« Anterior</a>
    <?php endif; ?>

    <?php
      // Mostrar un rango razonable de páginas (ej: -2..+2)
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

<!-- ✅ jQuery y Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>

<script>
  $(document).ready(function() {
    // activar Select2 en selects de entrada/salida
    $('#entrada-product, #salida-product').select2({
      placeholder: "Buscar producto...",
      width: '100%'
    });

    // Buscador en la tabla (filtrado por texto)
    $("#searchInventory").on("keyup", function() {
      var value = $(this).val().toLowerCase();
      $("#inventoryTable tbody tr").filter(function() {
        $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
      });
    });

    // Limpiar filtro
    $("#clearFilter").on("click", function() {
      $("#searchInventory").val('').trigger('keyup');
    });

    // Confirmación antes de enviar actualización masiva (opcional)
    $("#massUpdateForm").on("submit", function(e) {
      if (!confirm("¿Deseas guardar los cambios masivos en el inventario? Esta acción registrará ajustes para cada producto modificado.")) {
        e.preventDefault();
      }
    });
  });

  // Función para exportar tabla a CSV (cliente)
  function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("#inventoryTable tr");
    for (var i = 0; i < rows.length; i++) {
      var row = [], cols = rows[i].querySelectorAll("td, th");
      for (var j = 0; j < cols.length; j++) {
        // Reemplazar comas internas y saltos de línea
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

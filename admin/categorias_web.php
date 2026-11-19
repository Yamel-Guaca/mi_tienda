<?php
// admin/categorias_web.php - Gestión de Categorías del Catálogo Web y Asignación de Productos
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1]);
$pdo = DB::getConnection();

$msg = '';
$error = '';
$selectedCatId = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;

// ==========================================
// 1. CREAR / ACTUALIZAR CATEGORÍA WEB
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_web_category'])) {
    $catId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name === '') {
        $error = 'El nombre de la categoría web es obligatorio.';
    } else {
        try {
            if ($catId > 0) {
                $stmt = $pdo->prepare("UPDATE web_categories SET name = ?, sort_order = ?, active = ? WHERE id = ?");
                $stmt->execute([$name, $sortOrder, $active, $catId]);
                $msg = 'Categoría web actualizada correctamente.';
                $selectedCatId = $catId;
            } else {
                $stmt = $pdo->prepare("INSERT INTO web_categories (name, sort_order, active) VALUES (?, ?, ?)");
                $stmt->execute([$name, $sortOrder, $active]);
                $selectedCatId = (int)$pdo->lastInsertId();
                $msg = 'Categoría web creada correctamente.';
            }
        } catch (Exception $e) {
            $error = 'Error al guardar la categoría: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 2. ELIMINAR CATEGORÍA WEB
// ==========================================
if (isset($_GET['delete_cat'])) {
    $delId = (int)$_GET['delete_cat'];
    if ($delId > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM web_categories WHERE id = ?");
            $stmt->execute([$delId]);
            $msg = 'Categoría web eliminada.';
            if ($selectedCatId === $delId) $selectedCatId = 0;
        } catch (Exception $e) {
            $error = 'Error al eliminar categoría: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 3. GUARDAR ASIGNACIÓN DE PRODUCTOS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments'])) {
    $targetCatId = (int)($_POST['web_category_id'] ?? 0);
    $assignedProducts = $_POST['product_ids'] ?? [];

    if ($targetCatId > 0) {
        try {
            $pdo->beginTransaction();
            $stmtDel = $pdo->prepare("DELETE FROM web_category_products WHERE web_category_id = ?");
            $stmtDel->execute([$targetCatId]);

            if (is_array($assignedProducts) && count($assignedProducts) > 0) {
                $stmtIns = $pdo->prepare("INSERT INTO web_category_products (web_category_id, product_id) VALUES (?, ?)");
                foreach ($assignedProducts as $pId) {
                    $pidInt = (int)$pId;
                    if ($pidInt > 0) {
                        $stmtIns->execute([$targetCatId, $pidInt]);
                    }
                }
            }
            $pdo->commit();
            $selectedCatId = $targetCatId;
            $msg = 'Productos asignados correctamente a la categoría web.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error al asignar productos: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 4. CONSULTAR DATOS PARA LA VISTA
// ==========================================
$webCategories = $pdo->query("
    SELECT wc.*, 
           (SELECT COUNT(*) FROM web_category_products wcp WHERE wcp.web_category_id = wc.id) AS product_count
    FROM web_categories wc 
    ORDER BY wc.sort_order ASC, wc.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($selectedCatId === 0 && !empty($webCategories)) {
    $selectedCatId = (int)$webCategories[0]['id'];
}

$editingCategory = null;
if (isset($_GET['edit_cat'])) {
    $editId = (int)$_GET['edit_cat'];
    foreach ($webCategories as $wc) {
        if ((int)$wc['id'] === $editId) {
            $editingCategory = $wc;
            break;
        }
    }
}

// Todos los productos activos y visibles para la web
$allProducts = $pdo->query("
    SELECT p.id, p.name, p.sku, COALESCE(p.price_unit, p.price, 0) AS price,
           c.name AS internal_cat, s.name AS internal_sub,
           (SELECT filename FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1) AS main_image
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    WHERE p.active = 1 AND p.visible_web = 1
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// IDs de productos ya asignados a la categoría seleccionada
$assignedIds = [];
if ($selectedCatId > 0) {
    $stmtAssigned = $pdo->prepare("SELECT product_id FROM web_category_products WHERE web_category_id = ?");
    $stmtAssigned->execute([$selectedCatId]);
    $assignedIds = array_map('intval', $stmtAssigned->fetchAll(PDO::FETCH_COLUMN));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Admin - Categorías Catálogo Web</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; background: #f4f6f9; color: #222; margin: 0; padding: 0; }
  header { background: #0B2545; padding: 18px 24px; color: #fff; }
  header h2 { margin: 0; font-size: 20px; }
  header a { color: #EEB902; text-decoration: none; font-weight: 600; }
  .container { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
  .msg { background: #e6ffed; border: 1px solid #b7f0c6; padding: 12px; margin-bottom: 16px; border-radius: 6px; color: #0a6; font-weight: 600; }
  .err { background: #ffe6e6; border: 1px solid #f5b7b1; padding: 12px; margin-bottom: 16px; border-radius: 6px; color: #c0392b; font-weight: 600; }
  .grid-layout { display: grid; grid-template-columns: 360px 1fr; gap: 20px; align-items: start; }
  .card { background: #fff; padding: 18px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.05); }
  h3 { margin-top: 0; color: #0B2545; border-bottom: 1px solid #eee; padding-bottom: 10px; }
  label { display: block; margin-top: 10px; font-weight: 600; font-size: 13px; }
  input[type="text"], input[type="number"], input[type="search"] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; margin-top: 4px; box-sizing: border-box; }
  .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; border: 0; cursor: pointer; font-weight: 600; text-decoration: none; font-size: 13px; }
  .btn-primary { background: #0B2545; color: #fff; }
  .btn-success { background: #0b5; color: #fff; }
  .btn-warning { background: #f39c12; color: #fff; padding: 4px 8px; font-size: 12px; }
  .btn-danger { background: #e74c3c; color: #fff; padding: 4px 8px; font-size: 12px; }
  .btn-secondary { background: #6c757d; color: #fff; }
  .cat-list { list-style: none; padding: 0; margin: 14px 0 0 0; }
  .cat-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 8px; background: #fafafa; }
  .cat-list li.active { border-color: #0B2545; background: #eef4fc; }
  .cat-link { text-decoration: none; color: #0B2545; font-weight: 700; flex: 1; }
  .badge { background: #EEB902; color: #001529; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 6px; }
  .product-list { max-height: 560px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; margin-top: 12px; }
  .product-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
  .product-row:hover { background: #f8fafc; }
  .product-row.checked { background: #eff6ff; }
  .product-row img { width: 44px; height: 44px; object-fit: cover; border-radius: 6px; background: #eee; }
  .product-info { flex: 1; }
  .product-info strong { display: block; font-size: 14px; color: #1e293b; }
  .product-info span { font-size: 12px; color: #64748b; }
  @media (max-width: 850px) { .grid-layout { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<header>
  <h2>Categorías del Catálogo Web</h2>
  <p style="margin: 8px 0 0 0; font-size: 13px;">
    <a href="/mi_tienda/admin/dashboard.php">← Volver al Dashboard</a> | 
    <a href="/mi_tienda/admin/productos.php">Gestión de Productos</a> | 
    <a href="/mi_tienda/catalogo.php" target="_blank">Ver Tienda Web ↗</a>
  </p>
</header>

<div class="container">
  <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="grid-layout">
    <!-- PANEL IZQUIERDO: CREAR / LISTAR CATEGORÍAS WEB -->
    <div class="card">
      <h3><?= $editingCategory ? 'Editar Categoría Web' : 'Nueva Categoría Web' ?></h3>
      <form method="POST" action="categorias_web.php<?= $selectedCatId ? '?cat_id='.$selectedCatId : '' ?>">
        <input type="hidden" name="save_web_category" value="1">
        <input type="hidden" name="id" value="<?= $editingCategory ? (int)$editingCategory['id'] : 0 ?>">

        <label>Nombre de la categoría (visible en la web):</label>
        <input type="text" name="name" required placeholder="Ej: Vasos Desechables, Bolsas, Ofertas..." value="<?= htmlspecialchars($editingCategory['name'] ?? '') ?>">

        <label>Orden de aparición (menor sale primero):</label>
        <input type="number" name="sort_order" value="<?= (int)($editingCategory['sort_order'] ?? 0) ?>">

        <label style="display:flex; align-items:center; gap:8px; margin-top:12px; cursor:pointer;">
          <input type="checkbox" name="active" value="1" <?= (!$editingCategory || !empty($editingCategory['active'])) ? 'checked' : '' ?>>
          Visible en la barra de la tienda
        </label>

        <div style="margin-top: 14px; display: flex; gap: 8px;">
          <button type="submit" class="btn btn-primary"><?= $editingCategory ? 'Actualizar' : 'Crear Categoría' ?></button>
          <?php if ($editingCategory): ?>
            <a href="categorias_web.php?cat_id=<?= $selectedCatId ?>" class="btn btn-secondary">Cancelar</a>
          <?php endif; ?>
        </div>
      </form>

      <h3 style="margin-top: 24px;">Mis Categorías Web</h3>
      <?php if (empty($webCategories)): ?>
        <p style="font-size: 13px; color: #666;">Aún no has creado categorías para la web.</p>
      <?php else: ?>
        <ul class="cat-list">
          <?php foreach ($webCategories as $wc): $wcId = (int)$wc['id']; ?>
            <li class="<?= $selectedCatId === $wcId ? 'active' : '' ?>">
              <a href="categorias_web.php?cat_id=<?= $wcId ?>" class="cat-link">
                <?= htmlspecialchars($wc['name']) ?>
                <span class="badge"><?= (int)$wc['product_count'] ?></span>
                <?php if (empty($wc['active'])): ?><small style="color:#c0392b;">(Oculta)</small><?php endif; ?>
              </a>
              <div style="display:flex; gap:4px;">
                <a href="categorias_web.php?cat_id=<?= $wcId ?>&edit_cat=<?= $wcId ?>" class="btn btn-warning">Editar</a>
                <a href="categorias_web.php?delete_cat=<?= $wcId ?>" class="btn btn-danger" onclick="return confirm('¿Eliminar esta categoría web? Los productos no se borrarán.')">✕</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- PANEL DERECHO: ASIGNAR PRODUCTOS A LA CATEGORÍA SELECCIONADA -->
    <div class="card">
      <?php
      $currentCatName = '';
      foreach ($webCategories as $wc) {
          if ((int)$wc['id'] === $selectedCatId) {
              $currentCatName = $wc['name'];
              break;
          }
      }
      ?>
      <?php if ($selectedCatId > 0 && $currentCatName !== ''): ?>
        <h3>Asignar productos a: <span style="color:#0b5;"><?= htmlspecialchars($currentCatName) ?></span></h3>
        <p style="font-size: 13px; color: #555; margin-top: -4px;">
          Marca los productos que deseas mostrar cuando el cliente seleccione esta categoría en la tienda web.
        </p>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
          <input type="search" id="filterProductsInput" placeholder="Filtrar productos por nombre, SKU o categoría interna..." style="flex:1; min-width:220px;">
          <button type="button" class="btn btn-secondary" onclick="toggleOnlyChecked()">Ver solo marcados</button>
        </div>

        <form method="POST" action="categorias_web.php?cat_id=<?= $selectedCatId ?>">
          <input type="hidden" name="save_assignments" value="1">
          <input type="hidden" name="web_category_id" value="<?= $selectedCatId ?>">

          <div class="product-list" id="productListContainer">
            <?php foreach ($allProducts as $prod): 
              $pId = (int)$prod['id'];
              $isChecked = in_array($pId, $assignedIds, true);
              $imgSrc = !empty($prod['main_image']) ? '/mi_tienda/uploads/products/' . htmlspecialchars($prod['main_image']) : 'https://picsum.photos/seed/noimg/100/100';
              $searchText = strtolower($prod['name'] . ' ' . $prod['sku'] . ' ' . ($prod['internal_cat'] ?? '') . ' ' . ($prod['internal_sub'] ?? ''));
            ?>
              <label class="product-row <?= $isChecked ? 'checked' : '' ?>" data-search="<?= htmlspecialchars($searchText) ?>">
                <input type="checkbox" name="product_ids[]" value="<?= $pId ?>" <?= $isChecked ? 'checked' : '' ?> onchange="this.closest('.product-row').classList.toggle('checked', this.checked); updateSelectedCounter();">
                <img src="<?= $imgSrc ?>" alt="">
                <div class="product-info">
                  <strong><?= htmlspecialchars($prod['name']) ?> <small style="color:#64748b;">(SKU: <?= htmlspecialchars($prod['sku']) ?>)</small></strong>
                  <span>Interno: <?= htmlspecialchars($prod['internal_cat'] ?? '-') ?> / <?= htmlspecialchars($prod['internal_sub'] ?? '-') ?> • Precio base: $<?= number_format((float)$prod['price'], 2) ?></span>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: center;">
            <span id="selectedCounter" style="font-size: 13px; font-weight: 700; color: #0B2545;">
              <?= count($assignedIds) ?> producto(s) seleccionado(s)
            </span>
            <button type="submit" class="btn btn-success" style="padding: 10px 20px; font-size: 14px;">Guardar Asignación</button>
          </div>
        </form>
      <?php else: ?>
        <h3>Asignar productos</h3>
        <p style="color: #666;">Crea primero una categoría web en el panel izquierdo para comenzar a asignarle productos.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const searchInput = document.getElementById('filterProductsInput');
let onlyCheckedMode = false;

if (searchInput) {
  searchInput.addEventListener('input', filterRows);
}

function toggleOnlyChecked() {
  onlyCheckedMode = !onlyCheckedMode;
  filterRows();
}

function filterRows() {
  const q = (searchInput?.value || '').toLowerCase().trim();
  document.querySelectorAll('.product-row').forEach(row => {
    const text = row.dataset.search || '';
    const isChecked = row.querySelector('input[type="checkbox"]').checked;
    const matchesText = !q || text.includes(q);
    const matchesCheck = !onlyCheckedMode || isChecked;
    row.style.display = (matchesText && matchesCheck) ? 'flex' : 'none';
  });
}

function updateSelectedCounter() {
  const total = document.querySelectorAll('.product-row input[type="checkbox"]:checked').length;
  const el = document.getElementById('selectedCounter');
  if (el) el.textContent = total + ' producto(s) seleccionado(s)';
}
</script>
</body>
</html>
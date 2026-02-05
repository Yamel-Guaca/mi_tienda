<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo Cajero o Administrador
require_role([1, 3]);

$pdo = DB::getConnection();

// ✅ Ajuste de hora local: definimos Bogotá
date_default_timezone_set('America/Bogota');

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ✅ Procesar venta
$action = $_GET['action'] ?? null;
if ($action === "sell" && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
    $branch_id = $currentBranchId;

    if (!empty($items)) {
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, branch_id, total, status) VALUES (?, ?, 0, 'completado')");
        $stmt->execute([$_SESSION['user']['id'], $branch_id]);
        $order_id = $pdo->lastInsertId();

        $total = 0;
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['quantity'] ?? 0);
            $price = isset($item['price']) ? floatval($item['price']) : null;
            if ($price === null) {
                $stmt = $pdo->prepare("SELECT price_unit FROM products WHERE id=?");
                $stmt->execute([$pid]);
                $price = floatval($stmt->fetchColumn());
            }
            if ($qty <= 0 || $pid <= 0) continue;
            if (!$price) $price = 0;

            $subtotal = $price * $qty;
            $total += $subtotal;

            $tierId = !empty($item['tier_id']) ? intval($item['tier_id']) : null;
            $unitLabel = $item['unit_label'] ?? 'unidad';
            $unitQty = intval($item['unit_qty'] ?? 1);
            $productName = $item['name'] ?? null;

            $stmt = $pdo->prepare("INSERT INTO order_items 
                (order_id, product_id, product_name, tier_id, unit_label, quantity, price, subtotal) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $pid, $productName, $tierId, $unitLabel, $qty, $price, $subtotal]);

            $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id=? AND branch_id=?");
            $stmt->execute([$qty * $unitQty, $pid, $branch_id]);

            $stmt = $pdo->prepare("INSERT INTO inventory_movements (product_id, branch_id, user_id, type, quantity, note) VALUES (?, ?, ?, 'salida', ?, 'Venta POS')");
            $stmt->execute([$pid, $branch_id, $_SESSION['user']['id'], $qty * $unitQty]);
        }

        $stmt = $pdo->prepare("UPDATE orders SET total=? WHERE id=?");
        $stmt->execute([$total, $order_id]);

        // ✅ Guardar pago efectivo
        if (!empty($_POST['cash_received'])) {
            $cashReceived = floatval($_POST['cash_received']);
            if ($cashReceived > 0) {
                $changeGiven = max(0, $cashReceived - $total);
                $fechaLocal = date('Y-m-d H:i:s'); // Hora Bogotá
                $stmt = $pdo->prepare("INSERT INTO payments (order_id, method, amount, cash_received, change_given, status, user_id, created_at) 
                                       VALUES (?, 'efectivo', ?, ?, ?, 'completado', ?, ?)");
                $stmt->execute([$order_id, $total, $cashReceived, $changeGiven, $_SESSION['user']['id'], $fechaLocal]);
            }
        }

        // ✅ Guardar pago virtual
        if (!empty($_POST['virtual_received'])) {
            $virtualReceived = floatval($_POST['virtual_received']);
            if ($virtualReceived > 0) {
                $fechaLocal = date('Y-m-d H:i:s'); // Hora Bogotá
                $stmt = $pdo->prepare("INSERT INTO payments (order_id, method, amount, status, user_id, created_at) 
                                       VALUES (?, 'virtual', ?, 'completado', ?, ?)");
                $stmt->execute([$order_id, $virtualReceived, $_SESSION['user']['id'], $fechaLocal]);
            }
        }

        // ✅ Abrir factura e imprimir
        echo "<script>
            try { localStorage.removeItem('pos_cart'); } catch(e) {}
            window.open('/mi_tienda/admin/invoice_print.php?order_id={$order_id}','_blank');
            window.location.href='pos.php?mode=tactil&cleancart=1';
        </script>";
        exit;
    }
}

// ✅ Datos del modo táctil
$currentCategoryId = intval($_GET['cat'] ?? 0);
$currentSubcategoryId = intval($_GET['sub'] ?? 0);

// ✅ Cargar categorías visibles
$stmt = $pdo->prepare("
  SELECT c.id, c.name, c.icon
  FROM categories c
  LEFT JOIN branch_category_visibility v ON v.category_id = c.id AND v.branch_id = ?
  WHERE COALESCE(v.visible, 1) = 1
  ORDER BY c.name
");
$stmt->execute([$currentBranchId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Cargar subcategorías visibles
$subcategories = [];
if ($currentCategoryId > 0) {
    $stmt = $pdo->prepare("
      SELECT s.id, s.name, s.icon
      FROM subcategories s
      LEFT JOIN branch_subcategory_visibility v ON v.subcategory_id = s.id AND v.branch_id = ?
      WHERE s.category_id = ? AND COALESCE(v.visible, 1) = 1
      ORDER BY s.name
    ");
    $stmt->execute([$currentBranchId, $currentCategoryId]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Validar acceso a subcategoría oculta
if ($currentSubcategoryId > 0) {
    $stmt = $pdo->prepare("
      SELECT 1 FROM subcategories s
      LEFT JOIN branch_subcategory_visibility v ON v.subcategory_id = s.id AND v.branch_id = ?
      WHERE s.id = ? AND COALESCE(v.visible,1)=1
    ");
    $stmt->execute([$currentBranchId, $currentSubcategoryId]);
    if (!$stmt->fetchColumn()) {
        header("Location: pos.php?mode=tactil");
        exit;
    }
}

// ✅ Cargar productos
$products = [];
if ($currentSubcategoryId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.id AS product_id, NULL AS tier_id, p.name AS display_name, p.sku,
               p.price_unit AS price,
               (SELECT filename FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1) AS image,
               IFNULL(i.quantity, 0) AS stock,
               'unidad' AS unit_label, 1 AS unit_qty,
               p.category_id, p.subcategory_id
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
        WHERE p.active = 1 AND p.subcategory_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$currentBranchId, $currentCategoryId]);
    $products_base = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT p.id AS product_id, pp.id AS tier_id,
               CONCAT(p.name, ' - ', pp.unit_label) AS display_name,
               CONCAT(p.sku, '-', pp.unit_label) AS sku,
               pp.price,
               (SELECT filename FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1) AS image,
               IFNULL(i.quantity, 0) AS stock,
               pp.unit_label, pp.quantity AS unit_qty,
               pp.category_id, pp.subcategory_id
        FROM product_prices pp
        INNER JOIN products p ON pp.product_id = p.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.branch_id = ?
        WHERE p.active = 1 AND pp.subcategory_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$currentBranchId, $currentSubcategoryId]);
    $products_tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = array_merge($products_base, $products_tiers);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Punto de Venta</title>
<link rel="stylesheet" href="../public/css/pos.css">
<link rel="stylesheet" href="../public/css/theme_dark.css">

<!-- Compact search CSS (solo ajustes necesarios) -->
<style>
/* Compact search for POS */
.pos-search { display:inline-flex; align-items:center; gap:6px; }
.pos-search input#global-search {
  width:220px;
  max-width:40vw;
  min-width:160px;
  padding:8px 10px;
  font-size:14px;
  border-radius:8px;
  border:1px solid #ccc;
  background:#fff;
  box-shadow: none;
  outline: none;
  -webkit-appearance: none;
}
.pos-search button#global-search-clear {
  background:transparent;
  border:0;
  font-size:16px;
  padding:6px;
  cursor:pointer;
  color:#666;
  border-radius:6px;
}
.pos-search input#global-search:focus { border-color:#0078d4; box-shadow:0 0 0 3px rgba(0,120,212,0.08); }

/* Ajustes para pantallas muy pequeñas (táctil) */
@media (max-width: 800px) {
  .pos-search input#global-search { width:180px; font-size:15px; padding:10px; }
  .pos-search { gap:8px; }
}

/* Small helper to visually hide labels for accessibility */
.sr-only { position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden; }
</style>
</head>
<body>
<header>
  <div class="header-left">
    <h2>Punto de Venta</h2>
    <div style="font-size:14px;opacity:0.8;">
      Sucursal: <strong><?= htmlspecialchars($currentBranchName) ?></strong>
      <a href="/mi_tienda/admin/caja.php">Caja</a>
      <a href="/mi_tienda/admin/invoices_list.php">Facturas</a>
      <a href="#">Traslado de Mercancía</a>
    </div>
  </div>
  <div class="header-right">
    <a href="pos.php?mode=tactil" class="btn">Modo táctil</a>
    <a href="/mi_tienda/admin/logout.php" class="btn btn-danger">Cerrar sesión</a>
  </div>
</header>
<div class="container">
<div class="pos-layout">
    <!-- MIGAS DE PAN -->
    <div class="breadcrumb">
        <span onclick="goToCategories()">Categorías</span>

        <?php if ($currentCategoryId > 0): ?>
            › <span onclick="goToCategory(<?= $currentCategoryId ?>)">
                <?= htmlspecialchars($pdo->query("SELECT name FROM categories WHERE id=$currentCategoryId")->fetchColumn()) ?>
            </span>
        <?php endif; ?>

        <?php if ($currentSubcategoryId > 0): ?>
            › <span>
                <?= htmlspecialchars($pdo->query("SELECT name FROM subcategories WHERE id=$currentSubcategoryId")->fetchColumn()) ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- BUSCADOR GLOBAL POR CATEGORÍA/SUBCATEGORÍA (compacto para táctil) -->
    <div class="pos-search-wrapper" aria-hidden="false" style="margin:8px 0 12px;">
      <label for="global-search" class="sr-only">Buscar productos en la categoría actual</label>
      <div class="pos-search">
        <input id="global-search" type="search" inputmode="search" placeholder="Buscar producto (nombre o SKU)" autocomplete="off" />
        <button id="global-search-clear" type="button" aria-label="Limpiar búsqueda">✕</button>
      </div>
    </div>

    <!-- NIVEL 1 — CATEGORÍAS -->
    <?php if ($currentCategoryId === 0): ?>
        <h3>Categorías</h3>
        <div class="grid-tactil">
            <?php foreach ($categories as $c): ?>
                <div class="card-tactil" onclick="goToCategory(<?= $c['id'] ?>)">
                    <div class="card-tactil-icon">
                        <?= $c['icon'] ? htmlspecialchars($c['icon']) : '📦' ?>
                    </div>
                    <div class="card-tactil-name">
                        <?= htmlspecialchars($c['name']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- NIVEL 2 — SUBCATEGORÍAS -->
    <?php if ($currentCategoryId > 0 && $currentSubcategoryId === 0): ?>
        <h3>Subcategorías</h3>
        <button class="btn" onclick="goToCategories()">⬅ Volver a categorías</button>
        <div class="grid-tactil">
            <?php foreach ($subcategories as $s): ?>
                <div class="card-tactil" onclick="goToSubcategory(<?= $currentCategoryId ?>, <?= $s['id'] ?>)">
                    <div class="card-tactil-icon">
                        <?= $s['icon'] ? htmlspecialchars($s['icon']) : '🛍️' ?>
                    </div>
                    <div class="card-tactil-name">
                        <?= htmlspecialchars($s['name']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- NIVEL 3 — PRODUCTOS -->
    <?php if ($currentSubcategoryId > 0): ?>
        <h3>Productos</h3>
        <button class="btn" onclick="goToCategory(<?= $currentCategoryId ?>)">⬅ Volver a subcategorías</button>

        <div class="grid-tactil" id="products-grid">
            <?php foreach ($products as $p): ?>
                <div class="product-card <?= $p['stock'] <= 0 ? 'disabled' : '' ?>"
                    data-product-id="<?= $p['product_id'] ?>"
                    data-tier-id="<?= isset($p['tier_id']) ? $p['tier_id'] : '' ?>"
                    data-product-name="<?= htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES) ?>"
                    data-product-sku="<?= htmlspecialchars($p['sku'] ?? '', ENT_QUOTES) ?>"
                    data-product-price="<?= $p['price'] ?>"
                    data-product-image="<?= htmlspecialchars($p['image'] ?? '', ENT_QUOTES) ?>"
                    data-unit-label="<?= htmlspecialchars($p['unit_label'] ?? 'unidad', ENT_QUOTES) ?>"
                    data-unit-qty="<?= (int)($p['unit_qty'] ?? 1) ?>"
                    onclick="<?= $p['stock'] > 0 ? "addToCart(
                        {$p['product_id']}, 
                        ".(isset($p['tier_id']) ? $p['tier_id'] : 'null').",
                        '".htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES)."', 
                        {$p['price']}, 
                        '".htmlspecialchars($p['image'] ?? '', ENT_QUOTES)."',
                        '".htmlspecialchars($p['unit_label'] ?? 'unidad', ENT_QUOTES)."',
                        ".((int)($p['unit_qty'] ?? 1)).",
                        1
                    )" : "" ?>">

                    <?php if (!empty($p['image'])): ?>
                        <img src="/mi_tienda/uploads/products/<?= htmlspecialchars($p['image']) ?>" alt="">
                    <?php else: ?>
                        <img src="/mi_tienda/uploads/products/no-image.png" alt="">
                    <?php endif; ?>

                    <div class="product-name"><?= htmlspecialchars($p['display_name'] ?? $p['name']) ?></div>
                    <div class="product-price">$<?= number_format($p['price'], 2) ?></div>
                    <div class="product-stock" style="color:<?= $p['stock'] <= 0 ? '#d9534f' : '#0a6' ?>">
                        Stock: <?= (int)$p['stock'] ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- PANEL DERECHO -->
    <div class="pos-right">
        <h3>Carrito</h3>
        <div class="cart-panel">
            <table class="cart">
                <thead>
                    <tr>
                        <th>Prod.</th>
                        <th>Present.</th>
                        <th>Cant.</th>
                        <th>Subt.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="cart-body"></tbody>
            </table>
        </div>
        <div class="cart-total">
            Total: $<span id="total">0.00</span>
        </div>
        <div class="cart-actions">
            <button class="btn btn-danger" type="button" onclick="clearCart()">Limpiar</button>
            <button class="btn btn-secondary" type="button" id="open-cash-panel-2">Cobrar 2</button>
        </div>
        <form method="POST" action="pos.php?action=sell&mode=tactil" id="sell-form" style="display:block;">
            <input type="hidden" name="branch_id" value="<?= $currentBranchId ?>">
            <input type="hidden" name="items" id="items-input">
            <input type="hidden" name="cash_received" id="tmp_cash_received" value="">
            <input type="hidden" name="virtual_received" id="tmp_virtual_received" value="">
            <button class="btn" type="submit" id="confirm-sale-btn" style="display:none;">Confirmar Venta</button>
        </form>
        <script>
            document.getElementById("sell-form").addEventListener("submit", function() {
                try { localStorage.removeItem("pos_cart"); } catch(e){}
            });
        </script>
        <div id="cash-panel" class="cash-panel" aria-hidden="true" role="dialog" aria-labelledby="cash-panel-title" style="display:none;">
          <div class="cash-panel-inner" role="document">
            <h2 id="cash-panel-title">Cobro</h2>

            <div class="summary">
              <div class="row"><span class="label">Total:</span><span id="cp-total" class="value">COP 0.00</span></div>
              <div class="row"><span class="label">Descuento:</span><span id="cp-discount" class="value">COP 0.00</span></div>
              <div class="row"><span class="label">A pagar:</span><span id="cp-due" class="value">COP 0.00</span></div>
            </div>

            <div class="input-block">
              <label for="cp-virtual">Pago virtual</label>
              <input id="cp-virtual" inputmode="decimal" autocomplete="off" type="text" placeholder="0.00" />
            </div>

            <div class="input-block">
              <label for="cp-cash">Efectivo recibido</label>
              <input id="cp-cash" inputmode="decimal" autocomplete="off" type="text" placeholder="0.00" />
            </div>

            <div id="cp-change" class="change">Vuelto: <strong>COP 0.00</strong></div>

            <div class="numpad" role="group" aria-label="Teclado numérico">
              <button data-key="7" type="button">7</button><button data-key="8" type="button">8</button><button data-key="9" type="button">9</button>
              <button data-key="4" type="button">4</button><button data-key="5" type="button">5</button><button data-key="6" type="button">6</button>
              <button data-key="1" type="button">1</button><button data-key="2" type="button">2</button><button data-key="3" type="button">3</button>
              <button data-key="0" type="button">0</button><button data-key="." type="button">.</button><button data-key="back" type="button">⌫</button>
            </div>

            <div class="actions">
              <button id="cp-cancel" class="btn secondary" type="button">Cancelar</button>
              <button id="cp-confirm-ajax" class="btn primary" disabled type="button" style="display:none;">Confirmar venta (AJAX)</button>
              <button id="cp-confirm-post" class="btn primary" disabled type="button" style="display:none;">Confirmar venta</button>
            </div>

            <div id="cp-error" class="error" role="alert" aria-live="assertive"></div>
          </div>
        </div>

        <style>
        .cash-panel { position: fixed; right: 20px; bottom: 20px; width: 360px; background:#fff; border:1px solid #ddd; box-shadow:0 6px 18px rgba(0,0,0,.12); border-radius:8px; z-index:1000; font-family:Arial, sans-serif; }
        .cash-panel-inner { padding:16px; }
        .cash-panel h2 { margin:0 0 12px; font-size:18px; }
        .summary .row { display:flex; justify-content:space-between; margin:6px 0; }
        .label { color:#666; }
        .value { font-weight:700; }
        .input-block { margin:12px 0; }
        #cp-cash, #cp-virtual { width:100%; padding:10px; font-size:18px; box-sizing:border-box; }
        .change { margin-top:8px; color:#0a6; font-weight:700; }
        .numpad { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:12px 0; }
        .numpad button { padding:12px; font-size:16px; border-radius:6px; border:1px solid #ccc; background:#f7f7f7; cursor:pointer; }
        .actions { display:flex; gap:8px; justify-content:flex-end; }
        .btn { padding:10px 14px; border-radius:6px; border:0; cursor:pointer; }
        .btn.primary { background:#0078d4; color:#fff; }
        .btn.secondary { background:#eee; color:#333; }
        .error { color:#b00020; margin-top:8px; min-height:18px; }
        </style>

        <script>
        (function(){
          const currencyLabel = '$';
          const formatMoney = v => currencyLabel + ' ' + Number(v).toLocaleString('es-CO', {minimumFractionDigits:2, maximumFractionDigits:2});

          const panel = document.getElementById('cash-panel');
          const input = document.getElementById('cp-cash');
          const virtualInput = document.getElementById('cp-virtual');
          const changeEl = document.getElementById('cp-change').querySelector('strong');
          const confirmAjaxBtn = document.getElementById('cp-confirm-ajax');
          const confirmPostBtn = document.getElementById('cp-confirm-post');
          const cancelBtn = document.getElementById('cp-cancel');
          const errorEl = document.getElementById('cp-error');

          const openBtn2 = document.getElementById('open-cash-panel-2');
          const itemsInput = document.getElementById('items-input');
          const sellForm = document.getElementById('sell-form');
          const tmpCashInput = document.getElementById('tmp_cash_received');
          const tmpVirtualInput = document.getElementById('tmp_virtual_received');

          let total = 0.00;
          let discount = 0.00;
          const dueEl = document.getElementById('cp-due');
          const totalEl = document.getElementById('cp-total');
          const discountEl = document.getElementById('cp-discount');

          let panelMode = 'ajax';

          function initWithCart(cartTotal, cartDiscount, mode = 'ajax'){
            panelMode = mode;
            if (typeof cartTotal === 'undefined' || cartTotal === null) {
              const t = document.getElementById('total').textContent || '0';
              cartTotal = String(t).replace(/[^0-9\.,-]/g,'').replace(/,/g,'');
            }
            total = Number(cartTotal) || 0;
            discount = Number(cartDiscount) || 0;
            const due = Math.max(0, total - discount);
            totalEl.textContent = formatMoney(total);
            discountEl.textContent = formatMoney(discount);
            dueEl.textContent = formatMoney(due);
            input.value = '';
            virtualInput.value = '';
            updateChange();
            panel.style.display = 'block';
            panel.setAttribute('aria-hidden','false');
            input.focus();

            if (panelMode === 'post') {
              confirmPostBtn.style.display = 'inline-block';
              confirmAjaxBtn.style.display = 'none';
            } else {
              confirmPostBtn.style.display = 'none';
              confirmAjaxBtn.style.display = 'inline-block';
            }
          }

          function parseInputVal(v){
            v = String(v).replace(/,/g, '.').replace(/[^\d.]/g,'');
            return v === '' ? 0 : parseFloat(v);
          }
          function updateChange(){
            const cash = parseInputVal(input.value);
            const virtual = parseInputVal(virtualInput.value);
            const due = Math.max(0, total - discount);
            const paid = cash + virtual;
            const change = Math.max(0, paid - due);
            changeEl.textContent = formatMoney(change);
            if (paid >= due && due > 0) {
              confirmAjaxBtn.disabled = false;
              confirmPostBtn.disabled = false;
              errorEl.textContent = '';
            } else {
              confirmAjaxBtn.disabled = true;
              confirmPostBtn.disabled = true;
              errorEl.textContent = (due === 0) ? 'Total en 0. Usa pago distinto si aplica.' : 'Pago insuficiente';
            }
          }
          // Numpad
          document.querySelectorAll('.numpad button').forEach(btn=>{
            btn.addEventListener('click', ()=> {
              const k = btn.getAttribute('data-key');
              if (k === 'back') {
                input.value = input.value.slice(0,-1);
              } else {
                input.value = (input.value === '0' ? '' : input.value) + k;
              }
              updateChange();
              input.focus();
            });
          });

          // Input events
          input.addEventListener('input', updateChange);
          virtualInput.addEventListener('input', updateChange);
          input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
              if (!confirmAjaxBtn.disabled && confirmAjaxBtn.style.display !== 'none') confirmAjaxBtn.click();
              if (!confirmPostBtn.disabled && confirmPostBtn.style.display !== 'none') confirmPostBtn.click();
            }
          });

          // Cancelar
          cancelBtn.addEventListener('click', ()=> {
            panel.style.display = 'none';
            panel.setAttribute('aria-hidden','true');
            input.value = '';
            virtualInput.value = '';
            errorEl.textContent = '';
          });

          // Confirmar via AJAX
          confirmAjaxBtn.addEventListener('click', async ()=> {
            confirmAjaxBtn.disabled = true;
            errorEl.textContent = '';
            const cash = parseInputVal(input.value);
            const virtual = parseInputVal(virtualInput.value);
            const due = Math.max(0, total - discount);
            const paid = cash + virtual;
            if (paid < due) { errorEl.textContent = 'Pago insuficiente'; confirmAjaxBtn.disabled = false; return; }

            let items = [];
            try {
              const raw = localStorage.getItem('pos_cart') || '[]';
              items = JSON.parse(raw);
            } catch (err) { items = []; }

            let win = null;
            try { win = window.open('', '_blank'); } catch(e){ win = null; }

            const payments = [];
            if (cash > 0) payments.push({ type: "efectivo", amount: cash, ref: null });
            if (virtual > 0) payments.push({ type: "virtual", amount: virtual, ref: null });

            const payload = { items: items, payments: payments };

            try {
              const resp = await fetch('checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
              });
              const data = await resp.json();
              if (data && data.success) {
                try { localStorage.removeItem('pos_cart'); } catch(e){}
                if (typeof cart !== 'undefined') { cart = []; }
                if (typeof renderCart === 'function') { renderCart(); }
                panel.style.display = 'none';
                panel.setAttribute('aria-hidden','true');

                if (win && !win.closed) {
                  win.location = data.receipt_url;
                } else {
                  window.open(data.receipt_url, '_blank');
                }
                window.location.href = 'pos.php?mode=tactil&cleancart=1';
              } else {
                errorEl.textContent = (data && data.message) ? data.message : 'Error al procesar la venta';
                confirmAjaxBtn.disabled = false;
                if (win && !win.closed) win.close();
              }
            } catch (err) {
              errorEl.textContent = 'Error de conexión con checkout';
              confirmAjaxBtn.disabled = false;
              if (win && !win.closed) win.close();
            }
          });

          // Confirmar via POST
          confirmPostBtn.addEventListener('click', ()=> {
            confirmPostBtn.disabled = true;
            confirmPostBtn.innerText = 'Procesando...';
            errorEl.textContent = '';
            const cash = parseInputVal(input.value);
            const virtual = parseInputVal(virtualInput.value);
            const due = Math.max(0, total - discount);
            const paid = cash + virtual;
            if (paid < due) { errorEl.textContent = 'Pago insuficiente'; confirmPostBtn.disabled = false; confirmPostBtn.innerText = 'Confirmar venta'; return; }

            let raw = '[]';
            try { raw = localStorage.getItem('pos_cart') || '[]'; } catch (err) { raw = '[]'; }
            itemsInput.value = raw;

            if (tmpCashInput) tmpCashInput.value = cash;
            if (tmpVirtualInput) tmpVirtualInput.value = virtual;

            panel.style.display = 'none';
            panel.setAttribute('aria-hidden','true');

            window.__pos_submit_in_progress = true;
            sellForm.submit();

            setTimeout(function(){
              try { localStorage.removeItem('pos_cart'); } catch(e){}
              if (typeof cart !== 'undefined') { cart = []; }
              if (typeof renderCart === 'function') { renderCart(); }
            }, 600);
          });

          if (openBtn2) {
            openBtn2.addEventListener('click', function(){
              const t = document.getElementById('total').textContent || '0';
              const numeric = String(t).replace(/[^0-9\.,-]/g,'').replace(/,/g,'');
              initWithCart(numeric, 0, 'post');
            });
          }

          const confirmSaleBtn = document.getElementById('confirm-sale-btn');
          const sellFormEl = document.getElementById('sell-form');
          if (sellFormEl) {
            sellFormEl.addEventListener('submit', function(e){
              if (window.__pos_submit_in_progress) return;
              e.preventDefault();

              let raw = '[]';
              try { raw = localStorage.getItem('pos_cart') || '[]'; } catch(e) { raw = '[]'; }
              let parsed;
              try { parsed = JSON.parse(raw); } catch(e) { parsed = []; }

              if (!Array.isArray(parsed) || parsed.length === 0) {
                alert('El carrito está vacío. Agrega productos antes de confirmar la venta.');
                return;
              }

              const t = document.getElementById('total').textContent || '0';
              const numeric = String(t).replace(/[^0-9\.,-]/g,'').replace(/,/g,'');
              if (window.CashPanel && typeof window.CashPanel.initWithCart === 'function') {
                window.CashPanel.initWithCart(numeric, 0, 'post');
              } else {
                itemsInput.value = JSON.stringify(parsed);
                window.__pos_submit_in_progress = true;
                sellFormEl.submit();
              }
            });
          }

          window.CashPanel = { initWithCart };
        })();
        </script>
    </div> <!-- cierre pos-right -->
</div> <!-- cierre pos-layout -->

<!-- MODAL CANTIDAD -->
<div class="modal-overlay" id="modal-overlay" style="display:none;">
    <div class="modal">
        <h3 id="modal-product-name">Cantidad</h3>
        <input type="number" id="modal-qty" min="1" step="1" value="1">
        <div class="modal-buttons">
            <button type="button" class="btn btn-danger" onclick="closeQtyModal()">Cancelar</button>
            <button type="button" class="btn" onclick="confirmQty()">Agregar</button>
        </div>
    </div>
</div>

<!-- SONIDO -->
<audio id="beep-sound">
    <source src="data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YQAAAAA=" type="audio/wav">
</audio>

<script>
function goToCategories() { window.location.href = "pos.php?mode=tactil"; }
function goToCategory(catId) { window.location.href = "pos.php?mode=tactil&cat=" + catId; }
function goToSubcategory(catId, subId) { window.location.href = "pos.php?mode=tactil&cat=" + catId + "&sub=" + subId; }
/* ============================================
   CARRITO
============================================ */
let cart = JSON.parse(localStorage.getItem("pos_cart") || "[]");
renderCart();

function addToCart(id, tierId, name, price, image, unitLabel, unitQty, quantity = 1) {
    const index = cart.findIndex(item => item.product_id === id && String(item.tier_id || '') === String(tierId || ''));
    if (index >= 0) {
        cart[index].quantity += quantity;
    } else {
        cart.push({
            product_id: id,
            tier_id: tierId === null ? null : tierId,
            name: name,
            price: parseFloat(price),
            quantity: quantity,
            image: image,
            unit_label: unitLabel || 'unidad',
            unit_qty: parseInt(unitQty || 1, 10)
        });
    }
    renderCart();
    playBeep();
}

function renderCart() {
    const tbody = document.getElementById("cart-body");
    const totalSpan = document.getElementById("total");
    const itemsInput = document.getElementById("items-input");
    if (!tbody || !totalSpan || !itemsInput) return;

    let html = "";
    let total = 0;
    cart.forEach((item, i) => {
        const subtotal = item.price * item.quantity;
        total += subtotal;
        html += `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${escapeHtml(item.unit_label || 'unidad')}</td>
                <td>${item.quantity}</td>
                <td>$${subtotal.toFixed(2)}</td>
                <td><button class="btn btn-danger" type="button" onclick="removeItem(${i})">X</button></td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    totalSpan.innerText = total.toFixed(2);
    itemsInput.value = JSON.stringify(cart);
    try { localStorage.setItem("pos_cart", JSON.stringify(cart)); } catch(e){}
}

function removeItem(i) {
    cart.splice(i, 1);
    try { localStorage.setItem("pos_cart", JSON.stringify(cart)); } catch(e){}
    renderCart();
}

function clearCart() {
    if (!cart.length) return;
    if (!confirm("¿Vaciar carrito?")) return;
    cart = [];
    try { localStorage.removeItem("pos_cart"); } catch(e){}
    renderCart();
}

/* ============================================
   MODAL DE CANTIDAD
============================================ */
let currentProduct = null;
function openQtyModal(id, name, price, image, tierId = null, unitLabel = 'unidad', unitQty = 1) {
    currentProduct = { id, tier_id: tierId, name, price: parseFloat(price), image, unit_label: unitLabel, unit_qty: unitQty };
    const overlay = document.getElementById("modal-overlay");
    const qtyInput = document.getElementById("modal-qty");
    const title = document.getElementById("modal-product-name");
    if (!overlay || !qtyInput || !title) return;
    title.innerText = name;
    qtyInput.value = 1;
    overlay.style.display = "flex";
    qtyInput.focus();
}
function closeQtyModal() {
    const overlay = document.getElementById("modal-overlay");
    if (overlay) overlay.style.display = "none";
    currentProduct = null;
}
function confirmQty() {
    const qtyInput = document.getElementById("modal-qty");
    if (!qtyInput || !currentProduct) return;
    const qty = parseInt(qtyInput.value, 10);
    if (!qty || qty <= 0) return;
    addToCart(
        currentProduct.id,
        currentProduct.tier_id || null,
        currentProduct.name,
        currentProduct.price,
        currentProduct.image,
        currentProduct.unit_label || 'unidad',
        currentProduct.unit_qty || 1,
        qty
    );
    closeQtyModal();
}

/* ============================================
   SONIDO, MODO OSCURO, ATAJOS
============================================ */
function playBeep() {
    const beep = document.getElementById("beep-sound");
    if (beep) {
        beep.currentTime = 0;
        beep.play().catch(() => {});
    }
}
const switchDark = document.getElementById("switch-dark");
if (switchDark) {
    switchDark.addEventListener("click", () => {
        document.body.classList.toggle("dark");
    });
}
document.addEventListener("keydown", function(e) {
    const overlay = document.getElementById("modal-overlay");
    const modalVisible = overlay && overlay.style.display === "flex";
    if (e.key === "Escape" && modalVisible) { closeQtyModal(); return; }
    if (modalVisible && e.key === "Enter") { e.preventDefault(); confirmQty(); return; }
    if (!modalVisible) {
        if (e.key === "F1") { e.preventDefault(); const input = document.getElementById("global-search"); if (input) input.focus(); }
        if (e.key === "F2") { e.preventDefault(); const form = document.getElementById("sell-form"); if (form) form.submit(); }
        if (e.key === "F3") { e.preventDefault(); clearCart(); }
    }
});

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
}

/* Limpieza segura al cargar la página si el servidor indicó cleancart=1 */
(function(){
  try {
    const params = new URLSearchParams(window.location.search);
    if (params.get('cleancart') === '1') {
      try { localStorage.removeItem('pos_cart'); } catch(e){}
      if (typeof cart !== 'undefined') { cart = []; }
      if (typeof renderCart === 'function') { renderCart(); }
      if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('cleancart');
        window.history.replaceState({}, document.title, url.toString());
      }
    }
  } catch(e){}
})();

/* Long-press para abrir modal de cantidad */
(function(){
  try {
    document.querySelectorAll('.product-card').forEach(card => {
      let timer = null;
      const start = () => {
        timer = setTimeout(() => {
          const id = card.getAttribute('data-product-id');
          const tier = card.getAttribute('data-tier-id') || null;
          const name = card.getAttribute('data-product-name') || '';
          const price = card.getAttribute('data-product-price') || '0';
          const image = card.getAttribute('data-product-image') || '';
          const unitLabel = card.getAttribute('data-unit-label') || 'unidad';
          const unitQty = parseInt(card.getAttribute('data-unit-qty') || '1', 10);
          openQtyModal(id, name, price, image, tier, unitLabel, unitQty);
        }, 500);
      };
      const cancel = () => { if (timer) { clearTimeout(timer); timer = null; } };

      card.addEventListener('mousedown', start);
      card.addEventListener('touchstart', start);
      card.addEventListener('mouseup', cancel);
      card.addEventListener('mouseleave', cancel);
      card.addEventListener('touchend', cancel);
      card.addEventListener('touchcancel', cancel);
    });
  } catch(e){}
})();

/* ============================================
   BUSCADOR GLOBAL (fuera de la grilla de subcategoría)
   Filtra por nombre y SKU dentro de la subcategoría cargada
============================================ */
(function(){
  try {
    if (window.__pos_global_search_installed) return;
    window.__pos_global_search_installed = true;

    const input = document.getElementById('global-search');
    const clearBtn = document.getElementById('global-search-clear');
    const grid = document.getElementById('products-grid'); // grilla de productos de la subcategoría actual

    if (!input || !grid) {
      const wrapper = document.querySelector('.pos-search-wrapper');
      if (wrapper) wrapper.style.display = 'none';
      return;
    }

    function filterProducts(term) {
      term = String(term || '').trim().toLowerCase();
      const cards = grid.querySelectorAll('.product-card');
      if (!term) {
        cards.forEach(c => c.style.display = '');
        return;
      }
      cards.forEach(c => {
        const name = (c.getAttribute('data-product-name') || '').toLowerCase();
        const sku = (c.getAttribute('data-product-sku') || '').toLowerCase();
        const matches = name.indexOf(term) !== -1 || sku.indexOf(term) !== -1;
        c.style.display = matches ? '' : 'none';
      });
    }

    // Debounce
    let timer = null;
    input.addEventListener('input', function(){
      clearTimeout(timer);
      timer = setTimeout(() => filterProducts(this.value), 120);
    });

    clearBtn.addEventListener('click', function(){
      input.value = '';
      filterProducts('');
      input.focus();
    });

    input.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = grid.querySelector('.product-card:not([style*="display: none"])');
        if (first) first.scrollIntoView({behavior:'smooth', block:'center'});
      }
    });

    // Load query param q if present
    try {
      const params = new URLSearchParams(window.location.search);
      const q = params.get('q') || '';
      if (q) {
        input.value = q;
        filterProducts(q);
      }
    } catch(e){}
  } catch(e){}
})();
</script>
</body>
</html>

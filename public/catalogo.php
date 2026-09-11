<?php
// catalogo.php
// Versión corregida y estable: búsqueda visible, carouseles por producto funcionales,
// paginación, lazy-init robusto y lightbox. Evita referencias a columnas inexistentes.
// Requiere ../includes/db.php que devuelva PDO en DB::getConnection()
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::getConnection();

// --- Parámetros de paginación y búsqueda ---
$perPage = 24;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- Filtros ---
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(p.name LIKE :q OR p.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (!empty($_GET['categoria'])) {
    $where[] = "(p.category = :categoria OR p.categoria = :categoria)";
    $params[':categoria'] = $_GET['categoria'];
}
if (isset($_GET['stock']) && $_GET['stock'] !== '') {
    if (intval($_GET['stock']) === 1) {
        $where[] = "COALESCE(inv.stock,0) > 0";
    } else {
        $where[] = "COALESCE(inv.stock,0) <= 0";
    }
}
if (!empty($_GET['min'])) {
    $where[] = "p.price >= :min";
    $params[':min'] = floatval($_GET['min']);
}
if (!empty($_GET['max'])) {
    $where[] = "p.price <= :max";
    $params[':max'] = floatval($_GET['max']);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Conteo total para paginación ---
$countSql = "
  SELECT COUNT(DISTINCT p.id) AS total
  FROM products p
  LEFT JOIN (
    SELECT product_id, SUM(quantity) AS stock FROM inventory GROUP BY product_id
  ) inv ON inv.product_id = p.id
  $whereSql
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// --- Consulta principal: p.*, stock, main_image y lista de imágenes ---
$sql = "
    SELECT 
        p.*,
        COALESCE(inv.stock,0) AS stock,
        (
            SELECT filename 
            FROM product_images 
            WHERE product_id = p.id AND is_main = 1 
            LIMIT 1
        ) AS main_image,
        (
            SELECT GROUP_CONCAT(filename SEPARATOR '||') 
            FROM product_images 
            WHERE product_id = p.id
        ) AS images_list
    FROM products p
    LEFT JOIN (
      SELECT product_id, SUM(quantity) AS stock FROM inventory GROUP BY product_id
    ) inv ON inv.product_id = p.id
    $whereSql
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper
function safe_json_attr($data) {
    return htmlspecialchars(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi Tienda - Catálogo</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    /* =========================
   Responsive styles for TV / Desktop / Tablet / Mobile
   Paste this after your base styles or merge where corresponda
   ========================= */

/* --- Base adjustments (mobile-first) --- */
:root{
  --max-width:1120px;
  --gap:18px;
  --card-min-height:320px;
  --carousel-height:160px;
  --thumb-size:56px;
  --font-base:15px;
  --tv-scale:1.0;
}

html { font-size: 100%; } /* 16px */
body { font-size: var(--font-base); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }

/* Layout container */
.wrap { max-width: var(--max-width); margin: 0 auto; padding: 0 20px; }

/* Grid: mobile default = 1 column */
.oferta-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--gap);
}

/* Product card */
.oferta-card {
  min-height: var(--card-min-height);
  display: flex;
  flex-direction: column;
  transition: transform .18s ease, box-shadow .18s ease;
}
.oferta-card:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(11,37,69,0.08); }

/* Carousel sizes */
.prod-carousel { height: var(--carousel-height); border-radius:10px; overflow:hidden; background:#f6f7f9; position:relative; }
.prod-carousel img { width:100%; height:100%; object-fit:cover; display:block; cursor:pointer; }

/* Controls (overlay) */
.carousel-controls { position:absolute; left:8px; right:8px; bottom:8px; display:flex; justify-content:space-between; align-items:center; pointer-events:none; }
.carousel-controls button { pointer-events:auto; background:rgba(0,0,0,0.45); color:#fff; border:none; padding:6px 8px; border-radius:6px; cursor:pointer; }

/* Thumbs */
.carousel-thumbs { display:flex; gap:8px; margin-top:10px; overflow:auto; -webkit-overflow-scrolling:touch; }
.carousel-thumb { width: var(--thumb-size); height: calc(var(--thumb-size) * 0.72); border-radius:6px; overflow:hidden; flex:0 0 auto; border:2px solid transparent; cursor:pointer; }
.carousel-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.carousel-thumb.active { border-color: var(--amarillo); }

/* Rating and meta */
.rating { display:flex; align-items:center; gap:8px; margin-top:8px; }
.price { font-weight:800; font-size:1rem; }

/* Search bar (mobile-first: visible) */
.search-bar { display:flex; gap:8px; align-items:center; width:100%; }
.search-bar input[type="search"] { flex:1; padding:10px 12px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); background:#fff; font-size:0.95rem; }
.search-bar button { padding:10px 12px; border-radius:8px; border:none; background:var(--amarillo); color:var(--azul-dark); font-weight:700; cursor:pointer; }

/* Buttons touch targets */
button, .btn-link, .btn-admisiones { min-height:44px; padding:10px 14px; }

/* Pagination */
.pagination { display:flex; gap:8px; justify-content:center; margin:28px 0; flex-wrap:wrap; }

/* Accessibility focus */
a:focus, button:focus, input:focus { outline: 3px solid rgba(238,185,2,0.25); outline-offset:2px; }

/* --- Tablet (>= 700px) --- */
@media (min-width: 700px) {
  :root { --thumb-size:64px; --carousel-height:200px; --card-min-height:340px; }
  .oferta-grid { grid-template-columns: repeat(2, 1fr); }
  .search-bar { max-width:520px; }
  .prod-carousel { height: var(--carousel-height); }
  .carousel-thumb { height: calc(var(--thumb-size) * 0.72); width: var(--thumb-size); }
}

/* --- Desktop (>= 1000px) --- */
@media (min-width: 1000px) {
  :root { --thumb-size:72px; --carousel-height:220px; --card-min-height:360px; }
  .oferta-grid { grid-template-columns: repeat(3, 1fr); gap: calc(var(--gap) + 6px); }
  .search-bar { max-width:640px; margin-right:12px; }
  header .nav-inner { gap:18px; }
  .prod-carousel { height: var(--carousel-height); }
  .carousel-thumb { width: var(--thumb-size); height: calc(var(--thumb-size) * 0.72); }
  .oferta-card h4 { font-size: 0.98rem; }
}

/* --- Large Desktop / Small TV (>= 1400px) --- */
@media (min-width: 1400px) {
  :root { --max-width: 1400px; --thumb-size:84px; --carousel-height:260px; --card-min-height:420px; --tv-scale:1.02; }
  .wrap { max-width: var(--max-width); }
  .oferta-grid { grid-template-columns: repeat(4, 1fr); gap: calc(var(--gap) + 10px); }
  .prod-carousel { height: var(--carousel-height); border-radius:12px; }
  .carousel-thumb { width: var(--thumb-size); height: calc(var(--thumb-size) * 0.72); }
  .oferta-card { min-height: var(--card-min-height); padding:18px; }
  .search-bar input[type="search"] { font-size:1rem; padding:12px 14px; }
}

/* --- TV / Very large screens (>= 1920px) --- */
@media (min-width: 1920px) {
  :root { --max-width: 1800px; --thumb-size:96px; --carousel-height:320px; --card-min-height:520px; --tv-scale:1.08; }
  html { font-size: calc(16px * var(--tv-scale)); } /* scale up typography for TV */
  .oferta-grid { grid-template-columns: repeat(5, 1fr); gap: calc(var(--gap) + 14px); }
  .prod-carousel { height: var(--carousel-height); }
  .carousel-thumb { width: var(--thumb-size); height: calc(var(--thumb-size) * 0.72); }
  .brand b { font-size: 1.05rem; }
  .btn-admisiones { padding:12px 18px; font-size:1rem; }
}

/* --- Small phones adjustments (<= 420px) --- */
@media (max-width: 420px) {
  :root { --thumb-size:48px; --carousel-height:140px; --card-min-height:300px; }
  .oferta-grid { gap:12px; }
  .carousel-thumbs { gap:6px; }
  .search-bar input[type="search"] { padding:9px 10px; font-size:0.92rem; }
  .btn-link, .btn-admisiones { padding:10px 12px; font-size:0.95rem; }
  .carousel-controls button { padding:6px 6px; font-size:12px; }
}

/* --- Performance / density tweaks ---
   Reduce animations and autoplay on low-power devices */
@media (prefers-reduced-motion: reduce) {
  .oferta-card, .prod-carousel, .hero { transition: none !important; animation: none !important; }
}

/* --- Utility helpers --- */
.hidden-mobile { display:none; }
@media (min-width:700px) { .hidden-mobile { display:inline-block; } }

/* Ensure images don't overflow in TV scaling */
img { max-width:100%; height:auto; display:block; }

/* End of responsive styles */

    :root{
      --azul:#0B2545; --azul-dark:#001529; --amarillo:#EEB902; --bg:#F4F6F9; --dim:#4B5563;
      --glass-bg: rgba(255,255,255,0.92); --glass-border: rgba(0,0,0,0.06); --glass-blur: blur(8px);
    }
    *{box-sizing:border-box;margin:0;padding:0}
    html{font-size:16px}
    body{font-family:'Inter',system-ui,Arial; background:var(--bg); color:#1F2937; line-height:1.45}
    h1,h2,h3,h4,h5{font-family:'Poppins',sans-serif}
    .wrap{max-width:1120px;margin:0 auto;padding:0 20px}

    /* Header */
    header{position:sticky;top:0;background:rgba(0,21,41,0.92);backdrop-filter:var(--glass-blur);padding:10px 0;z-index:120;border-bottom:1px solid rgba(255,255,255,0.06)}
    .nav-inner{display:flex;align-items:center;gap:12px;max-width:1120px;margin:0 auto;padding:0 20px}
    .brand{display:flex;align-items:center;gap:10px;cursor:pointer}
    .brand .logo-img{width:46px;height:46px;border-radius:50%;overflow:hidden;background:#fff;border:2px solid var(--amarillo);display:flex;align-items:center;justify-content:center}
    .brand b{color:#fff;font-weight:800;font-size:13px;text-transform:uppercase}
    nav{display:flex;gap:6px;flex:1;justify-content:flex-end;align-items:center}
    nav button, nav a{background:none;border:none;color:rgba(255,255,255,0.95);padding:8px 12px;border-radius:6px;cursor:pointer;font-weight:600}
    .btn-admisiones{background:var(--amarillo);color:var(--azul-dark);padding:9px 16px;border-radius:6px;font-weight:700}

    /* Search bar in header - visible and responsive */
    .search-bar{display:flex;align-items:center;gap:8px;margin-left:12px;flex:1;min-width:260px}
    .search-bar form{display:flex;gap:8px;width:100%}
    .search-bar input[type="search"]{width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(0,0,0,0.08);font-size:14px;background:#fff}
    .search-bar button{padding:10px 12px;border-radius:8px;border:none;background:var(--amarillo);color:var(--azul-dark);font-weight:700;cursor:pointer}

    /* Hero */
    .hero{position:relative;min-height:220px;overflow:hidden;margin-bottom:12px;border-radius:8px}
    .hero img{width:100%;height:100%;object-fit:cover;display:block;filter:brightness(.55)}

    /* Accesos */
    .accesos{display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:rgba(255,255,255,0.06);border-bottom:4px solid var(--amarillo);margin-bottom:18px}
    .acceso-item{background:var(--azul-dark);color:#fff;padding:18px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer}
    .acceso-item .ic{font-size:20px;margin-bottom:8px}
    .acceso-item span{font-weight:700;text-transform:uppercase;font-size:12px}

    /* Grid */
    .oferta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
    .oferta-card{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:14px;padding:14px;display:flex;flex-direction:column;min-height:360px;position:relative}
    .oferta-card h4{color:var(--azul);font-size:15px;margin:8px 0;text-transform:uppercase}
    .oferta-card .meta{color:var(--dim);font-size:13px}
    .price{font-weight:800;color:var(--azul-dark);margin-top:6px}
    .old-price{color:var(--dim);text-decoration:line-through;margin-left:8px;font-weight:600}
    .discount-badge{position:absolute;left:14px;top:14px;background:#E53935;color:#fff;padding:6px 10px;border-radius:8px;font-weight:800;font-size:13px}

    /* Rating stars */
    .rating{display:flex;align-items:center;gap:8px;font-size:14px}
    .stars{display:inline-flex;gap:4px}
    .star{width:16px;height:16px;display:inline-block;color:#FFD166}
    .star.empty{color:rgba(0,0,0,0.12)}

    /* Carousel inside product card */
    .prod-carousel{position:relative;width:100%;height:160px;border-radius:10px;overflow:hidden;background:#f0f2f5}
    .prod-carousel img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer}
    .carousel-controls{position:absolute;left:8px;right:8px;bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;pointer-events:none}
    .carousel-controls .left, .carousel-controls .right{pointer-events:auto;background:rgba(0,0,0,0.45);color:#fff;border:none;padding:6px 8px;border-radius:6px;cursor:pointer}
    .carousel-thumbs{display:flex;gap:6px;margin-top:8px;overflow:auto}
    .carousel-thumb{width:56px;height:40px;border-radius:6px;overflow:hidden;flex:0 0 auto;border:2px solid transparent;cursor:pointer}
    .carousel-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .carousel-thumb.active{border-color:var(--amarillo)}

    /* Modal */
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.7);display:none;align-items:center;justify-content:center;z-index:200}
    .modal.open{display:flex}
    .modal .box{max-width:1100px;width:95%;background:#fff;border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
    .modal .gallery{display:flex;align-items:center;gap:12px;padding:12px;background:#111;color:#fff}
    .modal .gallery img{max-height:70vh;object-fit:contain;width:100%}
    .modal .close{position:absolute;right:18px;top:18px;background:#fff;border-radius:50%;border:none;padding:8px;cursor:pointer}

    /* Pagination */
    .pagination{display:flex;gap:8px;justify-content:center;margin:28px 0}
    .pagination a, .pagination span{padding:8px 12px;border-radius:8px;background:#fff;border:1px solid rgba(0,0,0,0.06);text-decoration:none;color:var(--azul);font-weight:700}

    @media(max-width:1000px){.oferta-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:700px){.oferta-grid{grid-template-columns:1fr}.accesos{grid-template-columns:repeat(3,1fr)} .search-bar{display:block}}
  </style>
</head>
<body>

<header>
  <div class="nav-inner">
    <div class="brand" onclick="location.href='/'" role="button" aria-label="Ir a inicio">
      <div class="logo-img"><img src="https://picsum.photos/seed/mitienda/100/100" alt="Logo" style="width:100%;height:100%;object-fit:cover"></div>
      <b>Mi Tienda</b>
    </div>

    <!-- Search bar (global) - visible -->
    <div class="search-bar" role="search" aria-label="Buscar productos">
      <form id="searchForm" method="GET" action="" style="width:100%">
        <input id="searchInput" name="q" type="search" placeholder="Buscar producto, marca o referencia..." value="<?= htmlspecialchars($q) ?>" aria-label="Buscar producto">
        <button type="submit" aria-label="Buscar">Buscar</button>
      </form>
    </div>

    <nav aria-label="Navegación principal">
      <button onclick="scrollToSection('quienes-somos')">¿Quiénes somos?</button>
      <button onclick="scrollToSection('oferta')">Oferta Académica</button>
      <button onclick="scrollToSection('proyectos')">Proyectos</button>
      <button onclick="scrollToSection('galeria')">Galería</button>
      <button onclick="scrollToSection('noticias')">Noticias</button>
      <button onclick="scrollToSection('vida-estudiantil')">Vida estudiantil</button>
      <button onclick="scrollToSection('contacto')">Contáctenos</button>

      <a id="nav-carrito" href="/carrito.html" style="color:rgba(255,255,255,0.95);text-decoration:none;padding:8px 10px;border-radius:6px;margin-left:8px">Ver carrito</a>
      <button class="btn-admisiones" onclick="location.href='/contacto.php'">Admisiones</button>
    </nav>
  </div>
</header>

<!-- Hero -->
<div class="hero wrap" aria-hidden="true">
  <img src="https://picsum.photos/seed/hero1/1600/400" alt="Banner">
</div>

<!-- Accesos -->
<div class="accesos" role="navigation" aria-label="Accesos rápidos">
  <div class="acceso-item" onclick="applyQuickFilter('todas', this)"><div class="ic">📚</div><span>Formación Integral</span></div>
  <div class="acceso-item" onclick="applyQuickFilter('oferta', this)"><div class="ic">🏆</div><span>Excelencia Académica</span></div>
  <div class="acceso-item" onclick="applyQuickFilter('valores', this)"><div class="ic">⭐</div><span>Valores y Principios</span></div>
  <div class="acceso-item" onclick="applyQuickFilter('amor', this)"><div class="ic">❤️</div><span>Educación con Amor</span></div>
  <div class="acceso-item" onclick="applyQuickFilter('tec', this)"><div class="ic">💻</div><span>Innovación y Tecnología</span></div>
</div>

<!-- Intro -->
<section class="alt wrap" id="catalog-intro" style="padding:18px 20px">
  <span class="section-tag">Catálogo</span>
  <h2 class="sec-title">Nuestros Productos</h2>
  <p class="sec-sub">Busca por nombre, descripción o referencia. Paginación para evitar saturación; cada producto tiene su propio carrusel y lightbox.</p>
</section>

<!-- Grid -->
<main class="wrap" style="padding-bottom:40px">
  <div id="catalogGrid" class="oferta-grid" aria-live="polite">
    <?php foreach ($products as $p):
      // Preparar lista de imágenes
      $images = [];
      if (!empty($p['images_list'])) {
        $parts = explode('||', $p['images_list']);
        foreach ($parts as $f) {
          $f = trim($f);
          if ($f !== '') $images[] = '/mi_tienda/uploads/products/' . $f;
        }
      }
      if (empty($images)) $images[] = 'https://picsum.photos/seed/noimg/600/400';
      if (!empty($p['main_image'])) {
        $mainPath = '/mi_tienda/uploads/products/' . $p['main_image'];
        if (($k = array_search($mainPath, $images)) !== false) array_splice($images, $k, 1);
        array_unshift($images, $mainPath);
      }
      $dataImages = safe_json_attr($images);

      // Calcular badge de descuento si existen campos en el array $p (no asumimos columnas)
      $discountPercent = null;
      if (isset($p['old_price']) && is_numeric($p['old_price']) && floatval($p['old_price']) > floatval($p['price'])) {
        $discountPercent = round((1 - floatval($p['price']) / floatval($p['old_price'])) * 100);
      } elseif (isset($p['discount_percent']) && is_numeric($p['discount_percent'])) {
        $discountPercent = intval($p['discount_percent']);
      }

      // Rating (0-5) si existe en $p
      $rating = isset($p['rating']) && is_numeric($p['rating']) ? floatval($p['rating']) : 0;
      $rating = max(0, min(5, $rating));
    ?>
      <article class="oferta-card" data-cat="<?= htmlspecialchars($p['category'] ?? $p['categoria'] ?? 'todas') ?>">
        <?php if ($discountPercent): ?>
          <div class="discount-badge">-<?= $discountPercent ?>%</div>
        <?php endif; ?>
        <?php if (intval($p['stock']) <= 0): ?>
          <div class="badge-out">Sin stock</div>
        <?php endif; ?>

        <!-- Carousel container -->
        <div class="prod-carousel" data-images='<?= $dataImages ?>' data-init="false" aria-label="Galería del producto">
          <img class="carousel-main" src="<?= htmlspecialchars($images[0]) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
          <div class="carousel-controls">
            <button class="left" aria-label="Anterior">◀</button>
            <div class="counter" style="color:#fff;font-weight:700;padding:4px 8px;background:rgba(0,0,0,0.35);border-radius:6px;font-size:13px">1 / <?= count($images) ?></div>
            <button class="right" aria-label="Siguiente">▶</button>
          </div>
        </div>

        <!-- Thumbnails -->
        <div class="carousel-thumbs" aria-hidden="true">
          <?php foreach ($images as $idx => $img): ?>
            <div class="carousel-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>">
              <img data-src="<?= htmlspecialchars($img) ?>" alt="thumb" loading="lazy">
            </div>
          <?php endforeach; ?>
        </div>

        <h4><?= htmlspecialchars($p['name']) ?></h4>

        <!-- Rating -->
        <div class="rating" aria-label="Calificación del producto">
          <div class="stars" title="<?= number_format($rating,1) ?> de 5">
            <?php
              $full = floor($rating);
              $half = ($rating - $full) >= 0.5 ? 1 : 0;
              $empty = 5 - $full - $half;
              for ($i=0;$i<$full;$i++) echo '<span class="star">★</span>';
              if ($half) echo '<span class="star">☆</span>';
              for ($i=0;$i<$empty;$i++) echo '<span class="star empty">★</span>';
            ?>
          </div>
          <div style="color:var(--dim);font-size:13px">(<?= isset($p['rating']) ? number_format($p['rating'],1) : '0.0' ?>)</div>
        </div>

        <p class="meta"><?= htmlspecialchars(mb_strimwidth($p['description'] ?? '', 0, 120, '...')) ?></p>

        <div style="margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;gap:12px">
          <div>
            <div class="price">$<?= number_format($p['price'],2) ?>
              <?php if (isset($p['old_price']) && is_numeric($p['old_price']) && floatval($p['old_price']) > floatval($p['price'])): ?>
                <span class="old-price">$<?= number_format($p['old_price'],2) ?></span>
              <?php endif; ?>
            </div>
            <div class="meta">Stock: <?= intval($p['stock']) ?></div>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
            <button class="btn-link add-to-cart"
                    data-id="<?= htmlspecialchars($p['id']) ?>"
                    data-name="<?= htmlspecialchars($p['name']) ?>"
                    data-price="<?= htmlspecialchars($p['price']) ?>"
                    <?= (intval($p['stock']) <= 0) ? 'disabled' : '' ?>>
              Agregar al carrito
            </button>
            <a href="/product.php?id=<?= $p['id'] ?>" style="font-size:13px;text-decoration:none;color:var(--azul)">Ver detalle</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <div class="pagination" aria-label="Paginación">
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Anterior</a>
    <?php else: ?>
      <span style="opacity:.5">&laquo; Anterior</span>
    <?php endif; ?>

    <span style="display:flex;align-items:center;padding:8px 12px;background:#fff;border:1px solid rgba(0,0,0,0.06)">
      Página <?= $page ?> de <?= $totalPages ?>
    </span>

    <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Siguiente &raquo;</a>
    <?php else: ?>
      <span style="opacity:.5">Siguiente &raquo;</span>
    <?php endif; ?>
  </div>
</main>

<!-- Modal lightbox -->
<div id="modal" class="modal" role="dialog" aria-hidden="true">
  <div class="box" role="document" style="position:relative">
    <button class="close" aria-label="Cerrar" onclick="closeModal()">✕</button>
    <div style="background:#111;padding:12px;color:#fff;display:flex;align-items:center;gap:12px">
      <button id="modal-prev" style="background:transparent;border:1px solid rgba(255,255,255,0.2);color:#fff;padding:8px;border-radius:6px;cursor:pointer">◀</button>
      <div id="modal-image-wrap" style="flex:1;display:flex;align-items:center;justify-content:center">
        <img id="modal-image" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain">
      </div>
      <button id="modal-next" style="background:transparent;border:1px solid rgba(255,255,255,0.2);color:#fff;padding:8px;border-radius:6px;cursor:pointer">▶</button>
    </div>
    <div id="modal-caption" style="padding:12px;background:#fff;color:#111"></div>
  </div>
</div>

<!-- WA flotante -->
<a class="wa-float" href="https://wa.me/573163766890" target="_blank" rel="noopener" aria-label="WhatsApp" style="position:fixed;right:20px;bottom:20px;z-index:120">
  <svg fill="currentColor" viewBox="0 0 24 24" width="28" height="28"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
</a>

<script>
/* ---------- Robust carousel init ----------
   - Initialize carousels when DOM ready
   - Use IntersectionObserver to lazy-init and autoplay only when visible
   - Ensure thumbs and controls reference the correct carousel
*/
document.addEventListener('DOMContentLoaded', function () {
  const carousels = new Map();

  function initCarousel(el) {
    if (!el || el.dataset.init === 'true') return;
    el.dataset.init = 'true';

    let images = [];
    try {
      images = JSON.parse(el.getAttribute('data-images') || '[]');
    } catch (err) {
      images = [];
    }
    if (!Array.isArray(images) || images.length === 0) {
      // fallback: use current src
      const cur = el.querySelector('.carousel-main');
      if (cur && cur.src) images = [cur.src];
    }

    const mainImg = el.querySelector('.carousel-main');
    // thumbs are siblings in the same oferta-card
    const card = el.closest('.oferta-card');
    const thumbs = card ? card.querySelectorAll('.carousel-thumb') : [];
    const leftBtn = el.querySelector('.left');
    const rightBtn = el.querySelector('.right');
    const counter = el.querySelector('.counter');

    let idx = 0;

    // ensure thumbs load their images lazily and attach click handlers
    thumbs.forEach((t, i) => {
      const img = t.querySelector('img');
      if (img && !img.src) img.src = img.dataset.src || img.getAttribute('data-src') || '';
      t.addEventListener('click', (ev) => {
        ev.stopPropagation();
        show(i);
      });
    });

    function show(i) {
      if (!images || images.length === 0) return;
      if (i < 0) i = images.length - 1;
      if (i >= images.length) i = 0;
      idx = i;
      // update main image only if different
      if (mainImg && mainImg.getAttribute('src') !== images[idx]) {
        mainImg.setAttribute('src', images[idx]);
      }
      if (counter) counter.textContent = (idx + 1) + ' / ' + images.length;
      thumbs.forEach(t => t.classList.remove('active'));
      if (thumbs[idx]) thumbs[idx].classList.add('active');
    }

    leftBtn && leftBtn.addEventListener('click', (e) => { e.stopPropagation(); show(idx - 1); });
    rightBtn && rightBtn.addEventListener('click', (e) => { e.stopPropagation(); show(idx + 1); });

    // open modal on main image click
    if (mainImg) {
      mainImg.addEventListener('click', () => {
        const titleEl = card ? card.querySelector('h4') : null;
        openModal(images, idx, titleEl ? titleEl.textContent.trim() : '');
      });
    }

    // autoplay control
    let autoplay = null;
    function startAuto(){ if (!autoplay) autoplay = setInterval(()=> show(idx+1), 5000); }
    function stopAuto(){ if (autoplay) { clearInterval(autoplay); autoplay = null; } }

    carousels.set(el, { show, startAuto, stopAuto });
    show(0);
  }

  // IntersectionObserver to init when visible (with margin)
  const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const el = entry.target;
      if (entry.isIntersecting) {
        initCarousel(el);
        const c = carousels.get(el);
        if (c) c.startAuto();
      } else {
        const c = carousels.get(el);
        if (c) c.stopAuto();
      }
    });
  }, { root: null, rootMargin: '300px', threshold: 0.15 });

  // Observe all carousels
  document.querySelectorAll('.prod-carousel').forEach(el => {
    // If the element is already in viewport, init immediately to avoid "no controls" issue
    const rect = el.getBoundingClientRect();
    const inViewport = rect.top < window.innerHeight && rect.bottom > 0;
    if (inViewport) initCarousel(el);
    io.observe(el);
  });
});

/* ---------- Modal lightbox ---------- */
const modal = document.getElementById('modal');
const modalImage = document.getElementById('modal-image');
const modalCaption = document.getElementById('modal-caption');
let modalImages = [];
let modalIndex = 0;

function openModal(images, index, caption) {
  modalImages = Array.isArray(images) ? images.slice() : [];
  modalIndex = index || 0;
  if (!modalImages[modalIndex]) modalIndex = 0;
  modalImage.src = modalImages[modalIndex] || '';
  modalCaption.textContent = caption || '';
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
}
function closeModal() {
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  modalImage.src = '';
}
document.getElementById('modal-prev').addEventListener('click', () => {
  modalIndex = (modalIndex - 1 + modalImages.length) % modalImages.length;
  modalImage.src = modalImages[modalIndex];
});
document.getElementById('modal-next').addEventListener('click', () => {
  modalIndex = (modalIndex + 1) % modalImages.length;
  modalImage.src = modalImages[modalIndex];
});
modal.addEventListener('click', (e) => {
  if (e.target === modal) closeModal();
});

/* ---------- Quick filters ---------- */
function applyQuickFilter(cat, btn) {
  document.querySelectorAll('.tabs-row button').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.accesos .acceso-item').forEach(x => x.classList.remove('activo'));
  if (btn && btn.classList.contains('acceso-item')) btn.classList.add('activo');
  else if (btn) btn.classList.add('active');

  if (cat === 'todas' || cat === 'oferta') {
    document.querySelectorAll('#catalogGrid .oferta-card').forEach(el => el.style.display = 'flex');
    return;
  }
  document.querySelectorAll('#catalogGrid .oferta-card').forEach(el => {
    const c = (el.dataset.cat || '').toLowerCase();
    el.style.display = (c === cat.toLowerCase()) ? 'flex' : 'none';
  });
}

/* ---------- Carrito simple ---------- */
document.addEventListener('click', function(e){
  const btn = e.target.closest('.add-to-cart');
  if(!btn) return;
  if(btn.disabled) return;
  const id = btn.dataset.id;
  const name = btn.dataset.name;
  const price = parseFloat(btn.dataset.price || 0);
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  const existing = cart.find(i => i.id == id);
  if(existing) existing.qty = (existing.qty || 1) + 1;
  else cart.push({ id, name, price, qty: 1 });
  localStorage.setItem('cart_v1', JSON.stringify(cart));
  btn.textContent = 'Agregado';
  setTimeout(()=> btn.textContent = 'Agregar al carrito', 1200);
  const verCarrito = document.getElementById('nav-carrito');
  if(verCarrito) verCarrito.textContent = `Ver carrito (${cart.reduce((s,i)=>s+i.qty,0)})`;
});

/* ---------- Debounced client-side search helper (submits form) ---------- */
(function(){
  const input = document.getElementById('searchInput');
  if(!input) return;
  let timer = null;
  input.addEventListener('input', function(){
    clearTimeout(timer);
    timer = setTimeout(()=> {
      document.getElementById('searchForm').submit();
    }, 600);
  });
})();

/* ---------- Utility: scrollToSection fallback ---------- */
function scrollToSection(id){
  const el = document.getElementById('pag-' + id) || document.getElementById('catalog-intro');
  if(el) el.scrollIntoView({behavior:'smooth'});
}
</script>
</body>
</html>

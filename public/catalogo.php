<?php
// catalogo.php - Catálogo Optimizado para Ventas en Línea
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = DB::getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// --- Parámetros de paginación y búsqueda ---
$perPage = 24;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) *$perPage;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- Filtros ---
$where = [];$params = [];

// CONDICIÓN PRINCIPAL: Solo mostrar productos marcados como visibles para la web
$where[] = "p.visible_web = 1";

if ($q !== '') {$where[] = "(p.name LIKE :q OR p.description LIKE :q)";
    $params[':q'] = '\%' .$q . '%';
}
if (!empty($_GET['categoria'])) {$where[] = "(p.category = :categoria OR p.categoria = :categoria)";
    $params[':categoria'] =$_GET['categoria'];
}
if (isset($_GET['stock']) &&$_GET['stock'] !== '') {
    if (intval($_GET['stock']) === 1) {$where[] = "COALESCE(inv.stock, 0) > 0";
    } else {
        $where[] = "COALESCE(inv.stock, 0) <= 0";
    }
}
if (!empty($_GET['min'])) {$where[] = "p.price >= :min";
    $params[':min'] = floatval($_GET['min']);
}
if (!empty($_GET['max'])) {$where[] = "p.price <= :max";
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
$stmtCount =$pdo->prepare($countSql);$stmtCount->execute($params);$total = (int)$stmtCount->fetchColumn();$totalPages = max(1, ceil($total / $perPage));

// --- Consulta principal ---
$sql = "
    SELECT 
        p.*,
        COALESCE(inv.stock, 0) AS stock,
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
foreach ($params as$k => $v) {$stmt->bindValue($k,$v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);$stmt->execute();
$products =$stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Consulta para las 20 imágenes del carrusel promocional (Rotación semanal) ---
$bannerImages = [];
try {
    // RAND(YEARWEEK(NOW())) garantiza que las 20 imágenes aleatorias sean las mismas durante la semana actual
    $bannerSql = "
        SELECT pi.filename, p.name 
        FROM product_images pi
        JOIN products p ON p.id = pi.product_id
        WHERE p.visible_web = 1
        ORDER BY RAND(YEARWEEK(NOW()))
        LIMIT 20
    ";
    $stmtBanner = $pdo->query($bannerSql);
    while ($row = $stmtBanner->fetch(PDO::FETCH_ASSOC)) {
        $bannerImages[] = [
            'src'  => '/mi_tienda/uploads/products/' . $row['filename'],
            'alt'  => $row['name']
        ];
    }
} catch (Exception $e) {
    // Si falla la consulta, se mantiene el arreglo vacío
}

function safe_json_attr($data) {
    return htmlspecialchars(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi Tienda - Catálogo Virtual</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --max-width: 1120px; 
      --gap: 18px; 
      --card-min-height: 320px; 
      --carousel-height: 160px;
      --thumb-size: 56px; 
      --font-base: 15px; 
      --azul: #0B2545; 
      --azul-dark: #001529;
      --amarillo: #EEB902; 
      --bg: #F4F6F9; 
      --dim: #4B5563;
      --glass-bg: rgba(255, 255, 255, 0.92); 
      --glass-border: rgba(0, 0, 0, 0.06); 
      --glass-blur: blur(8px);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 100%; }
    body { font-family: 'Inter', system-ui, Arial; background: var(--bg); color: #1F2937; line-height: 1.45; }
    h1, h2, h3, h4, h5 { font-family: 'Poppins', sans-serif; }
    .wrap { max-width: var(--max-width); margin: 0 auto; padding: 0 20px; }

    /* Header */
    header { position: sticky; top: 0; background: rgba(0, 21, 41, 0.92); backdrop-filter: var(--glass-blur); padding: 10px 0; z-index: 120; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
    .nav-inner { display: flex; align-items: center; gap: 12px; max-width: var(--max-width); margin: 0 auto; padding: 0 20px; }
    .brand { display: flex; align-items: center; gap: 10px; cursor: pointer; text-decoration: none; }
    .brand .logo-img { width: 46px; height: 46px; border-radius: 50%; overflow: hidden; background: #fff; border: 2px solid var(--amarillo); display: flex; align-items: center; justify-content: center; }
    .brand b { color: #fff; font-weight: 800; font-size: 13px; text-transform: uppercase; }
    nav { display: flex; gap: 6px; flex: 1; justify-content: flex-end; align-items: center; }
    nav button, nav a { background: none; border: none; color: rgba(255, 255, 255, 0.95); padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; }
    .btn-cart { background: var(--amarillo) !important; color: var(--azul-dark) !important; font-weight: 700 !important; padding: 9px 16px !important; border-radius: 6px; }

    /* Search bar */
    .search-bar { display: flex; align-items: center; gap: 8px; margin-left: 12px; flex: 1; min-width: 240px; }
    .search-bar form { display: flex; gap: 8px; width: 100%; }
    .search-bar input[type="search"] { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 14px; background: #fff; }
    .search-bar button { padding: 10px 12px; border-radius: 8px; border: none; background: var(--amarillo); color: var(--azul-dark); font-weight: 700; cursor: pointer; }

    /* ESTILOS DEL CARRUSEL DE BANNERS */
    .hero-banner-carousel {
      position: relative;
      width: 100%;
      margin-bottom: 24px;
      overflow: hidden;
      border-radius: 14px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      background: #000;
    }

    .hero-banner-slides {
      display: flex;
      transition: transform 0.5s ease-in-out;
      width: 100%;
    }

    .hero-slide {
      min-width: 100%;
      box-sizing: border-box;
      position: relative;
    }

    .hero-slide img {
      width: 100%;
      height: 280px;
      object-fit: cover;
      display: block;
    }

    .hero-prev-btn, .hero-next-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(0, 0, 0, 0.5);
      color: #fff;
      border: none;
      font-size: 20px;
      padding: 12px 10px;
      cursor: pointer;
      border-radius: 6px;
      z-index: 10;
      transition: background 0.2s ease;
    }

    .hero-prev-btn:hover, .hero-next-btn:hover {
      background: rgba(0, 0, 0, 0.85);
    }

    .hero-prev-btn { left: 10px; }
    .hero-next-btn { right: 10px; }

    .hero-carousel-dots {
      position: absolute;
      bottom: 12px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      gap: 8px;
      z-index: 10;
    }

    .hero-dot {
      width: 10px;
      height: 10px;
      background: rgba(255, 255, 255, 0.5);
      border-radius: 50%;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .hero-dot.active {
      background: var(--amarillo);
      width: 24px;
      border-radius: 6px;
    }

    /* Grid & Cards */
    .oferta-grid { display: grid; grid-template-columns: 1fr; gap: var(--gap); }
    .oferta-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; min-height: var(--card-min-height); position: relative; transition: transform .18s ease; }
    .oferta-card:hover { transform: translateY(-6px); }
    .oferta-card h4 { color: var(--azul); font-size: 15px; margin: 8px 0; text-transform: uppercase; }
    .price { font-weight: 800; color: var(--azul-dark); font-size: 1.1rem; }
    .old-price { color: var(--dim); text-decoration: line-through; margin-left: 8px; font-size: 0.9rem; }
    .discount-badge { position: absolute; left: 14px; top: 14px; background: #E53935; color: #fff; padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 12px; z-index: 10; }
    .badge-out { position: absolute; right: 14px; top: 14px; background: #4B5563; color: #fff; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; z-index: 10; }
    
    /* Carousel */
    .prod-carousel { position: relative; width: 100%; height: var(--carousel-height); border-radius: 10px; overflow: hidden; background: #f0f2f5; }
    .prod-carousel img { width: 100%; height: 100%; object-fit: cover; display: block; cursor: pointer; }
    .carousel-controls { position: absolute; left: 8px; right: 8px; bottom: 8px; display: flex; justify-content: space-between; align-items: center; pointer-events: none; }
    .carousel-controls button { pointer-events: auto; background: rgba(0, 0, 0, 0.45); color: #fff; border: none; padding: 6px 8px; border-radius: 6px; cursor: pointer; }
    .carousel-thumbs { display: flex; gap: 6px; margin-top: 8px; overflow: auto; }
    .carousel-thumb { width: var(--thumb-size); height: calc(var(--thumb-size) * 0.72); border-radius: 6px; overflow: hidden; flex: 0 0 auto; border: 2px solid transparent; cursor: pointer; }
    .carousel-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .carousel-thumb.active { border-color: var(--amarillo); }

    /* Toast Notification */
    .toast-notification {
      position: fixed; bottom: 80px; right: 20px; background: var(--azul-dark); color: #fff;
      padding: 12px 20px; border-radius: 8px; border-left: 4px solid var(--amarillo);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); z-index: 300; transform: translateY(100px); opacity: 0; transition: all 0.3s ease;
    }
    .toast-notification.show { transform: translateY(0); opacity: 1; }

    /* Modal & Utility */
    .modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); display: none; align-items: center; justify-content: center; z-index: 200; }
    .modal.open { display: flex; }
    .modal .box { max-width: 1100px; width: 95%; background: #fff; border-radius: 10px; overflow: hidden; position: relative; }
    .modal .close { position: absolute; right: 18px; top: 18px; background: #fff; border-radius: 50%; border: none; padding: 8px; cursor: pointer; z-index: 10; }
    .pagination { display: flex; gap: 8px; justify-content: center; margin: 28px 0; }
    .pagination a, .pagination span { padding: 8px 12px; border-radius: 8px; background: #fff; border: 1px solid rgba(0, 0, 0, 0.06); text-decoration: none; color: var(--azul); font-weight: 700; }
    .btn-link { background: var(--azul); color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
    .btn-link:disabled { background: #ccc; cursor: not-allowed; }

    /* Responsive Breakdown */
    @media (min-width: 700px) { :root { --carousel-height: 200px; } .oferta-grid { grid-template-columns: repeat(2, 1fr); } .hero-slide img { height: 320px; } }
    @media (min-width: 1000px) { :root { --carousel-height: 220px; } .oferta-grid { grid-template-columns: repeat(3, 1fr); } .hero-slide img { height: 360px; } }
    @media (min-width: 1400px) { :root { --max-width: 1400px; --carousel-height: 260px; } .oferta-grid { grid-template-columns: repeat(4, 1fr); } .hero-slide img { height: 400px; } }
  </style>
</head>
<body>

<header>
  <div class="nav-inner">
    <a href="catalogo.php" class="brand">
      <div class="logo-img"><img src="https://picsum.photos/seed/mitienda/100/100" alt="Logo"></div>
      <b>Mi Tienda</b>
    </a>

    <div class="search-bar">
      <form id="searchForm" method="GET" action="catalogo.php">
        <input id="searchInput" name="q" type="search" placeholder="Buscar productos..." value="<?= htmlspecialchars($q) ?>">
        <button type="submit">Buscar</button>
      </form>
    </div>

    <nav>
      <a id="nav-carrito" class="btn-cart" href="carrito.php">Ver carrito (0)</a>
    </nav>
  </div>
</header>

<main class="wrap" style="padding-top:20px; padding-bottom:40px">

    <!-- CARRUSEL PROMOCIONAL SUPERIOR DINÁMICO -->
  <?php if (!empty($bannerImages)): ?>
    <div class="hero-banner-carousel">
      <button class="hero-prev-btn" onclick="moveHeroSlide(-1)">&#10094;</button>
      
      <div class="hero-banner-slides" id="heroBannerSlides">
        <?php foreach ($bannerImages as $img): ?>
          <div class="hero-slide">
            <img src="<?= htmlspecialchars($img['src']) ?>" alt="<?= htmlspecialchars($img['alt']) ?>" loading="lazy">
          </div>
        <?php endforeach; ?>
      </div>

      <button class="hero-next-btn" onclick="moveHeroSlide(1)">&#10095;</button>

      <div class="hero-carousel-dots" id="heroCarouselDots">
        <?php foreach ($bannerImages as $idx => $img): ?>
          <span class="hero-dot <?= $idx === 0 ? 'active' : '' ?>" onclick="goToHeroSlide(<?= $idx ?>)"></span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div id="catalogGrid" class="oferta-grid">
    <?php foreach ($products as $p):$images = [];
      if (!empty($p['images_list'])) {
        foreach (explode('||', $p['images_list']) as$f) {
          $f = trim($f);
          if ($f !== '') $images[] = '/mi_tienda/uploads/products/' .$f;
        }
      }
      if (empty($images))$images[] = 'https://picsum.photos/seed/noimg/600/400';
      if (!empty($p['main_image'])) {
        $mainPath = '/mi_tienda/uploads/products/' .$p['main_image'];
        if (($k = array_search($mainPath,$images)) !== false) array_splice($images,$k, 1);
        array_unshift($images,$mainPath);
      }
      $dataImages = safe_json_attr($images);

      $discountPercent = null;
      if (isset($p['old_price']) && is_numeric($p['old_price']) && floatval($p['old_price']) > floatval($p['price'])) {$discountPercent = round((1 - floatval($p['price']) / floatval($p['old_price'])) * 100);
      }
    ?>
      <article class="oferta-card" data-cat="<?= htmlspecialchars($p['category'] ?? $p['categoria'] ?? 'todas') ?>">
        <?php if ($discountPercent): ?>
          <div class="discount-badge">-<?= $discountPercent ?>%</div>
        <?php endif; ?>
        <?php if (intval($p['stock']) <= 0): ?>
          <div class="badge-out">Agotado</div>
        <?php endif; ?>

        <div class="prod-carousel" data-images='<?= $dataImages ?>' data-init="false">
          <img class="carousel-main" src="<?= htmlspecialchars($images[0]) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
          <div class="carousel-controls">
            <button class="left">◀</button>
            <div class="counter" style="color:#fff;font-weight:700;padding:2px 6px;background:rgba(0,0,0,0.4);border-radius:4px;font-size:12px">1 / <?= count($images) ?></div>
            <button class="right">▶</button>
          </div>
        </div>

        <div class="carousel-thumbs">
          <?php foreach ($images as $idx =>$img): ?>
            <div class="carousel-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>">
              <img data-src="<?= htmlspecialchars($img) ?>" alt="thumb" loading="lazy">
            </div>
          <?php endforeach; ?>
        </div>

        <h4><?= htmlspecialchars($p['name']) ?></h4>
        <p style="color:var(--dim);font-size:13px"><?= htmlspecialchars(mb_strimwidth($p['description'] ?? '', 0, 90, '...')) ?></p>

        <div style="margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;padding-top:10px">
          <div>
            <div class="price">$<?= number_format($p['price'], 2) ?></div>
            <div style="font-size:12px;color:var(--dim)">Stock: <?= intval($p['stock']) ?></div>
          </div>

          <button class="btn-link add-to-cart"
                  data-id="<?= htmlspecialchars($p['id']) ?>"
                  data-name="<?= htmlspecialchars($p['name']) ?>"
                  data-price="<?= htmlspecialchars($p['price']) ?>"
                  <?= (intval($p['stock']) <= 0) ? 'disabled' : '' ?>>
            <?= (intval($p['stock']) <= 0) ? 'Agotado' : 'Agregar' ?>
          </button>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' =>$page - 1])) ?>">&laquo; Anterior</a>
    <?php endif; ?>
    <span>Página <?= $page ?> de <?= $totalPages ?></span>
    <?php if ($page <$totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' =>$page + 1])) ?>">Siguiente &raquo;</a>
    <?php endif; ?>
  </div>
</main>

<div id="modal" class="modal">
  <div class="box">
    <button class="close" onclick="closeModal()">✕</button>
    <div style="background:#111;padding:12px;display:flex;align-items:center;justify-content:center">
      <img id="modal-image" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain">
    </div>
  </div>
</div>

<script>
// --- Lógica del Carrusel Banner Superior ---
let currentHeroSlide = 0;
const heroSlides = document.querySelectorAll('.hero-slide');
const totalHeroSlides = heroSlides.length;
const heroSlidesContainer = document.getElementById('heroBannerSlides');
const heroDots = document.querySelectorAll('.hero-dot');

function updateHeroCarousel() {
  if (!heroSlidesContainer) return;
  heroSlidesContainer.style.transform = `translateX(-${currentHeroSlide * 100}%)`;
  heroDots.forEach((dot, index) => {
    dot.classList.toggle('active', index === currentHeroSlide);
  });
}

function moveHeroSlide(direction) {
  currentHeroSlide = (currentHeroSlide + direction + totalHeroSlides) % totalHeroSlides;
  updateHeroCarousel();
}

function goToHeroSlide(index) {
  currentHeroSlide = index;
  updateHeroCarousel();
}

// Transición automática cada 4.5 segundos
setInterval(() => {
  moveHeroSlide(1);
}, 4500);

// --- Inicializador de Carrito ---
function updateCartCount() {
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  const totalQty = cart.reduce((s, i) => s + (i.qty || 0), 0);
  const el = document.getElementById('nav-carrito');
  if (el) el.textContent = `Ver carrito (${totalQty})`;
}

function showToast(msg) {
  let toast = document.getElementById('toast-msg');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast-msg';
    toast.className = 'toast-notification';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 2500);
}

document.addEventListener('DOMContentLoaded', function () {
  updateCartCount();

  // --- Lógica Carruseles ---
  document.querySelectorAll('.prod-carousel').forEach(el => {
    let images = [];
    try { images = JSON.parse(el.getAttribute('data-images') || '[]'); } catch(e){}
    const mainImg = el.querySelector('.carousel-main');
    const card = el.closest('.oferta-card');
    const thumbs = card ? card.querySelectorAll('.carousel-thumb') : [];
    const counter = el.querySelector('.counter');
    let idx = 0;

    thumbs.forEach((t, i) => {
      const img = t.querySelector('img');
      if (img) img.src = img.dataset.src || '';
      t.addEventListener('click', (e) => { e.stopPropagation(); show(i); });
    });

    function show(i) {
      if (!images.length) return;
      idx = (i + images.length) % images.length;
      if (mainImg) mainImg.src = images[idx];
      if (counter) counter.textContent = (idx + 1) + ' / ' + images.length;
      thumbs.forEach(t => t.classList.remove('active'));
      if (thumbs[idx]) thumbs[idx].classList.add('active');
    }

    el.querySelector('.left')?.addEventListener('click', (e) => { e.stopPropagation(); show(idx - 1); });
    el.querySelector('.right')?.addEventListener('click', (e) => { e.stopPropagation(); show(idx + 1); });
    mainImg?.addEventListener('click', () => openModal(images[idx]));
  });
});

// --- Evento Agregar al Carrito ---
document.addEventListener('click', function(e){
  const btn = e.target.closest('.add-to-cart');
  if(!btn || btn.disabled) return;

  const id = btn.dataset.id;
  const name = btn.dataset.name;
  const price = parseFloat(btn.dataset.price || 0);
  
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  const existing = cart.find(i => i.id == id);
  
  if(existing) {
    existing.qty += 1;
  } else {
    cart.push({ id, name, price, qty: 1 });
  }

  localStorage.setItem('cart_v1', JSON.stringify(cart));
  updateCartCount();
  showToast(`✔ "${name}" agregado al carrito`);
});

// Modal Lightbox
function openModal(src) {
  document.getElementById('modal-image').src = src;
  document.getElementById('modal').classList.add('open');
}
function closeModal() {
  document.getElementById('modal').classList.remove('open');
}
</script>
</body>
</html>
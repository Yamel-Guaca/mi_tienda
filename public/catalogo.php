<?php
// catalogo.php - Catálogo Virtual Integrado con Categorías Web Personalizadas
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = DB::getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// --- Parámetros de paginación y búsqueda ---
$perPage = 24;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- Parámetro de Categoría Web ---
$catId = isset($_GET['cat']) ? intval($_GET['cat']) : 0;

// --- Consultar lista de Categorías Web creadas por el administrador ---
$categories = [];
try {
    $stmtCat = $pdo->query("SELECT id, name FROM web_categories WHERE active = 1 ORDER BY sort_order ASC, name ASC");
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// --- Filtros SQL ---
$where = [];
$params = [];

// Mostrar solo productos visibles y activos
$where[] = "p.visible_web = 1";
$where[] = "p.active = 1";

// BUSCADOR: Busca por Nombre, SKU, Categoría interna/subcategoría o Categoría Web
if ($q !== '') {
    $where[] = "(
        p.name LIKE :q1 
        OR p.sku LIKE :q2 
        OR c.name LIKE :q3 
        OR s.name LIKE :q4
        OR p.id IN (
            SELECT wcp.product_id 
            FROM web_category_products wcp 
            JOIN web_categories wc ON wc.id = wcp.web_category_id 
            WHERE wc.name LIKE :q5
        )
    )";
    $qParam = '%' . $q . '%';
    $params[':q1'] = $qParam;
    $params[':q2'] = $qParam;
    $params[':q3'] = $qParam;
    $params[':q4'] = $qParam;
    $params[':q5'] = $qParam;
}

// FILTRO POR CATEGORÍA WEB PERSONALIZADA
if ($catId > 0) {
    $where[] = "p.id IN (SELECT product_id FROM web_category_products WHERE web_category_id = :catId)";
    $params[':catId'] = $catId;
}

if (isset($_GET['stock']) && $_GET['stock'] !== '') {
    if (intval($_GET['stock']) === 1) {
        $where[] = "COALESCE(inv.stock, 0) > 0";
    } else {
        $where[] = "COALESCE(inv.stock, 0) <= 0";
    }
}

if (!empty($_GET['min'])) {
    $where[] = "COALESCE(p.price_unit, p.price) >= :min";
    $params[':min'] = floatval($_GET['min']);
}
if (!empty($_GET['max'])) {
    $where[] = "COALESCE(p.price_unit, p.price) <= :max";
    $params[':max'] = floatval($_GET['max']);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Conteo total para paginación ---
$countSql = "
    SELECT COUNT(DISTINCT p.id) AS total
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS stock FROM inventory GROUP BY product_id
    ) inv ON inv.product_id = p.id
    $whereSql
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// --- Consulta principal de productos ---
$sql = "
    SELECT 
        p.*,
        COALESCE(p.price_unit, p.price, 0) AS price,
        COALESCE(inv.stock, 0) AS stock,
        (
            SELECT GROUP_CONCAT(wc.name SEPARATOR ', ')
            FROM web_category_products wcp
            JOIN web_categories wc ON wc.id = wcp.web_category_id
            WHERE wcp.product_id = p.id AND wc.active = 1
        ) AS web_categories_names,
        (
            SELECT filename 
            FROM product_images 
            WHERE product_id = p.id AND is_main = 1 
            LIMIT 1
        ) AS main_image,
        (
            SELECT GROUP_CONCAT(CONCAT(id, ':', filename) SEPARATOR '||') 
            FROM product_images 
            WHERE product_id = p.id
        ) AS images_list
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS stock FROM inventory GROUP BY product_id
    ) inv ON inv.product_id = p.id
    $whereSql
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Cargar Presentaciones (product_prices) ---
$productIds = array_column($products, 'id');
$pricesByProduct = [];
if (!empty($productIds)) {
    $inClause = implode(',', array_fill(0, count($productIds), '?'));
    try {
        $stmtPrices = $pdo->prepare("SELECT * FROM product_prices WHERE product_id IN ($inClause) ORDER BY id ASC");
        $stmtPrices->execute($productIds);
        foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pricesByProduct[$row['product_id']][] = $row;
        }
    } catch (Exception $e) {}
}

// --- Consulta para imágenes del carrusel promocional ---
$bannerImages = [];
try {
    $bannerSql = "
        SELECT pi.filename, p.name 
        FROM product_images pi
        JOIN products p ON p.id = pi.product_id
        WHERE p.visible_web = 1 AND p.active = 1
        ORDER BY RAND()
        LIMIT 20
    ";
    $stmtBanner = $pdo->query($bannerSql);
    while ($row = $stmtBanner->fetch(PDO::FETCH_ASSOC)) {
        $bannerImages[] = [
            'src' => '/mi_tienda/uploads/products/' . $row['filename'],
            'alt' => $row['name']
        ];
    }
} catch (Exception $e) {}

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
      --card-min-height: 340px; 
      --carousel-height: 180px;
      --thumb-size: 56px; 
      --azul: #0B2545; 
      --azul-dark: #001529;
      --amarillo: #EEB902; 
      --bg: #F4F6F9; 
      --dim: #4B5563;
      --glass-bg: rgba(255, 255, 255, 0.95); 
      --glass-border: rgba(0, 0, 0, 0.06); 
      --glass-blur: blur(8px);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', system-ui, Arial; background: var(--bg); color: #1F2937; line-height: 1.45; }
    h1, h2, h3, h4, h5 { font-family: 'Poppins', sans-serif; }
    .wrap { max-width: var(--max-width); margin: 0 auto; padding: 0 20px; }

    /* Header */
    header { position: sticky; top: 0; background: rgba(0, 21, 41, 0.95); backdrop-filter: var(--glass-blur); padding: 10px 0; z-index: 120; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
    .nav-inner { display: flex; align-items: center; gap: 12px; max-width: var(--max-width); margin: 0 auto; padding: 0 20px; flex-wrap: wrap; }
    .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
    .brand .logo-img { width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: #fff; border: 2px solid var(--amarillo); display: flex; align-items: center; justify-content: center; }
    .brand b { color: #fff; font-weight: 800; font-size: 14px; text-transform: uppercase; }
    
    /* Search Bar */
    .search-bar { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 200px; }
    .search-bar form { display: flex; gap: 8px; width: 100%; }
    .search-bar input[type="search"] { width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 14px; background: #fff; }
    .search-bar button { padding: 9px 14px; border-radius: 8px; border: none; background: var(--amarillo); color: var(--azul-dark); font-weight: 700; cursor: pointer; }
    .btn-clear-search { padding: 9px 12px; border-radius: 8px; background: #e2e8f0; color: #334155; text-decoration: none; font-size: 13px; font-weight: 600; display: flex; align-items: center; }

    nav { display: flex; gap: 6px; align-items: center; }
    .btn-cart { background: var(--amarillo) !important; color: var(--azul-dark) !important; font-weight: 700 !important; padding: 9px 16px !important; border-radius: 6px; text-decoration: none; }

    /* MENÚ DE CATEGORÍAS WEB */
    .cat-nav-bar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 10px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .cat-list { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; }
    .cat-item { padding: 6px 14px; border-radius: 20px; background: #f1f5f9; color: #334155; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; transition: all 0.2s; }
    .cat-item:hover, .cat-item.active { background: var(--azul); color: #fff; }

    /* CARRUSEL DE BANNERS */
    .hero-banner-carousel { position: relative; width: 100%; margin-bottom: 24px; overflow: hidden; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); background: #000; }
    .hero-banner-slides { display: flex; transition: transform 0.5s ease-in-out; width: 100%; }
    .hero-slide { min-width: 100%; box-sizing: border-box; position: relative; }
    .hero-slide img { width: 100%; height: 260px; object-fit: cover; display: block; }
    .hero-prev-btn, .hero-next-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0, 0, 0, 0.5); color: #fff; border: none; font-size: 20px; padding: 12px 10px; cursor: pointer; border-radius: 6px; z-index: 10; }
    .hero-prev-btn { left: 10px; }
    .hero-next-btn { right: 10px; }
    .hero-carousel-dots { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 10; }
    .hero-dot { width: 10px; height: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 50%; cursor: pointer; }
    .hero-dot.active { background: var(--amarillo); width: 24px; border-radius: 6px; }

    /* Grid & Cards */
    .oferta-grid { display: grid; grid-template-columns: 1fr; gap: var(--gap); }
    .oferta-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; min-height: var(--card-min-height); position: relative; transition: transform .18s ease; }
    .oferta-card:hover { transform: translateY(-4px); }
    .oferta-card h4 { color: var(--azul); font-size: 15px; margin: 8px 0 4px 0; text-transform: uppercase; }
    .price { font-weight: 800; color: var(--azul-dark); font-size: 1.15rem; }
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

    /* Select Presentaciones */
    .tier-select-wrapper { margin-top: 8px; margin-bottom: 4px; }
    .tier-select-wrapper label { font-size: 12px; font-weight: 600; color: var(--dim); display: block; margin-bottom: 2px; }
    .tier-select { width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 600; color: #334155; background: #fff; cursor: pointer; }

    /* Toast */
    .toast-notification { position: fixed; bottom: 30px; right: 20px; background: var(--azul-dark); color: #fff; padding: 12px 20px; border-radius: 8px; border-left: 4px solid var(--amarillo); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); z-index: 300; transform: translateY(100px); opacity: 0; transition: all 0.3s ease; }
    .toast-notification.show { transform: translateY(0); opacity: 1; }

    /* Modal */
    .modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); display: none; align-items: center; justify-content: center; z-index: 200; }
    .modal.open { display: flex; }
    .modal .box { max-width: 900px; width: 95%; background: #fff; border-radius: 10px; overflow: hidden; position: relative; }
    .modal .close { position: absolute; right: 18px; top: 18px; background: #fff; border-radius: 50%; border: none; padding: 8px; cursor: pointer; z-index: 10; font-weight: bold; }
    .pagination { display: flex; gap: 8px; justify-content: center; margin: 28px 0; }
    .pagination a, .pagination span { padding: 8px 12px; border-radius: 8px; background: #fff; border: 1px solid rgba(0, 0, 0, 0.06); text-decoration: none; color: var(--azul); font-weight: 700; }
    .btn-link { background: var(--azul); color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: background 0.2s; }
    .btn-link:hover { background: #001529; }
    .btn-link:disabled { background: #ccc; cursor: not-allowed; }

    @media (min-width: 700px) { :root { --carousel-height: 200px; } .oferta-grid { grid-template-columns: repeat(2, 1fr); } .hero-slide img { height: 320px; } }
    @media (min-width: 1000px) { :root { --carousel-height: 220px; } .oferta-grid { grid-template-columns: repeat(3, 1fr); } .hero-slide img { height: 360px; } }
    @media (min-width: 1400px) { :root { --max-width: 1400px; --carousel-height: 240px; } .oferta-grid { grid-template-columns: repeat(4, 1fr); } }
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
        <input id="searchInput" name="q" type="search" placeholder="Buscar por producto, SKU, categoría..." value="<?= htmlspecialchars($q) ?>">
        <button type="submit">Buscar</button>
        <?php if (!empty($q) || $catId > 0): ?>
          <a href="catalogo.php" class="btn-clear-search" title="Limpiar filtros">✕</a>
        <?php endif; ?>
      </form>
    </div>

    <nav>
      <a id="nav-carrito" class="btn-cart" href="carrito.php">Ver carrito (0)</a>
    </nav>
  </div>
</header>

<!-- BARRA DE NAVEGACIÓN DE CATEGORÍAS WEB PERSONALIZADAS -->
<div class="cat-nav-bar">
  <div class="wrap">
    <div class="cat-list">
      <a href="catalogo.php<?= !empty($q) ? '?q='.urlencode($q) : '' ?>" class="cat-item <?= $catId === 0 ? 'active' : '' ?>">Todas las categorías</a>
      <?php foreach ($categories as $c): ?>
        <a href="catalogo.php?cat=<?= (int)$c['id'] ?><?= !empty($q) ? '&q='.urlencode($q) : '' ?>" class="cat-item <?= $catId == $c['id'] ? 'active' : '' ?>">
          <?= htmlspecialchars($c['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<main class="wrap" style="padding-bottom:40px">

  <!-- CARRUSEL PROMOCIONAL SUPERIOR -->
  <?php if (!empty($bannerImages) && $catId === 0 && empty($q)): ?>
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
    <?php if (empty($products)): ?>
      <div style="grid-column: 1/-1; text-align: center; padding: 50px 20px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
        <h3 style="color: var(--azul); margin-bottom: 8px;">No se encontraron productos</h3>
        <p style="color: #64748b; font-size: 14px;">Intenta buscar con otros términos o limpia los filtros seleccionados.</p>
        <a href="catalogo.php" style="display: inline-block; margin-top: 14px; padding: 8px 16px; background: var(--amarillo); color: var(--azul-dark); font-weight: 700; border-radius: 6px; text-decoration: none;">Ver todo el catálogo</a>
      </div>
    <?php else: ?>
      <?php foreach ($products as $p): 
        $pId = (int)$p['id'];
        $images = [];
        $imageMap = [];

        if (!empty($p['images_list'])) {
          foreach (explode('||', $p['images_list']) as $item) {
            $parts = explode(':', $item, 2);
            if (count($parts) === 2) {
              $imgId = (int)$parts[0];
              $f = trim($parts[1]);
              if ($f !== '') {
                $imgPath = '/mi_tienda/uploads/products/' . $f;
                $images[] = $imgPath;
                $imageMap[$imgId] = $imgPath;
              }
            } else {
              $f = trim($item);
              if ($f !== '') $images[] = '/mi_tienda/uploads/products/' . $f;
            }
          }
        }

        if (empty($images)) $images[] = 'https://picsum.photos/seed/noimg/600/400';

        if (!empty($p['main_image'])) {
          $mainPath = '/mi_tienda/uploads/products/' . $p['main_image'];
          if (($k = array_search($mainPath, $images)) !== false) array_splice($images, $k, 1);
          array_unshift($images, $mainPath);
        }

        $images = array_values(array_unique($images));
        $dataImages = safe_json_attr($images);

        $discountPercent = null;
        if (isset($p['old_price']) && is_numeric($p['old_price']) && floatval($p['old_price']) > floatval($p['price']) && floatval($p['old_price']) > 0) {
          $discountPercent = round((1 - (floatval($p['price']) / floatval($p['old_price']))) * 100);
        }

        $tiers = $pricesByProduct[$pId] ?? [];
        $initialPrice = (float)$p['price'];
      ?>
        <article class="oferta-card" data-product-id="<?= $pId ?>">
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
            <?php foreach ($images as $idx => $img): ?>
              <div class="carousel-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>">
                <img data-src="<?= htmlspecialchars($img) ?>" alt="thumb" loading="lazy">
              </div>
            <?php endforeach; ?>
          </div>

          <h4><?= htmlspecialchars($p['name']) ?></h4>
          <?php if (!empty($p['web_categories_names'])): ?>
            <p style="color:var(--dim);font-size:12px;margin-bottom:4px;"><?= htmlspecialchars($p['web_categories_names']) ?></p>
          <?php endif; ?>

          <!-- SELECTOR DE PRESENTACIONES -->
          <?php if (!empty($tiers)): ?>
            <div class="tier-select-wrapper">
              <label>Presentación:</label>
              <select class="tier-select" data-product-id="<?= $pId ?>">
                <option value="0" 
                        data-price="<?= htmlspecialchars($initialPrice) ?>" 
                        data-label="Unidad base"
                        data-image="">
                  Unidad base ($<?= number_format($initialPrice, 2) ?>)
                </option>
                <?php foreach ($tiers as $t): 
                  $tImg = !empty($t['image_id']) && isset($imageMap[$t['image_id']]) ? $imageMap[$t['image_id']] : '';
                ?>
                  <option value="<?= (int)$t['id'] ?>" 
                          data-price="<?= htmlspecialchars($t['price']) ?>" 
                          data-label="<?= htmlspecialchars($t['unit_label']) ?>"
                          data-image="<?= htmlspecialchars($tImg) ?>">
                    <?= htmlspecialchars($t['unit_label']) ?> (x<?= (int)$t['quantity'] ?>) - $<?= number_format((float)$t['price'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div style="margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;padding-top:10px">
            <div>
              <div class="price price-display">$<?= number_format($initialPrice, 2) ?></div>
              <div style="font-size:12px;color:var(--dim)">Stock: <?= intval($p['stock']) ?></div>
            </div>

            <button class="btn-link add-to-cart"
                    data-id="<?= $pId ?>"
                    data-name="<?= htmlspecialchars($p['name']) ?>"
                    data-price="<?= $initialPrice ?>"
                    data-tier-label=""
                    <?= (intval($p['stock']) <= 0) ? 'disabled' : '' ?>>
              <?= (intval($p['stock']) <= 0) ? 'Agotado' : 'Agregar' ?>
            </button>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Anterior</a>
      <?php endif; ?>
      <span>Página <?= $page ?> de <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Siguiente &raquo;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
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
  if (totalHeroSlides <= 0) return;
  currentHeroSlide = (currentHeroSlide + direction + totalHeroSlides) % totalHeroSlides;
  updateHeroCarousel();
}

function goToHeroSlide(index) {
  currentHeroSlide = index;
  updateHeroCarousel();
}

if (totalHeroSlides > 0) {
  setInterval(() => {
    moveHeroSlide(1);
  }, 4500);
}

// --- Carrito & Toast ---
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

  // Carruseles de productos
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
    
    el.changeImageBySrc = function(src) {
      if (!src) return;
      const foundIdx = images.indexOf(src);
      if (foundIdx !== -1) {
        show(foundIdx);
      } else {
        if (mainImg) mainImg.src = src;
      }
    };
  });

  // Manejo de cambio de Presentaciones
  document.querySelectorAll('.tier-select').forEach(select => {
    select.addEventListener('change', function() {
      const card = this.closest('.oferta-card');
      if (!card) return;

      const opt = this.options[this.selectedIndex];
      const newPrice = parseFloat(opt.dataset.price || 0);
      const tierLabel = opt.dataset.label || '';
      const tierImage = opt.dataset.image || '';

      const priceDisplay = card.querySelector('.price-display');
      if (priceDisplay) {
        priceDisplay.textContent = '$' + newPrice.toFixed(2);
      }

      const btn = card.querySelector('.add-to-cart');
      if (btn) {
        btn.dataset.price = newPrice;
        btn.dataset.tierLabel = tierLabel !== 'Unidad base' ? tierLabel : '';
      }

      if (tierImage) {
        const carousel = card.querySelector('.prod-carousel');
        if (carousel && typeof carousel.changeImageBySrc === 'function') {
          carousel.changeImageBySrc(tierImage);
        }
      }
    });
  });
});

// Evento Agregar al Carrito
document.addEventListener('click', function(e){
  const btn = e.target.closest('.add-to-cart');
  if(!btn || btn.disabled) return;

  const id = btn.dataset.id;
  const baseName = btn.dataset.name;
  const price = parseFloat(btn.dataset.price || 0);
  const tierLabel = btn.dataset.tierLabel || '';
  
  const fullName = tierLabel ? `${baseName} (${tierLabel})` : baseName;
  const cartKey = tierLabel ? `${id}_${tierLabel}` : `${id}`;

  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  const existing = cart.find(i => (i.cartKey || i.id) == cartKey);
  
  if(existing) {
    existing.qty += 1;
  } else {
    cart.push({ id, cartKey, name: fullName, price, qty: 1 });
  }

  localStorage.setItem('cart_v1', JSON.stringify(cart));
  updateCartCount();
  showToast(`✔ "${fullName}" agregado al carrito`);
});

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
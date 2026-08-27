<?php
// public/product.php
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo "Producto no encontrado.";
    exit;
}

// Obtener producto
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        IFNULL(SUM(i.quantity),0) AS stock
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo "Producto no encontrado.";
    exit;
}

// Obtener galería de imágenes
$stmt = $pdo->prepare("
    SELECT id, filename, is_main
    FROM product_images
    WHERE product_id = ?
    ORDER BY is_main DESC, position ASC
");
$stmt->execute([$id]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($product['name']) ?> - Mi Tienda</title>
  <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
  <header class="site-header">
    <div class="logo">Mi Tienda</div>
    <nav>
      <a href="/index.php">Inicio</a>
      <a href="/carrito.html" id="ver-carrito">Ver carrito</a>
    </nav>
  </header>

  <main class="product-page">
    <section class="product-gallery">
      <?php if (!empty($images)): ?>
        <?php
          $main = $images[0];
        ?>
        <div class="product-main-image">
          <img 
            id="main-image"
            src="/mi_tienda/uploads/products/<?= htmlspecialchars($main['filename']) ?>" 
            alt="<?= htmlspecialchars($product['name']) ?>"
          >
        </div>
        <div class="product-thumbs">
          <?php foreach ($images as $img): ?>
            <img 
              src="/mi_tienda/uploads/products/<?= htmlspecialchars($img['filename']) ?>" 
              alt=""
              class="thumb-item <?= $img['is_main'] ? 'is-main' : '' ?>"
              onclick="changeMainImage(this.src)"
            >
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="product-main-image no-image">
          <span>Sin imagen</span>
        </div>
      <?php endif; ?>
    </section>

    <section class="product-info">
      <h1><?= htmlspecialchars($product['name']) ?></h1>
      <p class="product-price">$<?= number_format($product['price'], 2) ?></p>
      <p class="product-stock">
        <?= (int)$product['stock'] > 0 ? 'Disponible: ' . (int)$product['stock'] . ' unidades' : 'Sin stock' ?>
      </p>
      <p class="product-description">
        <?= nl2br(htmlspecialchars($product['description'] ?? '')) ?>
      </p>

      <button 
        class="btn-primary"
        id="add-to-cart-detail"
        data-id="<?= $product['id'] ?>"
        data-name="<?= htmlspecialchars($product['name']) ?>"
        data-price="<?= $product['price'] ?>"
      >
        Agregar al carrito
      </button>
    </section>
  </main>

  <script src="/js/cart.js"></script>
  <script>
    function changeMainImage(src) {
      const img = document.getElementById('main-image');
      if (img) img.src = src;
    }
  </script>
</body>
</html>

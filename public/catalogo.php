<?php
// public/index.php
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::getConnection();

$stmt = $pdo->query("
    SELECT 
        p.*,
        IFNULL(SUM(i.quantity),0) AS stock,
        (
            SELECT filename 
            FROM product_images 
            WHERE product_id = p.id AND is_main = 1 
            LIMIT 1
        ) AS main_image
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    GROUP BY p.id
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi Tienda - Catálogo</title>
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
  <main>
    <section class="filtros">
  <form method="GET" style="display:flex; gap:20px; width:100%;">

    <select name="categoria">
      <option value="">Categoría</option>
      <option value="aseo">Aseo</option>
      <option value="hogar">Hogar</option>
      <option value="alimentos">Alimentos</option>
    </select>

    <select name="stock">
      <option value="">Disponibilidad</option>
      <option value="1">Con stock</option>
      <option value="0">Sin stock</option>
    </select>

    <input type="number" name="min" placeholder="Precio mínimo">
    <input type="number" name="max" placeholder="Precio máximo">

    <button class="btn-primary">Filtrar</button>
  </form>
</section>
<section class="catalogo">
      <?php foreach($products as $p): ?>
        <article class="producto" data-id="<?= htmlspecialchars($p['id']) ?>">

        <?php if ($p['stock'] <= 0): ?>
          <div class="badge-out">Sin stock</div>
        <?php endif; ?>
  
        <?php if (!empty($p['main_image'])): ?>
            <img 
              src="/mi_tienda/uploads/products/<?= htmlspecialchars($p['main_image']) ?>" 
              alt="<?= htmlspecialchars($p['name']) ?>"
            >
          <?php endif; ?>

          <h2><?= htmlspecialchars($p['name']) ?></h2>
          <p><?= htmlspecialchars($p['description'] ?? '') ?></p>
          <p><strong>$<?= number_format($p['price'],2) ?></strong></p>
          <p>Stock: <?= intval($p['stock']) ?></p>

          <button
            class="add-to-cart"
            data-id="<?= htmlspecialchars($p['id']) ?>"
            data-name="<?= htmlspecialchars($p['name']) ?>"
            data-price="<?= htmlspecialchars($p['price']) ?>"
          >
            Agregar al carrito
          </button>

          <a 
            href="/product.php?id=<?= $p['id'] ?>" 
            style="margin-top:6px; font-size:13px; text-decoration:none; color:#0a6;"
          >
            Ver detalle
          </a>
        </article>
      <?php endforeach; ?>
    </section>
  </main>
  <script src="/js/cart.js"></script>
</body>
</html>

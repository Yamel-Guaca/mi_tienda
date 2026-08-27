<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::getConnection();

// Traer solo nombres de productos activos
$products = $pdo->query("SELECT name FROM products WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Listado de Productos</title>
  <style>
    body { font-family: Arial, sans-serif; background:#f7f7f7; margin:0; padding:0; }
    .container { max-width:800px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    h2 { text-align:center; }
    ul { list-style:none; padding:0; }
    li { padding:12px; border-bottom:1px solid #eee; font-size:18px; }
    .actions { margin:20px 0; text-align:center; }
    .btn { padding:10px 16px; border-radius:6px; border:0; cursor:pointer; margin:5px; }
    .btn.primary { background:#0078d4; color:#fff; }
    .btn.secondary { background:#0b5; color:#fff; }
  </style>
</head>
<body>
  
<div class="container">
  <h2>Listado de Productos</h2>
  <a href="/mi_tienda/admin/pos.php">Atras</a>
  <ul>
    <?php foreach ($products as $p): ?>
      <li><?= htmlspecialchars($p['name']) ?></li>
    <?php endforeach; ?>
  </ul>
  <div class="actions">
    <button class="btn primary" onclick="window.print()">Imprimir</button>
    <form method="post" action="export_excel.php" style="display:inline;">      
    </form>
  </div>
</div>
</body>
</html>

<?php
// admin/branch_visibility.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo administradores
require_role([1]);

$pdo = DB::getConnection();

// Cargar sucursales
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$branchId = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $branchId > 0) {
    $visibleCats = $_POST['cat_visible'] ?? [];   // array category_id => '1'
    $visibleSubs = $_POST['sub_visible'] ?? [];   // array subcategory_id => '1'
    $userId = $_SESSION['user']['id'] ?? null;

    $pdo->beginTransaction();
    try {
        // Upsert categorías
        $allCats = $pdo->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
        $stmtUpsertCat = $pdo->prepare("
            INSERT INTO branch_category_visibility (branch_id, category_id, visible, updated_by)
            VALUES (:bid, :cid, :vis, :uid)
            ON DUPLICATE KEY UPDATE visible = VALUES(visible), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($allCats as $cid) {
            $vis = isset($visibleCats[$cid]) ? 1 : 0;
            $stmtUpsertCat->execute([':bid'=>$branchId, ':cid'=>$cid, ':vis'=>$vis, ':uid'=>$userId]);
        }

        // Upsert subcategorías
        $allSubs = $pdo->query("SELECT id FROM subcategories")->fetchAll(PDO::FETCH_COLUMN);
        $stmtUpsertSub = $pdo->prepare("
            INSERT INTO branch_subcategory_visibility (branch_id, subcategory_id, visible, updated_by)
            VALUES (:bid, :sid, :vis, :uid)
            ON DUPLICATE KEY UPDATE visible = VALUES(visible), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($allSubs as $sid) {
            $vis = isset($visibleSubs[$sid]) ? 1 : 0;
            $stmtUpsertSub->execute([':bid'=>$branchId, ':sid'=>$sid, ':vis'=>$vis, ':uid'=>$userId]);
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = "Visibilidad actualizada para la sucursal.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = "Error al guardar: " . $e->getMessage();
    }
    header("Location: branch_visibility.php?branch_id={$branchId}");
    exit;
}

// Cargar categorías y subcategorías (agrupadas)
$cats = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subs = $pdo->query("SELECT id, category_id, name FROM subcategories ORDER BY category_id, name")->fetchAll(PDO::FETCH_ASSOC);

// Cargar visibilidades actuales para la sucursal seleccionada
$catVis = [];
$subVis = [];
if ($branchId > 0) {
    $stmt = $pdo->prepare("SELECT category_id, visible FROM branch_category_visibility WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $catVis[$r['category_id']] = (int)$r['visible'];

    $stmt = $pdo->prepare("SELECT subcategory_id, visible FROM branch_subcategory_visibility WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $subVis[$r['subcategory_id']] = (int)$r['visible'];
}

// Agrupar subcategorías por categoría para la UI
$subsByCat = [];
foreach ($subs as $s) {
    $subsByCat[$s['category_id']][] = $s;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Visibilidad por Sucursal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:Arial,Helvetica,sans-serif;margin:18px;color:#222}
    .box{max-width:980px;margin:0 auto}
    .row{display:flex;gap:12px;align-items:center;margin:8px 0}
    .col{flex:1}
    .card{border:1px solid #e1e1e1;padding:12px;border-radius:6px;margin:8px 0;background:#fafafa}
    h1,h2{margin:8px 0}
    .btn{padding:8px 12px;border-radius:6px;border:0;background:#0078d4;color:#fff;cursor:pointer}
    .btn-ghost{background:#f3f3f3;color:#222;border:1px solid #ddd}
    .cat-title{font-weight:700;margin-top:10px}
    .small{font-size:13px;color:#666}
    .flash{padding:8px;border-radius:6px;margin:8px 0}
    .flash.ok{background:#e6ffed;border:1px solid #b7f0c9;color:#0a6}
    .flash.err{background:#ffecec;border:1px solid #f0b7b7;color:#b00020}
  </style>
</head>
<body>
  <div class="box">
    <h1>Visibilidad de Categorías y Subcategorías por Sucursal</h1>

    <?php if (!empty($_SESSION['flash_msg'])): ?>
      <div class="flash ok"><?= htmlspecialchars($_SESSION['flash_msg']) ?></div>
      <?php unset($_SESSION['flash_msg']); endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="flash err"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
      <?php unset($_SESSION['flash_error']); endif; ?>

    <form method="GET" action="branch_visibility.php" class="row">
      <label class="col">
        <strong>Sucursal</strong>
        <select name="branch_id" onchange="this.form.submit()" style="width:100%;padding:8px;margin-top:6px">
          <option value="0">-- Selecciona --</option>
          <?php foreach($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $b['id']==$branchId ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="align-self:flex-end">
        <a href="/mi_tienda/admin/" class="btn-ghost" style="padding:8px 10px;text-decoration:none">Volver</a>
      </div>
    </form>

    <?php if ($branchId > 0): ?>
      <form method="POST" action="branch_visibility.php" id="visibility-form">
        <input type="hidden" name="branch_id" value="<?= $branchId ?>">
        <div class="card">
          <h2>Categorías</h2>
          <div class="small">Marca las categorías que deben estar visibles en el POS para la sucursal seleccionada.</div>
          <?php foreach ($cats as $c):
            $checked = array_key_exists($c['id'], $catVis) ? $catVis[$c['id']] : 1; ?>
            <div style="display:flex;align-items:center;gap:10px;margin:8px 0">
              <label style="flex:1">
                <input type="checkbox" name="cat_visible[<?= $c['id'] ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                <span class="cat-title"><?= htmlspecialchars($c['name']) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="card">
          <h2>Subcategorías</h2>
          <div class="small">Selecciona las subcategorías visibles. Puedes expandir/contraer por categoría.</div>

          <?php foreach ($cats as $c): 
            $catSubs = $subsByCat[$c['id']] ?? [];
            if (empty($catSubs)) continue;
          ?>
            <div style="margin-top:12px">
              <div style="display:flex;align-items:center;justify-content:space-between">
                <div><strong><?= htmlspecialchars($c['name']) ?></strong></div>
                <div><button type="button" class="btn-ghost" data-toggle="cat-<?= $c['id'] ?>">Alternar</button></div>
              </div>
              <div id="cat-<?= $c['id'] ?>" style="margin-left:14px;margin-top:8px">
                <?php foreach ($catSubs as $s):
                  $checked = array_key_exists($s['id'], $subVis) ? $subVis[$s['id']] : 1; ?>
                  <div style="display:flex;align-items:center;gap:10px;margin:6px 0">
                    <label style="flex:1">
                      <input type="checkbox" name="sub_visible[<?= $s['id'] ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                      <?= htmlspecialchars($s['name']) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:14px;display:flex;gap:8px">
            <button type="submit" class="btn">Guardar visibilidad</button>
            <button type="button" class="btn-ghost" id="reset-default">Restaurar por defecto</button>
            <button type="button" class="btn-ghost" id="clear-branch">Borrar configuración sucursal</button>
          </div>
        </div>
      </form>

      <script>
        // Alternar secciones por categoría
        document.querySelectorAll('[data-toggle]').forEach(btn=>{
          btn.addEventListener('click', function(){
            const id = this.getAttribute('data-toggle');
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = (el.style.display === 'none') ? 'block' : 'none';
          });
        });

        // Restaurar por defecto: desmarca todos (al guardar, el backend marcará 0/1 según checkboxes)
        document.getElementById('reset-default').addEventListener('click', function(){
          if (!confirm('Restaurar visibilidad por defecto (todo visible)?')) return;
          // Marcar todos los checkboxes como checked
          document.querySelectorAll('#visibility-form input[type="checkbox"]').forEach(cb => cb.checked = true);
        });

        // Borrar configuración de la sucursal (elimina filas para esta sucursal)
        document.getElementById('clear-branch').addEventListener('click', function(){
          if (!confirm('Borrar configuración de visibilidad para esta sucursal (volverá al comportamiento por defecto)?')) return;
          fetch('branch_visibility_clear.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ branch_id: <?= $branchId ?> })
          }).then(r=>r.json()).then(data=>{
            if (data && data.success) {
              alert('Configuración borrada. Ahora todo será visible por defecto.');
              window.location.reload();
            } else {
              alert('Error al borrar configuración.');
            }
          }).catch(()=>alert('Error de conexión.'));
        });
      </script>

    <?php endif; // branchId > 0 ?>

  </div>
</body>
</html>

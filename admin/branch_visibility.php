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
            $stmtUpsertCat->execute([':bid' => $branchId, ':cid' => $cid, ':vis' => $vis, ':uid' => $userId]);
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
            $stmtUpsertSub->execute([':bid' => $branchId, ':sid' => $sid, ':vis' => $vis, ':uid' => $userId]);
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = "Visibilidad actualizada correctamente para la sucursal.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = "Error al guardar la visibilidad: " . $e->getMessage();
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
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $catVis[$r['category_id']] = (int)$r['visible'];
    }

    $stmt = $pdo->prepare("SELECT subcategory_id, visible FROM branch_subcategory_visibility WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $subVis[$r['subcategory_id']] = (int)$r['visible'];
    }
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
    body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:#f7f7f7;color:#222}
    .box{max-width:980px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.05)}
    .row{display:flex;gap:12px;align-items:center;margin:12px 0}
    .col{flex:1}
    .card{border:1px solid #e1e1e1;padding:16px;border-radius:6px;margin:12px 0;background:#fafafa}
    h1,h2{margin:8px 0}
    .btn{padding:8px 14px;border-radius:6px;border:0;background:#0078d4;color:#fff;cursor:pointer;font-weight:600}
    .btn:hover{background:#005a9e}
    .btn-ghost{padding:8px 12px;border-radius:6px;background:#f3f3f3;color:#222;border:1px solid #ccc;cursor:pointer;text-decoration:none}
    .cat-title{font-weight:700}
    .small{font-size:13px;color:#666}
    .flash{padding:10px 14px;border-radius:6px;margin:10px 0;font-weight:600}
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
        <strong>Seleccionar Sucursal:</strong>
        <select name="branch_id" onchange="this.form.submit()" style="width:100%;padding:10px;margin-top:6px;border-radius:6px;border:1px solid #ccc">
          <option value="0">-- Selecciona una sucursal --</option>
          <?php foreach($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $b['id'] == $branchId ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="align-self:flex-end">
        <a href="/mi_tienda/admin/dashboard.php" class="btn-ghost">Volver al Dashboard</a>
      </div>
    </form>

    <?php if ($branchId > 0): ?>
      <form method="POST" action="branch_visibility.php" id="visibility-form">
        <input type="hidden" name="branch_id" value="<?= $branchId ?>">
        
        <div class="card">
          <h2>Categorías Visibles</h2>
          <div class="small" style="margin-bottom:12px;">Marca las categorías que estarán activas en el POS y catálogo para esta sucursal.</div>
          <?php foreach ($cats as $c):
            $checked = array_key_exists($c['id'], $catVis) ? $catVis[$c['id']] : 1; ?>
            <div style="display:flex;align-items:center;gap:10px;margin:8px 0">
              <label style="flex:1;cursor:pointer">
                <input type="checkbox" name="cat_visible[<?= $c['id'] ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                <span class="cat-title"><?= htmlspecialchars($c['name']) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card">
          <h2>Subcategorías Visibles</h2>
          <div class="small" style="margin-bottom:12px;">Selecciona las subcategorías permitidas por categoría.</div>

          <?php foreach ($cats as $c): 
            $catSubs = $subsByCat[$c['id']] ?? [];
            if (empty($catSubs)) continue;
          ?>
            <div style="margin-top:14px;padding-top:10px;border-top:1px solid #e5e5e5">
              <div style="display:flex;align-items:center;justify-content:space-between">
                <div><strong>Categoría: <?= htmlspecialchars($c['name']) ?></strong></div>
                <div><button type="button" class="btn-ghost" data-toggle="cat-<?= $c['id'] ?>">Contraer / Mostrar</button></div>
              </div>
              <div id="cat-<?= $c['id'] ?>" style="margin-left:16px;margin-top:10px">
                <?php foreach ($catSubs as $s):
                  $checked = array_key_exists($s['id'], $subVis) ? $subVis[$s['id']] : 1; ?>
                  <div style="display:flex;align-items:center;gap:10px;margin:6px 0">
                    <label style="flex:1;cursor:pointer">
                      <input type="checkbox" name="sub_visible[<?= $s['id'] ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                      <?= htmlspecialchars($s['name']) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="btn">Guardar visibilidad</button>
            <button type="button" class="btn-ghost" id="reset-default">Marcar Todo Visible</button>
            <button type="button" class="btn-ghost" id="clear-branch" style="color:#b00020">Borrar Configuración Sucursal</button>
          </div>
        </div>
      </form>

      <script>
        // Alternar secciones por categoría
        document.querySelectorAll('[data-toggle]').forEach(btn => {
          btn.addEventListener('click', function(){
            const id = this.getAttribute('data-toggle');
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = (el.style.display === 'none') ? 'block' : 'none';
          });
        });

        // Marcar todos como visibles en el formulario
        document.getElementById('reset-default').addEventListener('click', function(){
          if (!confirm('¿Desea marcar todas las categorías y subcategorías como visibles?')) return;
          document.querySelectorAll('#visibility-form input[type="checkbox"]').forEach(cb => cb.checked = true);
        });

        // Borrar configuración personalizada de la sucursal
        document.getElementById('clear-branch').addEventListener('click', function(){
          if (!confirm('¿Desea borrar la configuración de visibilidad personalizada de esta sucursal? Volverá al estado por defecto.')) return;
          fetch('branch_visibility_clear.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ branch_id: <?= $branchId ?> })
          }).then(r => r.json()).then(data => {
            if (data && data.success) {
              alert('Configuración borrada. Todo se mostrará por defecto.');
              window.location.reload();
            } else {
              alert('Error al borrar la configuración: ' + (data.message || 'Error desconocido'));
            }
          }).catch(() => alert('Error de conexión con el servidor.'));
        });
      </script>
    <?php endif; ?>
  </div>
</body>
</html>
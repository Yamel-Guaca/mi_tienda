<?php
// admin/transfer_create.php
// Ventana para crear un traslado de mercancía desde la sucursal actual hacia otra

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();
$user = $_SESSION['user'] ?? null;
if (!$user) {
    die("Acceso no autorizado.");
}

// PRUEBA TEMPORAL: forzar sucursal en sesión (quitar en producción)
if (empty($_SESSION['user']['branch_id']) || intval($_SESSION['user']['branch_id']) === 0) {
    $_SESSION['user']['branch_id'] = 1; // id de Sucursal Principal
    $_SESSION['user']['branch_name'] = 'Sucursal Principal';
}

$currentBranchId = intval($_SESSION['user']['branch_id'] ?? 0);
$userId = intval($user['id'] ?? 0);

// Cargar sucursales destino (excluyendo la actual)
$stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id != ? ORDER BY name");
$stmt->execute([$currentBranchId]);
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Procesar envío
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toBranch = intval($_POST['to_branch']);
    $items = json_decode($_POST['items_json'] ?? '[]', true);

    if ($toBranch && is_array($items) && count($items) > 0) {
        try {
            $pdo->beginTransaction();

            // Insertar traslado
            $stmt = $pdo->prepare("INSERT INTO transfers (from_branch_id,to_branch_id,sent_by,notes) VALUES (?,?,?,?)");
            $stmt->execute([$currentBranchId, $toBranch, $userId, $_POST['notes'] ?? null]);
            $transferId = $pdo->lastInsertId();

            foreach ($items as $it) {
                $pid = intval($it['product_id'] ?? 0);
                $qty = floatval($it['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;

                // Descontar stock en sucursal origen (usar columna quantity)
                $stmtLock = $pdo->prepare("SELECT quantity FROM stock WHERE branch_id = ? AND product_id = ? FOR UPDATE");
                $stmtLock->execute([$currentBranchId, $pid]);
                $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
                $currentQty = $row ? floatval($row['quantity']) : 0.0;
                if ($currentQty < $qty) throw new Exception("Stock insuficiente para producto ID {$pid}");

                $stmtUpd = $pdo->prepare("UPDATE stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");
                $stmtUpd->execute([$qty, $currentBranchId, $pid]);

                // Insertar item del traslado
                $stmtItem = $pdo->prepare("INSERT INTO transfer_items (transfer_id, product_id, qty) VALUES (?,?,?)");
                $stmtItem->execute([$transferId, $pid, $qty]);
            }

            $pdo->commit();
            echo "<script>alert('Traslado creado correctamente (ID {$transferId})');window.close();</script>";
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color:red'>Error: Debes seleccionar una sucursal destino y agregar al menos un producto.</p>";
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Traslado de Mercancía</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;padding:12px}
label{display:block;margin-top:8px}
input,select,textarea{padding:6px;margin-top:4px;width:100%}
button{padding:10px 14px;margin-top:12px;border-radius:6px;cursor:pointer}
.item-row{margin-top:6px}
</style>
</head>
<body>
<a href="/mi_tienda/admin/pos.php?mode=tactil">Atras</a>
<h2>Traslado de Mercancía (Sucursal actual: <?= htmlspecialchars($_SESSION['user']['branch_name'] ?? $currentBranchId) ?>)</h2>

<form id="frm" method="POST">
  <label>Enviar a sucursal:</label>
  <select name="to_branch" required>
    <option value="">-- Seleccione --</option>
    <?php foreach($branches as $b): ?>
      <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <h3>Productos</h3>
  <div id="items-area">
    <div class="item-row">
      Producto ID: <input type="number" class="pid" placeholder="ID producto">
      Cantidad: <input type="number" class="pqty" step="0.01" placeholder="Cantidad">
      <button type="button" class="add-row">+</button>
    </div>
  </div>

  <label>Notas:</label>
  <textarea name="notes"></textarea>

  <input type="hidden" name="items_json" id="items_json">
  <button type="button" id="sendBtn">Enviar traslado</button>
</form>

<script>
document.addEventListener('click', function(e){
  if(e.target.classList.contains('add-row')){
    var div = document.createElement('div'); div.className='item-row';
    div.innerHTML = 'Producto ID: <input type="number" class="pid"> Cantidad: <input type="number" class="pqty" step="0.01"> <button type="button" class="remove-row">-</button>';
    document.getElementById('items-area').appendChild(div);
  }
  if(e.target.classList.contains('remove-row')){
    e.target.parentElement.remove();
  }
});

document.getElementById('sendBtn').addEventListener('click', function(){
  var rows = document.querySelectorAll('.item-row');
  var items = [];
  rows.forEach(function(r){
    var pid = r.querySelector('.pid').value;
    var qty = r.querySelector('.pqty').value;
    if(pid && qty) items.push({product_id: pid, qty: qty});
  });
  if(items.length===0){ alert('Agrega al menos un producto'); return; }
  document.getElementById('items_json').value = JSON.stringify(items);
  document.getElementById('frm').submit();
});
</script>
</body>
</html>

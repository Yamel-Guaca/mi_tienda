<?php
// admin/transfer_list.php
// Lista traslados pendientes para la sucursal actual y permite ver detalles / aceptar

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();
$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(403);
    echo "Acceso no autorizado.";
    exit;
}

$currentBranchId = intval($user['branch_id'] ?? 0);
$userId = intval($user['id'] ?? 0);

// Cargar traslados pendientes dirigidos a la sucursal actual
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.from_branch_id, t.to_branch_id, t.status, t.sent_by, t.sent_at, t.notes,
               b_from.name AS from_branch_name, u_from.name AS sent_by_name
        FROM transfers t
        LEFT JOIN branches b_from ON b_from.id = t.from_branch_id
        LEFT JOIN users u_from ON u_from.id = t.sent_by
        WHERE t.to_branch_id = ? AND t.status = 'pending'
        ORDER BY t.sent_at ASC
    ");
    $stmt->execute([$currentBranchId]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $transfers = [];
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Traslados pendientes</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;padding:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border:1px solid #ddd;text-align:left}
.btn{padding:8px 12px;border-radius:6px;border:none;cursor:pointer}
.btn-accept{background:#28a745;color:#fff}
.btn-view{background:#007bff;color:#fff}
.note{font-size:13px;color:#555;margin-top:8px}
</style>
</head>
<body>
<h2>Traslados pendientes (Sucursal: <?= htmlspecialchars($user['branch_name'] ?? $currentBranchId) ?>)</h2>

<?php if (empty($transfers)): ?>
    <p>No hay traslados pendientes para esta sucursal.</p>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Desde</th>
                <th>Enviado por</th>
                <th>Fecha</th>
                <th>Notas</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transfers as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['id']) ?></td>
                <td><?= htmlspecialchars($t['from_branch_name'] ?? $t['from_branch_id']) ?></td>
                <td><?= htmlspecialchars($t['sent_by_name'] ?? $t['sent_by']) ?></td>
                <td><?= htmlspecialchars($t['sent_at']) ?></td>
                <td><?= nl2br(htmlspecialchars($t['notes'])) ?></td>
                <td>
                    <button class="btn btn-view" onclick="openDetails(<?= intval($t['id']) ?>)">Ver</button>
                    <button class="btn btn-accept" onclick="acceptTransfer(<?= intval($t['id']) ?>)">Aceptar</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="note">Al aceptar, los productos se sumarán al inventario de esta sucursal y el traslado quedará registrado con tu usuario.</div>

<!-- Modal simple para detalles -->
<div id="modal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">
  <div style="background:#fff;max-width:800px;margin:40px auto;padding:16px;border-radius:6px;position:relative;">
    <div id="modal-content">Cargando...</div>
    <div style="text-align:right;margin-top:12px;"><button onclick="closeModal()" class="btn">Cerrar</button></div>
  </div>
</div>

<script>
function openDetails(id){
  var modal = document.getElementById('modal');
  var content = document.getElementById('modal-content');
  content.innerHTML = 'Cargando...';
  modal.style.display = 'block';
  fetch('transfer_view.php?id=' + encodeURIComponent(id))
    .then(r => r.text())
    .then(html => { content.innerHTML = html; })
    .catch(e => { content.innerHTML = 'Error al cargar detalles.'; });
}
function closeModal(){ document.getElementById('modal').style.display = 'none'; }

function acceptTransfer(id){
  if(!confirm('¿Aceptar este traslado y sumar los productos al inventario de esta sucursal?')) return;
  fetch('transfer_accept.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'id=' + encodeURIComponent(id)
  }).then(r => r.json()).then(j => {
    if(j.success){
      alert('Traslado aceptado correctamente.');
      location.reload();
    } else {
      alert('Error: ' + (j.error || 'No se pudo aceptar el traslado.'));
    }
  }).catch(e => { alert('Error de comunicación.'); });
}
</script>
</body>
</html>

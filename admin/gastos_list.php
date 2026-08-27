<?php
// admin/gastos_list.php
session_start();
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$usuario = $_SESSION['user'];
$rol_id  = (int) $usuario['role_id'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$messages = [];

// ✅ Procesar anulación (solo Admin rol_id = 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular') {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $messages[] = "Token inválido.";
    } elseif ($rol_id !== 1) {
        $messages[] = "Solo el Administrador puede anular gastos.";
    } else {
        $gasto_id = (int) ($_POST['gasto_id'] ?? 0);
        if ($gasto_id > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE gastos
                    SET anulado = 1, anulado_by = :uid, anulado_at = NOW(), updated_at = NOW()
                    WHERE id = :id AND anulado = 0
                ");
                $stmt->execute([':uid'=>$usuario['id'], ':id'=>$gasto_id]);
                $messages[] = $stmt->rowCount() ? "Gasto $gasto_id anulado." : "No se pudo anular.";
            } catch (PDOException $e) {
                $messages[] = "Error al anular el gasto.";
            }
        }
    }
}

// ✅ Filtros
$filter_user = (int) ($_GET['usuario_id'] ?? 0);
$filter_from = $_GET['from'] ?? '';
$filter_to   = $_GET['to'] ?? '';
$export_csv  = ($_GET['export'] ?? '') === 'csv';

$where = ["1=1"];
$params = [];
if ($filter_user) { $where[]="g.usuario_id=:uid"; $params[':uid']=$filter_user; }
if ($filter_from) { $where[]="g.fecha>=:from"; $params[':from']=$filter_from; }
if ($filter_to)   { $where[]="g.fecha<=:to";   $params[':to']=$filter_to; }

/*
  Para mostrar la sucursal:
  - g.branch_id: si al guardar el gasto se almacenó la sucursal en la tabla gastos.
  - u.branch_id: sucursal asignada al usuario (fallback).
  Hacemos dos LEFT JOIN a branches: una para la sucursal del gasto (b), otra para la sucursal del usuario (ub).
  Luego usamos COALESCE(b.name, ub.name) AS branch_name.
*/
$sql = "
SELECT g.*, u.name AS usuario_nombre, r.name AS rol_nombre,
       COALESCE(b.name, ub.name) AS branch_name
FROM gastos g
LEFT JOIN users u ON u.id = g.usuario_id
LEFT JOIN roles r ON r.id = g.rol_id
LEFT JOIN branches b ON b.id = g.branch_id
LEFT JOIN branches ub ON ub.id = u.branch_id
WHERE ".implode(" AND ",$where)." ORDER BY g.created_at DESC LIMIT 500
";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$gastos=$stmt->fetchAll();

// ✅ Export CSV (incluye sucursal)
if ($export_csv) {
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=gastos_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['ID','Fecha','Hora','Usuario','Rol','Sucursal','Valor','Descripción','Referencia','Anulado','Created_at','Updated_at']);
    foreach($gastos as $g){
        fputcsv($out,[
            $g['id'],
            $g['fecha'],
            $g['hora'],
            $g['usuario_nombre'],
            $g['rol_nombre'],
            $g['branch_name'] ?? '',
            $g['valor'],
            $g['descripcion'],
            $g['referencia'],
            $g['anulado'] ? 'SI' : 'NO',
            $g['created_at'],
            $g['updated_at']
        ]);
    }
    fclose($out); exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Listado de Gastos</title>
<style>
body{font-family:Arial;margin:20px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f4f4f4}
.btn{padding:6px 10px;border:0;border-radius:4px;cursor:pointer}
.btn-primary{background:#007bff;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.filters{margin-bottom:12px}
.messages{color:#006400;margin-bottom:10px}
.small{font-size:13px;color:#555}
</style>
</head>
<body>
<h3>Reporte de Gastos</h3>

<p class="small"><strong>Usuario:</strong> <?=htmlspecialchars($usuario['name'])?> | <strong>Rol:</strong> <?=htmlspecialchars($usuario['role_name'])?></p>

<?php foreach($messages as $m) echo "<div class='messages'>".htmlspecialchars($m)."</div>"; ?>

<form method="GET" class="filters">
  <label>Usuario:
    <select name="usuario_id">
      <option value="0">Todos</option>
      <?php
      $uStmt = $pdo->query("SELECT id,name FROM users ORDER BY name");
      while($u = $uStmt->fetch()){
          $sel = ($filter_user == $u['id']) ? 'selected' : '';
          echo "<option value=\"".htmlspecialchars($u['id'])."\" $sel>".htmlspecialchars($u['name'])."</option>";
      }
      ?>
    </select>
  </label>
  &nbsp;
  <label>Desde: <input type="date" name="from" value="<?=htmlspecialchars($filter_from)?>"></label>
  &nbsp;
  <label>Hasta: <input type="date" name="to" value="<?=htmlspecialchars($filter_to)?>"></label>
  &nbsp;
  <button type="submit" class="btn btn-primary">Filtrar</button>
  &nbsp;
  <a class="btn" href="gastos_list.php?<?=http_build_query(array_merge($_GET,['export'=>'csv']))?>">Exportar CSV</a>
  <a href="/mi_tienda/admin/dashboard.php" class="btn">Atras</a>
</form>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Fecha</th>
      <th>Hora</th>
      <th>Usuario</th>
      <th>Rol</th>
      <th>Sucursal</th>
      <th>Valor</th>
      <th>Descripción</th>
      <th>Referencia</th>
      <th>Anulado</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($gastos as $g): ?>
      <tr style="<?=$g['anulado'] ? 'opacity:0.6;background:#f9d6d6' : ''?>">
        <td><?=htmlspecialchars($g['id'])?></td>
        <td><?=htmlspecialchars($g['fecha'])?></td>
        <td><?=htmlspecialchars($g['hora'])?></td>
        <td><?=htmlspecialchars($g['usuario_nombre'])?></td>
        <td><?=htmlspecialchars($g['rol_nombre'])?></td>
        <td><?=htmlspecialchars($g['branch_name'] ?? '')?></td>
        <td><?= '$'.number_format($g['valor'],2,',','.')?></td>
        <td><?=htmlspecialchars($g['descripcion'])?></td>
        <td><?=htmlspecialchars($g['referencia'])?></td>
        <td><?=$g['anulado'] ? 'SI' : 'NO'?></td>
        <td>
          <a class="btn" href="invoice_print.php?gasto_id=<?=$g['id']?>" target="_blank">Imprimir</a>
          <?php if (!$g['anulado'] && $rol_id === 1): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Confirmar anulación del gasto ID <?=$g['id']?>');">
              <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
              <input type="hidden" name="action" value="anular">
              <input type="hidden" name="gasto_id" value="<?=htmlspecialchars($g['id'])?>">
              <button type="submit" class="btn btn-danger">Anular</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>

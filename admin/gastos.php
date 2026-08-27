<?php
// admin/gastos.php
session_start();
require_once __DIR__ . '/../includes/config.php';

// Verificar sesión
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$usuario = $_SESSION['user'];
$rol_id  = (int) ($usuario['role_id'] ?? 0);

// Permitir solo Administrador (1) y Cajero (3)
if ($rol_id !== 1 && $rol_id !== 3) {
    http_response_code(403);
    echo "No tienes permisos para ingresar gastos.";
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$gasto_id = null;

// Determinar branch_id a usar al insertar
// Prioriza: session branch_id (por ejemplo al cambiar de sucursal en la sesión), luego usuario.branch_id
$branch_for_insert = $_SESSION['branch_id'] ?? $usuario['branch_id'] ?? null;
if ($branch_for_insert !== null) $branch_for_insert = (int)$branch_for_insert;

// Guardar gasto (ahora fuerza branch_id en el INSERT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Token de seguridad inválido.";
    } else {
        $valor = trim($_POST['valor'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $referencia = trim($_POST['referencia'] ?? '');

        if ($valor === '' || !is_numeric($valor) || (float)$valor <= 0) {
            $errors[] = "Ingrese un valor válido mayor a 0.";
        }
        if ($descripcion === '' || mb_strlen($descripcion) < 3) {
            $errors[] = "La descripción es obligatoria.";
        }

        if (empty($errors)) {
            try {
                $fecha = date("Y-m-d");
                $hora  = date("H:i:s");

                // INSERT incluyendo branch_id (forzado desde sesión/usuario)
                $stmt = $pdo->prepare("
                    INSERT INTO gastos (usuario_id, rol_id, branch_id, fecha, hora, valor, descripcion, referencia)
                    VALUES (:usuario_id, :rol_id, :branch_id, :fecha, :hora, :valor, :descripcion, :referencia)
                ");
                $stmt->execute([
                    ':usuario_id' => $usuario['id'],
                    ':rol_id'     => $rol_id,
                    ':branch_id'  => $branch_for_insert,
                    ':fecha'      => $fecha,
                    ':hora'       => $hora,
                    ':valor'      => number_format((float)$valor, 2, '.', ''),
                    ':descripcion'=> $descripcion,
                    ':referencia' => $referencia ?: null
                ]);

                $gasto_id = $pdo->lastInsertId();
                $success = true;
                // Regenerar token para evitar reenvíos
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                $errors[] = "Error al guardar el gasto.";
            }
        }
    }
}

// ✅ Consultar gastos del día actual para este usuario (no anulado)
// Traer también el nombre de la sucursal: primero g.branch_id, si no existe usar la sucursal del usuario
$hoy = date("Y-m-d");
$stmtHoy = $pdo->prepare("
    SELECT g.*,
           u.name AS usuario_nombre,
           r.name AS rol_nombre,
           COALESCE(b.name, ub.name, '') AS branch_name
    FROM gastos g
    LEFT JOIN users u ON u.id = g.usuario_id
    LEFT JOIN roles r ON r.id = g.rol_id
    LEFT JOIN branches b ON b.id = g.branch_id
    LEFT JOIN branches ub ON ub.id = u.branch_id
    WHERE g.usuario_id = :uid AND g.fecha = :hoy AND g.anulado = 0
    ORDER BY g.hora DESC
");
$stmtHoy->execute([':uid'=>$usuario['id'], ':hoy'=>$hoy]);
$gastosHoy = $stmtHoy->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Registro de Gastos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Arial;margin:20px}
.form-row{margin-bottom:10px}
label{display:block;font-weight:600;margin-bottom:4px}
input, select, textarea{width:100%;padding:8px;box-sizing:border-box}
.btn{padding:8px 12px;margin-right:8px;cursor:pointer;border:0;border-radius:4px}
.btn-primary{background:#007bff;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}
.errors{color:#b00020;margin-bottom:10px}
.success{color:#006400;margin-bottom:10px}
.box{border:1px solid #ccc;padding:10px;margin-top:20px;background:#f9f9f9}
.box h4{margin-top:0}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#eee}
.small{font-size:13px;color:#555}
.controls-row{display:flex;gap:8px;align-items:center}
.controls-row .btn{margin:0}
</style>
</head>
<body>
<h3>Registro de Gastos</h3>

<p>
<strong>Usuario:</strong> <?=htmlspecialchars($usuario['name'])?> |
<strong>Rol:</strong> <?=htmlspecialchars($usuario['role_name'])?> |
<strong>Fecha:</strong> <?=date("d-m-Y")?> |
<strong>Hora:</strong> <?=date("H:i:s")?>
</p>

<?php if (!empty($errors)): ?>
<div class="errors"><?php foreach($errors as $e) echo htmlspecialchars($e)."<br>"; ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success">Gasto guardado correctamente. ID: <?=htmlspecialchars($gasto_id)?></div>
<?php endif; ?>

<form method="POST" id="formGuardar">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
  <input type="hidden" name="action" value="guardar">

   
  <div class="form-row">
    <label>Valor del gasto</label>
    <input type="number" step="0.01" min="0.01" name="valor" required>
  </div>
  <div class="form-row">
    <label>Descripción</label>
    <input type="text" name="descripcion" required>
  </div>
  <div class="form-row">
    <label>Referencia (opcional)</label>
    <input type="text" name="referencia">
  </div>

  <div class="controls-row" style="margin-top:6px;">
    <button type="submit" class="btn btn-primary">Guardar</button>
    <button type="button" class="btn btn-secondary" onclick="window.location.href='/mi_tienda/admin/pos.php'">Atras - Abrir POS</button>
    <button type="button" class="btn btn-secondary" onclick="window.print();">Imprimir</button>
  </div>
</form>

<!-- Reimprimir: selector de gastos del día -->
<div class="box" style="margin-top:18px;">
  <h4>Reimprimir gasto del día</h4>
  <p class="small">Selecciona un gasto registrado hoy y haz clic en Reimprimir para abrir la tirilla.</p>

  <?php if ($gastosHoy): ?>
    <div style="display:flex;gap:8px;align-items:center;">
      <select id="reprint_select" aria-label="Seleccionar gasto para reimprimir">
        <?php foreach($gastosHoy as $g): 
            $label = sprintf("%s | %s | %s", $g['hora'], '$'.number_format($g['valor'],2,',','.'), mb_substr($g['descripcion'],0,60));
        ?>
          <option value="<?=htmlspecialchars($g['id'])?>"><?=htmlspecialchars($label)?></option>
        <?php endforeach; ?>
      </select>
      <button id="btnReimprimir" class="btn btn-primary" type="button">Reimprimir</button>
      <button id="btnReimprimirNuevaVentana" class="btn btn-secondary" type="button">Reimprimir en nueva ventana</button>
    </div>
  <?php else: ?>
    <p>No hay pagos registrados hoy para reimprimir.</p>
  <?php endif; ?>
</div>

<!-- ✅ Recuadro de gastos del día (listado) -->
<div class="box" style="margin-top:18px;">
  <h4>Pagos del día (<?=date("d-m-Y")?>)</h4>
  <?php if ($gastosHoy): ?>
    <table>
      <thead>
        <tr>
          <th>Hora</th>
          <th>Valor</th>
          <th>Descripción</th>
          <th>Referencia</th>
          <th>Sucursal</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($gastosHoy as $g): ?>
          <tr style="<?=($g['anulado'] ? 'opacity:0.6;background:#f9d6d6' : '')?>">
            <td><?=htmlspecialchars($g['hora'])?></td>
            <td><?= '$'.number_format($g['valor'],2,',','.')?></td>
            <td><?=htmlspecialchars($g['descripcion'])?></td>
            <td><?=htmlspecialchars($g['referencia'])?></td>
            <td><?=htmlspecialchars($g['branch_name'] ?? '')?></td>
            <td>
              <a class="btn btn-primary" href="invoice_print.php?gasto_id=<?=urlencode($g['id'])?>" target="_blank">Reimprimir</a>
              <?php if (!$g['anulado'] && $rol_id === 1): ?>
                <form method="POST" style="display:inline" action="gastos_list.php" onsubmit="return confirm('Confirmar anulación del gasto ID <?=htmlspecialchars($g['id'])?>');">
                  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                  <input type="hidden" name="action" value="anular">
                  <input type="hidden" name="gasto_id" value="<?=htmlspecialchars($g['id'])?>">
                  <button type="submit" class="btn" style="background:#dc3545;color:#fff;border-radius:4px;padding:6px 10px;">Anular</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>No hay pagos registrados hoy.</p>
  <?php endif; ?>
</div>

<script>
// Botón Reimprimir: abre la tirilla del gasto seleccionado
document.getElementById('btnReimprimir')?.addEventListener('click', function(){
  var sel = document.getElementById('reprint_select');
  if (!sel) return;
  var id = sel.value;
  if (!id) { alert('Seleccione un gasto para reimprimir.'); return; }
  window.open('invoice_print.php?gasto_id=' + encodeURIComponent(id), '_blank');
});

// Botón Reimprimir en nueva ventana (opción explícita)
document.getElementById('btnReimprimirNuevaVentana')?.addEventListener('click', function(){
  var sel = document.getElementById('reprint_select');
  if (!sel) return;
  var id = sel.value;
  if (!id) { alert('Seleccione un gasto para reimprimir.'); return; }
  window.open('invoice_print.php?gasto_id=' + encodeURIComponent(id), '_blank', 'noopener,noreferrer');
});
</script>
</body>
</html>

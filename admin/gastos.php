<?php
// admin/gastos.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Obtener conexión PDO estandarizada
$pdo = DB::getConnection();

// Verificar sesión de usuario
$usuario = $_SESSION['user'] ?? null;
$userId  = (int) ($usuario['id'] ?? $_SESSION['user_id'] ?? 0);
$rol_id  = (int) ($usuario['role_id'] ?? $_SESSION['role_id'] ?? 0);

if (!$userId || !$usuario) {
    header("Location: login.php");
    exit();
}

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

// Determinar branch_id activo
$branch_for_insert = $_SESSION['branch_id'] ?? $usuario['branch_id'] ?? null;
if ($branch_for_insert !== null) {
    $branch_for_insert = (int)$branch_for_insert;
}

if (!$branch_for_insert) {
    $errors[] = "No hay una sucursal seleccionada en el sistema.";
}

// Guardar gasto
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
        if (!$branch_for_insert) {
            $errors[] = "No se puede guardar el gasto sin una sucursal asignada.";
        }

        if (empty($errors)) {
            try {
                $fecha = date("Y-m-d");
                $hora  = date("H:i:s");

                $stmt = $pdo->prepare("
                    INSERT INTO gastos (usuario_id, rol_id, branch_id, fecha, hora, valor, descripcion, referencia)
                    VALUES (:usuario_id, :rol_id, :branch_id, :fecha, :hora, :valor, :descripcion, :referencia)
                ");
                $stmt->execute([
                    ':usuario_id' => $userId,
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
                error_log("Error guardando gasto: " . $e->getMessage());
                $errors[] = "Error al guardar el gasto en la base de datos.";
            }
        }
    }
}

// Consultar gastos del día actual para este usuario (no anulados)
$hoy = date("Y-m-d");
$stmtHoy = $pdo->prepare("
    SELECT g.*,
           u.name AS usuario_nombre,
           r.name AS rol_nombre,
           COALESCE(b.name, ub.name, 'Sin sucursal') AS branch_name
    FROM gastos g
    LEFT JOIN users u ON u.id = g.usuario_id
    LEFT JOIN roles r ON r.id = g.rol_id
    LEFT JOIN branches b ON b.id = g.branch_id
    LEFT JOIN branches ub ON ub.id = u.branch_id
    WHERE g.usuario_id = :uid AND g.fecha = :hoy AND COALESCE(g.anulado, 0) = 0
    ORDER BY g.hora DESC
");
$stmtHoy->execute([':uid' => $userId, ':hoy' => $hoy]);
$gastosHoy = $stmtHoy->fetchAll(PDO::FETCH_ASSOC);

$nombreUsuario = $usuario['name'] ?? $usuario['nombre'] ?? 'Usuario';
$nombreRol     = $usuario['role_name'] ?? $usuario['rol_nombre'] ?? ($rol_id === 1 ? 'Administrador' : 'Cajero');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Registro de Gastos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Arial, sans-serif;margin:20px;background:#f8fafc;color:#334155}
.container{max-width:900px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.form-row{margin-bottom:12px}
label{display:block;font-weight:600;margin-bottom:4px;font-size:14px}
input, select, textarea{width:100%;padding:9px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;font-size:14px}
.btn{padding:9px 14px;cursor:pointer;border:0;border-radius:6px;font-weight:600;font-size:14px;text-decoration:none;display:inline-block}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-secondary{background:#64748b;color:#fff}
.btn-secondary:hover{background:#475569}
.errors{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:6px;margin-bottom:12px}
.success{color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;padding:10px;border-radius:6px;margin-bottom:12px}
.box{border:1px solid #e2e8f0;padding:14px;margin-top:20px;background:#f8fafc;border-radius:6px}
.box h4{margin-top:0;color:#1e293b}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border:1px solid #e2e8f0;padding:8px;font-size:13px;text-align:left}
th{background:#f1f5f9;font-weight:600}
.small{font-size:13px;color:#64748b}
.controls-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>

<div class="container">
<h3>Registro de Gastos Local</h3>

<p class="small">
<strong>Usuario:</strong> <?=htmlspecialchars($nombreUsuario)?> |
<strong>Rol:</strong> <?=htmlspecialchars($nombreRol)?> |
<strong>Fecha:</strong> <?=date("d-m-Y")?> |
<strong>Hora:</strong> <?=date("H:i:s")?>
</p>

<?php if (!empty($errors)): ?>
<div class="errors">
    <?php foreach($errors as $e) echo htmlspecialchars($e)."<br>"; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success">Gasto guardado correctamente. ID: <?=htmlspecialchars((string)$gasto_id)?></div>
<?php endif; ?>

<form method="POST" id="formGuardar">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
  <input type="hidden" name="action" value="guardar">

  <div class="form-row">
    <label>Valor del gasto *</label>
    <input type="number" step="0.01" min="0.01" name="valor" required placeholder="0.00">
  </div>
  <div class="form-row">
    <label>Descripción *</label>
    <input type="text" name="descripcion" required placeholder="Ej: Pago de transporte, insumos de aseo...">
  </div>
  <div class="form-row">
    <label>Referencia (opcional)</label>
    <input type="text" name="referencia" placeholder="Ej: Factura o recibo N°">
  </div>

  <div class="controls-row" style="margin-top:15px;">
    <button type="submit" class="btn btn-primary">Guardar Gasto</button>
    <a href="pos.php" class="btn btn-secondary">⬅ Volver al POS</a>
    <button type="button" class="btn btn-secondary" onclick="window.print();">Imprimir Pantalla</button>
  </div>
</form>

<!-- Reimprimir: selector de gastos del día -->
<div class="box">
  <h4>Reimprimir comprobante del día</h4>
  <p class="small">Selecciona un gasto registrado hoy para abrir la tirilla de impresión.</p>

  <?php if ($gastosHoy): ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <select id="reprint_select" style="flex:1;min-width:240px;" aria-label="Seleccionar gasto para reimprimir">
        <?php foreach($gastosHoy as $g): 
            $label = sprintf("%s | %s | %s", $g['hora'], '$'.number_format((float)$g['valor'],2,',','.'), mb_substr($g['descripcion'],0,50));
        ?>
          <option value="<?=htmlspecialchars((string)$g['id'])?>"><?=htmlspecialchars($label)?></option>
        <?php endforeach; ?>
      </select>
      <button id="btnReimprimir" class="btn btn-primary" type="button">Reimprimir Tirilla</button>
    </div>
  <?php else: ?>
    <p class="small">No hay gastos registrados hoy para reimprimir.</p>
  <?php endif; ?>
</div>

<!-- Recuadro de gastos del día -->
<div class="box">
  <h4>Gastos registrados hoy (<?=date("d-m-Y")?>)</h4>
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
          <tr style="<?=!empty($g['anulado']) ? 'opacity:0.6;background:#fef2f2;' : ''?>">
            <td><?=htmlspecialchars($g['hora'])?></td>
            <td><strong>$<?=number_format((float)$g['valor'],2,',','.')?></strong></td>
            <td><?=htmlspecialchars($g['descripcion'])?></td>
            <td><?=htmlspecialchars($g['referencia'] ?? '-')?></td>
            <td><?=htmlspecialchars($g['branch_name'] ?? '')?></td>
            <td>
              <a class="btn btn-primary" style="padding:4px 8px;font-size:12px;" href="invoice_print.php?gasto_id=<?=urlencode($g['id'])?>" target="_blank">Imprimir</a>
              <?php if (empty($g['anulado']) && $rol_id === 1): ?>
                <form method="POST" style="display:inline" action="gastos_list.php" onsubmit="return confirm('¿Confirmar anulación del gasto ID <?=htmlspecialchars($g['id'])?>?');">
                  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                  <input type="hidden" name="action" value="anular">
                  <input type="hidden" name="gasto_id" value="<?=htmlspecialchars($g['id'])?>">
                  <button type="submit" class="btn" style="background:#dc2626;color:#fff;padding:4px 8px;font-size:12px;">Anular</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="small">No hay gastos registrados hoy.</p>
  <?php endif; ?>
</div>
</div>

<script>
// Auto-impresión si se acaba de guardar un gasto exitosamente
<?php if ($success && $gasto_id): ?>
window.open('invoice_print.php?gasto_id=<?= $gasto_id ?>', '_blank', 'width=400,height=600');
<?php endif; ?>

// Botón Reimprimir gasto seleccionado
document.getElementById('btnReimprimir')?.addEventListener('click', function(){
  var sel = document.getElementById('reprint_select');
  if (!sel) return;
  var id = sel.value;
  if (!id) { alert('Seleccione un gasto para reimprimir.'); return; }
  window.open('invoice_print.php?gasto_id=' + encodeURIComponent(id), '_blank', 'width=400,height=600');
});
</script>
</body>
</html>
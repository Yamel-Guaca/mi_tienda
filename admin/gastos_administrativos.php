<?php
// admin/gastos_administrativos.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// Verificar sesión
$usuario = $_SESSION['user'] ?? null;
$userId  = (int) ($usuario['id'] ?? $_SESSION['user_id'] ?? 0);
$rol_id  = (int) ($usuario['role_id'] ?? $_SESSION['role_id'] ?? 0);

if (!$userId || !$usuario) {
    header("Location: login.php");
    exit();
}

// Permitir solo Administradores (1) o Supervisores (2)
if ($rol_id !== 1 && $rol_id !== 2) {
    http_response_code(403);
    echo "Acceso denegado: Solo administradores o supervisores pueden ingresar gastos administrativos.";
    exit();
}

// --- MIGRACIÓN AUTOMÁTICA DE COLUMNAS DE COMPATIBILIDAD ---
try {
    $columns = $pdo->query("SHOW COLUMNS FROM gastos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tipo', $columns)) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN tipo VARCHAR(30) DEFAULT 'local'");
    }
    if (!in_array('categoria', $columns)) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN categoria VARCHAR(100) DEFAULT 'General'");
    }
    if (!in_array('metodo_pago', $columns)) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN metodo_pago VARCHAR(150) DEFAULT 'Efectivo'");
    }
    if (!in_array('comprobante_img', $columns)) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN comprobante_img VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('firma_img', $columns)) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN firma_img VARCHAR(255) DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log("Error al verificar/actualizar estructura de gastos: " . $e->getMessage());
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$gasto_id = null;

// Cargar sucursales para el selector
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$currentBranch = $_SESSION['branch_id'] ?? $usuario['branch_id'] ?? null;

// Guardar gasto administrativo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_admin') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Token de seguridad inválido.";
    } else {
        $pago_modo   = $_POST['pago_modo'] ?? 'unico';
        $categoria   = trim($_POST['categoria'] ?? 'Otros');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $referencia  = trim($_POST['referencia'] ?? '');
        $branch_id   = $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;

        $valorFinal  = 0.0;
        $metodoPagoTexto = '';

        if ($pago_modo === 'mixto') {
            $valEfectivo = max(0, (float)($_POST['val_efectivo'] ?? 0));
            $valTransf   = max(0, (float)($_POST['val_transferencia'] ?? 0));
            $valOtros    = max(0, (float)($_POST['val_otros'] ?? 0));
            
            $valorFinal = $valEfectivo + $valTransf + $valOtros;

            $partes = [];
            if ($valEfectivo > 0) $partes[] = "Efectivo: $" . number_format($valEfectivo, 0, ',', '.');
            if ($valTransf > 0)   $partes[] = "Transf: $" . number_format($valTransf, 0, ',', '.');
            if ($valOtros > 0)    $partes[] = "Otro: $" . number_format($valOtros, 0, ',', '.');

            $metodoPagoTexto = "Mixto (" . implode(" | ", $partes) . ")";
        } else {
            $valorFinal = (float)($_POST['valor_unico'] ?? 0);
            $metodoPagoTexto = trim($_POST['metodo_pago_unico'] ?? 'Efectivo');
        }

        if ($valorFinal <= 0) {
            $errors[] = "El valor total del gasto debe ser mayor a 0.";
        }
        if (empty($categoria)) {
            $errors[] = "Seleccione una categoría de gasto.";
        }
        if ($descripcion === '' || mb_strlen($descripcion) < 3) {
            $errors[] = "La descripción o concepto es obligatorio.";
        }

        // --- PROCESAMIENTO DE FIRMA DIGITAL (BASE64 CANVAS) ---
        $firma_filename = null;
        if (!empty($_POST['firma_base64'])) {
            $firma_data = $_POST['firma_base64'];
            if (preg_match('/^data:image\/(png|jpeg);base64,/', $firma_data)) {
                $base64_str = substr($firma_data, strpos($firma_data, ',') + 1);
                $decoded_data = base64_decode($base64_str);
                
                $uploadDirFirmas = __DIR__ . '/../uploads/firmas/';
                if (!is_dir($uploadDirFirmas)) {
                    mkdir($uploadDirFirmas, 0755, true);
                }
                $firma_filename = 'firma_' . time() . '_' . rand(100, 999) . '.png';
                if (!file_put_contents($uploadDirFirmas . $firma_filename, $decoded_data)) {
                    $errors[] = "No se pudo guardar la imagen de la firma.";
                }
            }
        }

        // --- PROCESAMIENTO OPCIONAL DE FOTO / COMPROBANTE ---
        $comprobante_filename = null;
        if (!empty($_FILES['comprobante_img']['name'])) {
            $ext = strtolower(pathinfo($_FILES['comprobante_img']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            if (in_array($ext, $allowed)) {
                $uploadDir = __DIR__ . '/../uploads/gastos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $comprobante_filename = 'gasto_admin_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (!move_uploaded_file($_FILES['comprobante_img']['tmp_name'], $uploadDir . $comprobante_filename)) {
                    $errors[] = "No se pudo subir la imagen del comprobante.";
                }
            } else {
                $errors[] = "Formato de archivo no permitido. Suba JPG, PNG, WEBP o PDF.";
            }
        }

        if (empty($errors)) {
            try {
                $fecha = date("Y-m-d");
                $hora  = date("H:i:s");

                $stmt = $pdo->prepare("
                    INSERT INTO gastos (usuario_id, rol_id, branch_id, fecha, hora, valor, descripcion, referencia, tipo, categoria, metodo_pago, comprobante_img, firma_img)
                    VALUES (:usuario_id, :rol_id, :branch_id, :fecha, :hora, :valor, :descripcion, :referencia, 'administrativo', :categoria, :metodo_pago, :comprobante_img, :firma_img)
                ");
                $stmt->execute([
                    ':usuario_id' => $userId,
                    ':rol_id'     => $rol_id,
                    ':branch_id'  => $branch_id,
                    ':fecha'      => $fecha,
                    ':hora'       => $hora,
                    ':valor'      => number_format($valorFinal, 2, '.', ''),
                    ':descripcion'=> $descripcion,
                    ':referencia' => $referencia ?: null,
                    ':categoria'  => $categoria,
                    ':metodo_pago'=> $metodoPagoTexto,
                    ':comprobante_img' => $comprobante_filename,
                    ':firma_img' => $firma_filename
                ]);

                $gasto_id = $pdo->lastInsertId();
                $success = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                error_log("Error guardando gasto administrativo: " . $e->getMessage());
                $errors[] = "Error al registrar el gasto en la base de datos.";
            }
        }
    }
}

// Filtros de búsqueda
$filtro_mes = $_GET['mes'] ?? date('Y-m');
$filtro_cat = $_GET['categoria_filtro'] ?? '';

$sqlGastos = "
    SELECT g.*, 
           u.name AS usuario_nombre, 
           r.name AS rol_nombre,
           b.name AS branch_name
    FROM gastos g
    LEFT JOIN users u ON u.id = g.usuario_id
    LEFT JOIN roles r ON r.id = g.rol_id
    LEFT JOIN branches b ON b.id = g.branch_id
    WHERE g.tipo = 'administrativo' 
      AND DATE_FORMAT(g.fecha, '%Y-%m') = :mes
      AND COALESCE(g.anulado, 0) = 0
";

$paramsSql = [':mes' => $filtro_mes];

if (!empty($filtro_cat)) {
    $sqlGastos .= " AND g.categoria = :cat";
    $paramsSql[':cat'] = $filtro_cat;
}

$sqlGastos .= " ORDER BY g.fecha DESC, g.hora DESC";
$stmtGastos = $pdo->prepare($sqlGastos);
$stmtGastos->execute($paramsSql);
$gastosAdmin = $stmtGastos->fetchAll(PDO::FETCH_ASSOC);

$totalMes = array_reduce($gastosAdmin, function($sum, $item) {
    return $sum + (float)$item['valor'];
}, 0.0);

$nombreUsuario = $usuario['name'] ?? 'Usuario';
$nombreRol     = $usuario['role_name'] ?? ($rol_id === 1 ? 'Administrador' : 'Supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Gastos Administrativos con Firma Digital</title>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<style>
body{font-family:Arial, sans-serif;margin:20px;background:#f1f5f9;color:#1e293b}
.container{max-width:1050px;margin:0 auto;background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06)}
.header-bar{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #e2e8f0;padding-bottom:12px;margin-bottom:20px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px}
.form-row{margin-bottom:12px}
.form-row.full{grid-column:1/-1}
label{display:block;font-weight:600;margin-bottom:6px;font-size:13px;color:#475569}
input, select, textarea{width:100%;padding:10px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff}
input:focus, select:focus, textarea:focus{border-color:#2563eb;outline:none}
.btn{padding:10px 16px;cursor:pointer;border:0;border-radius:6px;font-weight:600;font-size:14px;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-secondary{background:#64748b;color:#fff}
.btn-secondary:hover{background:#475569}
.btn-danger-outline{background:transparent;color:#dc2626;border:1px solid #dc2626;padding:6px 12px;font-size:12px;border-radius:4px;cursor:pointer;font-weight:600}
.btn-danger-outline:hover{background:#fef2f2}
.errors{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:6px;margin-bottom:16px}
.success{color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:6px;margin-bottom:16px}
.card-stats{display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.stat-box{flex:1;min-width:200px;background:#f8fafc;border:1px solid #e2e8f0;padding:16px;border-radius:8px}
.stat-box .title{font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase}
.stat-box .amount{font-size:22px;font-weight:800;color:#0f172a;margin-top:4px}
.pay-box{background:#f8fafc;border:1px solid #cbd5e1;padding:14px;border-radius:8px;margin-bottom:16px}
.signature-box{background:#fff;border:2px dashed #94a3b8;border-radius:8px;padding:10px;text-align:center;position:relative}
#signature-pad{border:1px solid #cbd5e1;background:#fff;border-radius:6px;cursor:crosshair;touch-action:none;width:100%;max-width:450px;height:160px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{border:1px solid #e2e8f0;padding:10px;font-size:13px;text-align:left}
th{background:#f8fafc;font-weight:700;color:#334155}
.badge{padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;display:inline-block;background:#e2e8f0;color:#334155}
.badge-cat{background:#dbeafe;color:#1e40af}
.small{font-size:13px;color:#64748b}
.img-link{color:#0284c7;text-decoration:none;font-weight:bold;display:inline-flex;align-items:center;gap:4px}
.img-link:hover{text-decoration:underline}
.thumb-firma{height:35px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;padding:2px}
</style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <div>
            <h2 style="margin:0;color:#0f172a;">Gestión de Gastos Administrativos</h2>
            <p class="small" style="margin:4px 0 0 0;">
                <strong>Usuario:</strong> <?=htmlspecialchars($nombreUsuario)?> (<?=htmlspecialchars($nombreRol)?>)
            </p>
        </div>
        <div>
            <a href="gastos_list.php" class="btn btn-secondary">📋 Ver Todos los Gastos</a>
            <a href="dashboard.php" class="btn btn-secondary">⬅ Dashboard</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach($errors as $e) echo "• " . htmlspecialchars($e) . "<br>"; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">✓ Gasto administrativo registrado con éxito. ID: <?=htmlspecialchars((string)$gasto_id)?></div>
    <?php endif; ?>

    <!-- FORMULARIO PRINCIPAL -->
    <form method="POST" id="formGastoAdmin" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="guardar_admin">
        <input type="hidden" name="firma_base64" id="firma_base64">

        <div class="form-grid">
            <div class="form-row">
                <label>Categoría Administrativa *</label>
                <select name="categoria" id="categoria_select" required>
                    <option value="">-- Seleccionar Categoría --</option>
                    <option value="Nómina / Salarios">Nómina / Salarios</option>
                    <option value="Arriendo / Alquiler">Arriendo / Alquiler</option>
                    <option value="Servicios Públicos (Agua/Luz/Gas/Internet)">Servicios Públicos (Agua/Luz/Gas/Internet)</option>
                    <option value="Pago a Proveedores / Insumos">Pago a Proveedores / Insumos</option>
                    <option value="Impuestos / Tasas / Contribuciones">Impuestos / Tasas / Contribuciones</option>
                    <option value="Mantenimiento y Reparaciones">Mantenimiento y Reparaciones</option>
                    <option value="Honorarios / Asesorías">Honorarios / Asesorías</option>
                    <option value="Papelería / Útiles de Oficina">Papelería / Útiles de Oficina</option>
                    <option value="Transporte / Viáticos">Transporte / Viáticos</option>
                    <option value="Otros Gastos Administrativos">Otros Gastos Administrativos</option>
                </select>
            </div>

            <div class="form-row">
                <label>Sucursal Afectada</label>
                <select name="branch_id">
                    <option value="">-- Oficina Central / Gasto Global --</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?=$b['id']?>" <?=$currentBranch == $b['id'] ? 'selected' : ''?>>
                            <?=htmlspecialchars($b['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- FORMA DE PAGO: ÚNICO O MIXTO -->
            <div class="form-row full pay-box">
                <label style="font-size:14px;color:#0f172a;margin-bottom:10px;">Modalidad de Pago *</label>
                <div style="display:flex;gap:20px;margin-bottom:12px;">
                    <label style="font-weight:normal;cursor:pointer;">
                        <input type="radio" name="pago_modo" value="unico" checked onclick="togglePagoModo('unico')"> Pago Único
                    </label>
                    <label style="font-weight:normal;cursor:pointer;">
                        <input type="radio" name="pago_modo" value="mixto" onclick="togglePagoModo('mixto')"> Pago Dividido / Mixto (Efectivo + Transferencia)
                    </label>
                </div>

                <!-- PAGO ÚNICO -->
                <div id="pago_unico_container" class="form-grid">
                    <div>
                        <label>Monto / Valor ($) *</label>
                        <input type="number" step="0.01" min="0.01" id="valor_unico" name="valor_unico" placeholder="0.00">
                    </div>
                    <div>
                        <label>Canal de Pago *</label>
                        <select name="metodo_pago_unico">
                            <option value="Transferencia Bancaria">Transferencia Bancaria</option>
                            <option value="Efectivo">Efectivo</option>
                            <option value="Nequi / Daviplata">Nequi / Daviplata</option>
                            <option value="Tarjeta Débito / Crédito">Tarjeta Débito / Crédito</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                </div>

                <!-- PAGO MIXTO -->
                <div id="pago_mixto_container" class="form-grid" style="display:none;">
                    <div>
                        <label>Monto en Efectivo ($)</label>
                        <input type="number" step="0.01" min="0" class="calc-mixto" id="val_efectivo" name="val_efectivo" placeholder="0.00" oninput="calcularTotalMixto()">
                    </div>
                    <div>
                        <label>Monto Transferencia / Banco ($)</label>
                        <input type="number" step="0.01" min="0" class="calc-mixto" id="val_transferencia" name="val_transferencia" placeholder="0.00" oninput="calcularTotalMixto()">
                    </div>
                    <div>
                        <label>Monto Tarjeta / Otro ($)</label>
                        <input type="number" step="0.01" min="0" class="calc-mixto" id="val_otros" name="val_otros" placeholder="0.00" oninput="calcularTotalMixto()">
                    </div>
                    <div style="align-self:flex-end;">
                        <label>Total Calculado ($)</label>
                        <input type="text" id="total_mixto_display" value="$ 0,00" readonly style="background:#e2e8f0;font-weight:bold;">
                    </div>
                </div>
            </div>

            <div class="form-row full">
                <label>Concepto / Descripción Detallada *</label>
                <input type="text" name="descripcion" required placeholder="Ej: Pago quincena supervisor de tienda, comprobante adjunto...">
            </div>

            <div class="form-row">
                <label>N° Factura / N° Transacción de Referencia</label>
                <input type="text" name="referencia" placeholder="Ej: Ref #883920, Factura #104">
            </div>

            <div class="form-row">
                <label>Comprobante Adjunto / Foto (Opcional)</label>
                <input type="file" name="comprobante_img" accept="image/*,.pdf">
                <span class="small">JPG, PNG, WEBP o PDF.</span>
            </div>

            <!-- RECUADRO DE FIRMA DIGITAL (CELULAR / TÁCTIL) -->
            <div class="form-row full">
                <label>Firma Digital / Autorización en Pantalla (Opcional o según requerimiento)</label>
                <div class="signature-box">
                    <canvas id="signature-pad" width="450" height="160"></canvas>
                    <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;max-width:450px;margin-left:auto;margin-right:auto;">
                        <span class="small">✍️ Firma con el dedo o lápiz táctil en tu celular</span>
                        <button type="button" class="btn-danger-outline" onclick="clearSignature()">🗑️ Limpiar Firma</button>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top:15px;">
            <button type="submit" class="btn btn-primary">💾 Registrar Gasto Administrativo</button>
        </div>
    </form>

    <hr style="margin:30px 0;border:0;border-top:1px solid #e2e8f0;">

    <!-- HISTÓRICO Y RESULTADOS -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;color:#0f172a;">Histórico Administrativo</h3>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="month" name="mes" value="<?=htmlspecialchars($filtro_mes)?>" style="padding:6px 10px;font-size:13px;">
            <select name="categoria_filtro" style="padding:6px 10px;font-size:13px;">
                <option value="">Todas las categorías</option>
                <option value="Nómina / Salarios" <?=$filtro_cat==='Nómina / Salarios'?'selected':''?>>Nómina / Salarios</option>
                <option value="Arriendo / Alquiler" <?=$filtro_cat==='Arriendo / Alquiler'?'selected':''?>>Arriendo / Alquiler</option>
                <option value="Servicios Públicos (Agua/Luz/Gas/Internet)" <?=$filtro_cat==='Servicios Públicos (Agua/Luz/Gas/Internet)'?'selected':''?>>Servicios Públicos</option>
                <option value="Pago a Proveedores / Insumos" <?=$filtro_cat==='Pago a Proveedores / Insumos'?'selected':''?>>Proveedores</option>
                <option value="Impuestos / Tasas / Contribuciones" <?=$filtro_cat==='Impuestos / Tasas / Contribuciones'?'selected':''?>>Impuestos</option>
            </select>
            <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:13px;">Filtrar</button>
        </form>
    </div>

    <div class="card-stats">
        <div class="stat-box">
            <div class="title">Total Administrativo Mes (<?=$filtro_mes?>)</div>
            <div class="amount">$<?=number_format($totalMes, 2, ',', '.')?></div>
        </div>
        <div class="stat-box">
            <div class="title">Registros del Mes</div>
            <div class="amount"><?=count($gastosAdmin)?></div>
        </div>
    </div>

    <?php if ($gastosAdmin): ?>
        <table>
            <thead>
                <tr>
                    <th>Fecha / Hora</th>
                    <th>Categoría</th>
                    <th>Concepto / Descripción</th>
                    <th>Valor</th>
                    <th>Método / Pago</th>
                    <th>Firma</th>
                    <th>Soporte</th>
                    <th>Sucursal</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($gastosAdmin as $g): ?>
                    <tr>
                        <td><?=htmlspecialchars($g['fecha'])?><br><span class="small"><?=htmlspecialchars($g['hora'])?></span></td>
                        <td><span class="badge badge-cat"><?=htmlspecialchars($g['categoria'] ?? 'General')?></span></td>
                        <td>
                            <strong><?=htmlspecialchars($g['descripcion'])?></strong>
                            <?php if(!empty($g['referencia'])): ?>
                                <br><span class="small">Ref: <?=htmlspecialchars($g['referencia'])?></span>
                            <?php endif; ?>
                        </td>
                        <td><strong>$<?=number_format((float)$g['valor'], 2, ',', '.')?></strong></td>
                        <td><span class="badge"><?=htmlspecialchars($g['metodo_pago'] ?? 'Efectivo')?></span></td>
                        <td>
                            <?php if (!empty($g['firma_img'])): ?>
                                <a href="../uploads/firmas/<?=htmlspecialchars($g['firma_img'])?>" target="_blank">
                                    <img src="../uploads/firmas/<?=htmlspecialchars($g['firma_img'])?>" alt="Firma" class="thumb-firma">
                                </a>
                            <?php else: ?>
                                <span class="small" style="color:#aaa;">Sin firma</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($g['comprobante_img'])): ?>
                                <a href="../uploads/gastos/<?=htmlspecialchars($g['comprobante_img'])?>" target="_blank" class="img-link">
                                    📎 Ver Soporte
                                </a>
                            <?php else: ?>
                                <span class="small" style="color:#aaa;">Sin foto</span>
                            <?php endif; ?>
                        </td>
                        <td><?=htmlspecialchars($g['branch_name'] ?? 'Global / Oficina')?></td>
                        <td>
                            <a class="btn btn-primary" style="padding:4px 8px;font-size:11px;" href="invoice_print.php?gasto_id=<?=urlencode($g['id'])?>" target="_blank">Imprimir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="small">No se encontraron gastos administrativos en el periodo seleccionado.</p>
    <?php endif; ?>
</div>

<script>
/* ============================================================
   LÓGICA PAGO ÚNICO / MIXTO
============================================================ */
function togglePagoModo(modo) {
    var unico = document.getElementById('pago_unico_container');
    var mixto = document.getElementById('pago_mixto_container');
    var valUnicoInput = document.getElementById('valor_unico');

    if (modo === 'mixto') {
        unico.style.display = 'none';
        mixto.style.display = 'grid';
        if (valUnicoInput) valUnicoInput.required = false;
    } else {
        unico.style.display = 'grid';
        mixto.style.display = 'none';
        if (valUnicoInput) valUnicoInput.required = true;
    }
}

function calcularTotalMixto() {
    var efec = parseFloat(document.getElementById('val_efectivo').value || 0);
    var trans = parseFloat(document.getElementById('val_transferencia').value || 0);
    var otr = parseFloat(document.getElementById('val_otros').value || 0);

    var total = efec + trans + otr;
    document.getElementById('total_mixto_display').value = '$ ' + total.toLocaleString('es-CO', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

/* ============================================================
   LÓGICA FIRMA DIGITAL EN CANVAS (TÁCTIL / MÓVIL)
============================================================ */
var canvas = document.getElementById('signature-pad');
var ctx = canvas.getContext('2d');
var isDrawing = false;
var hasSignature = false;

function resizeCanvas() {
    var ratio = Math.max(window.devicePixelRatio || 1, 1);
    var displayWidth = canvas.offsetWidth;
    var displayHeight = canvas.offsetHeight;
    
    if (canvas.width !== displayWidth || canvas.height !== displayHeight) {
        canvas.width = displayWidth;
        canvas.height = displayHeight;
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = '#0f172a';
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
    }
}

function getPos(e) {
    var rect = canvas.getBoundingClientRect();
    var clientX = e.touches ? e.touches[0].clientX : e.clientX;
    var clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return {
        x: clientX - rect.left,
        y: clientY - rect.top
    };
}

function startDrawing(e) {
    e.preventDefault();
    isDrawing = true;
    hasSignature = true;
    var pos = getPos(e);
    ctx.beginPath();
    ctx.moveTo(pos.x, pos.y);
}

function draw(e) {
    if (!isDrawing) return;
    e.preventDefault();
    var pos = getPos(e);
    ctx.lineTo(pos.x, pos.y);
    ctx.stroke();
}

function stopDrawing(e) {
    if (isDrawing) {
        ctx.closePath();
        isDrawing = false;
    }
}

function clearSignature() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSignature = false;
    document.getElementById('firma_base64').value = '';
}

// Eventos de ratón
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseleave', stopDrawing);

// Eventos táctiles para celular / tablet
canvas.addEventListener('touchstart', startDrawing, {passive: false});
canvas.addEventListener('touchmove', draw, {passive: false});
canvas.addEventListener('touchend', stopDrawing);

// Guardar datos del canvas antes de enviar el formulario
document.getElementById('formGastoAdmin').addEventListener('submit', function(e) {
    if (hasSignature) {
        var dataURL = canvas.toDataURL('image/png');
        document.getElementById('firma_base64').value = dataURL;
    }
});

document.addEventListener('DOMContentLoaded', function(){
    togglePagoModo('unico');
    resizeCanvas();
});
</script>
</body>
</html>
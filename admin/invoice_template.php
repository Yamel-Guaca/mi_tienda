<?php
// admin/invoice_template.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_role([1]); // solo admin

$pdo = DB::getConnection();
$msg = "";

// Helpers
function get_setting(PDO $pdo, string $key, $default = '') {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? $v : $default;
}
function set_setting(PDO $pdo, string $key, string $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    return $stmt->execute([$key, $value]);
}
function render_preview(string $template, array $data) {
    if (trim($template) === '') {
        return "<div style='padding:10px;background:#fff6e6;border:1px solid #f0d9b5;'>No hay plantilla guardada.</div>";
    }
    $replacements = [
        '{{COMPANY_NAME}}' => htmlspecialchars($data['COMPANY_NAME']),
        '{{NIT}}' => htmlspecialchars($data['NIT']),
        '{{ADDRESS}}' => htmlspecialchars($data['ADDRESS']),
        '{{PHONE}}' => htmlspecialchars($data['PHONE']),
        '{{INVOICE_NUMBER}}' => htmlspecialchars($data['INVOICE_NUMBER']),
        '{{DATE}}' => htmlspecialchars($data['DATE']),
        '{{CASHIER}}' => htmlspecialchars($data['CASHIER']),
        '{{CUSTOMER_NAME}}' => htmlspecialchars($data['CUSTOMER_NAME']),
        '{{SUBTOTAL}}' => htmlspecialchars($data['SUBTOTAL']),
        '{{TAX}}' => htmlspecialchars($data['TAX']),
        '{{TOTAL}}' => htmlspecialchars($data['TOTAL']),
        '{{LEGAL_LEGEND}}' => htmlspecialchars($data['LEGAL_LEGEND']),
        '{{LOGO}}' => htmlspecialchars($data['LOGO']),
        '{{ITEMS}}' => '<table class="items tiny"><tr><td>1x</td><td>Producto ejemplo</td><td style="text-align:right;">$1.000</td></tr></table>',
        '{{QR}}' => '<img src="https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=PREVIEW&choe=UTF-8" style="width:70px;height:70px;">'
    ];
    return strtr($template, $replacements);
}

// Cargar lista de sucursales
$branches = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM branches ORDER BY id");
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $branches = [];
}

// Determinar sucursal seleccionada (GET o POST)
$selectedBranchId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedBranchId = isset($_POST['branch_id']) && intval($_POST['branch_id']) > 0 ? intval($_POST['branch_id']) : null;
} else {
    $selectedBranchId = isset($_GET['branch_id']) && intval($_GET['branch_id']) > 0 ? intval($_GET['branch_id']) : null;
}

// Cargar plantilla global
$current_global_template = get_setting($pdo, 'invoice_template_html', '');

// Cargar datos de la sucursal seleccionada (si hay)
$branchData = [
    'id' => '',
    'name' => '',
    'nit' => '',
    'address' => '',
    'phone' => '',
    'invoice_legend' => '',
    'company_logo' => ''
];
$branch_template_key = '';
$branch_template = '';

if ($selectedBranchId) {
    try {
        $stmtB = $pdo->prepare("SELECT id, name, nit, address, phone, invoice_legend, company_logo FROM branches WHERE id = ? LIMIT 1");
        $stmtB->execute([$selectedBranchId]);
        $b = $stmtB->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $branchData['id'] = $b['id'];
            $branchData['name'] = $b['name'];
            $branchData['nit'] = $b['nit'];
            $branchData['address'] = $b['address'];
            $branchData['phone'] = $b['phone'];
            $branchData['invoice_legend'] = $b['invoice_legend'];
            $branchData['company_logo'] = $b['company_logo'];
            $branch_template_key = "invoice_template_html_branch_" . $b['id'];
            $branch_template = get_setting($pdo, $branch_template_key, '');
        }
    } catch (Exception $e) {
        // ignore
    }
}

// Guardar cambios (global o por sucursal)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si se envió "save_global" guardamos plantilla global
    if (isset($_POST['save_global'])) {
        $tpl = $_POST['template_html_global'] ?? '';
        set_setting($pdo, 'invoice_template_html', $tpl);
        $current_global_template = $tpl;
        $msg = "Plantilla global guardada.";
    }

    // Si se envió "save_branch" guardamos datos de branch y plantilla por branch
    if (isset($_POST['save_branch'])) {
        $bid = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : 0;
        $bname = trim($_POST['branch_name'] ?? '');
        $bnit = trim($_POST['branch_nit'] ?? '');
        $baddr = trim($_POST['branch_address'] ?? '');
        $bphone = trim($_POST['branch_phone'] ?? '');
        $blegend = trim($_POST['branch_legend'] ?? '');
        $blogo = trim($_POST['branch_logo'] ?? '');
        $tpl_branch = $_POST['template_html_branch'] ?? '';

        if ($bid > 0) {
            // Actualizar tabla branches
            $stmtUp = $pdo->prepare("UPDATE branches SET name = ?, nit = ?, address = ?, phone = ?, invoice_legend = ?, company_logo = ? WHERE id = ?");
            $stmtUp->execute([$bname, $bnit, $baddr, $bphone, $blegend, $blogo, $bid]);

            // Guardar plantilla por sucursal en settings
            $key = "invoice_template_html_branch_" . $bid;
            set_setting($pdo, $key, $tpl_branch);

            // Recargar datos
            $selectedBranchId = $bid;
            $branchData['id'] = $bid;
            $branchData['name'] = $bname;
            $branchData['nit'] = $bnit;
            $branchData['address'] = $baddr;
            $branchData['phone'] = $bphone;
            $branchData['invoice_legend'] = $blegend;
            $branchData['company_logo'] = $blogo;
            $branch_template_key = $key;
            $branch_template = $tpl_branch;

            $msg = "Datos y plantilla de la sucursal guardados.";
        } else {
            $msg = "Selecciona una sucursal válida para guardar.";
        }
    }
}

// Si no hay plantilla por sucursal, usar la global como valor mostrado
$display_template = $branch_template !== '' ? $branch_template : $current_global_template;

// Preparar vista previa con datos (si no hay branch seleccionado, usar global values)
$previewData = [
    'COMPANY_NAME' => $branchData['name'] ?: get_setting($pdo, 'company_name', 'Mi Negocio S.A.'),
    'NIT' => $branchData['nit'] ?: get_setting($pdo, 'company_nit', ''),
    'ADDRESS' => $branchData['address'] ?: get_setting($pdo, 'company_address', ''),
    'PHONE' => $branchData['phone'] ?: get_setting($pdo, 'company_phone', ''),
    'INVOICE_NUMBER' => 'PREVIEW-0001',
    'DATE' => date('Y-m-d H:i:s'),
    'CASHIER' => $_SESSION['user']['name'] ?? 'Administrador',
    'CUSTOMER_NAME' => 'Cliente Ejemplo',
    'SUBTOTAL' => '10.000',
    'TAX' => '1.900',
    'TOTAL' => '11.900',
    'LEGAL_LEGEND' => $branchData['invoice_legend'] ?: get_setting($pdo, 'company_legend', ''),
    'LOGO' => $branchData['company_logo'] ?: get_setting($pdo, 'company_logo', '')
];

$previewHtml = render_preview($display_template, $previewData);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Plantilla de factura por sucursal</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;padding:18px;max-width:1100px;margin:auto}
  .row{display:flex;gap:18px}
  .col{flex:1}
  label{display:block;margin-top:8px;font-weight:600}
  input[type="text"], textarea, select{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}
  textarea{height:300px;font-family:monospace}
  .btn{padding:8px 12px;border-radius:6px;background:#2b7cff;color:#fff;border:none;cursor:pointer}
  .msg{background:#e6ffe6;padding:8px;border:1px solid #cfc;margin-bottom:10px}
  .preview{background:#fff;padding:10px;border:1px solid #ddd;border-radius:6px;margin-top:12px}
  .help{background:#f7f7f7;padding:10px;border-radius:6px;margin-bottom:10px}
</style>
</head>
<body>
<h2>Plantilla de factura y datos por sucursal</h2>

<div class="help">
  <strong>Instrucciones</strong>: selecciona la sucursal para editar sus datos y su plantilla de factura. Si no existe plantilla por sucursal, se usa la plantilla global. Guarda los cambios para que se apliquen al imprimir desde esa sucursal.
</div>

<?php if ($msg): ?><div class="msg"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<form method="get" style="margin-bottom:12px">
  <label>Seleccionar sucursal para editar</label>
  <select name="branch_id" onchange="this.form.submit()">
    <option value="">-- Plantilla global / sin sucursal --</option>
    <?php foreach ($branches as $b): ?>
      <option value="<?=htmlspecialchars($b['id'])?>" <?=($selectedBranchId == $b['id']) ? 'selected' : ''?>><?=htmlspecialchars($b['id'] . ' — ' . $b['name'])?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="row">
  <div class="col">
    <h3>Plantilla global</h3>
    <form method="post">
      <label>Plantilla global (se usa si no hay plantilla por sucursal)</label>
      <textarea name="template_html_global"><?=htmlspecialchars($current_global_template)?></textarea>
      <div style="margin-top:8px;">
        <button class="btn" name="save_global" type="submit">Guardar plantilla global</button>
      </div>
    </form>

    <hr style="margin:18px 0">

    <h3>Editar sucursal <?= $selectedBranchId ? 'ID ' . intval($selectedBranchId) : '' ?></h3>
    <form method="post">
      <input type="hidden" name="branch_id" value="<?=htmlspecialchars($selectedBranchId)?>">
      <label>Nombre</label>
      <input type="text" name="branch_name" value="<?=htmlspecialchars($branchData['name'])?>">
      <label>NIT</label>
      <input type="text" name="branch_nit" value="<?=htmlspecialchars($branchData['nit'])?>">
      <label>Dirección</label>
      <input type="text" name="branch_address" value="<?=htmlspecialchars($branchData['address'])?>">
      <label>Teléfono</label>
      <input type="text" name="branch_phone" value="<?=htmlspecialchars($branchData['phone'])?>">
      <label>Logo (URL o ruta)</label>
      <input type="text" name="branch_logo" value="<?=htmlspecialchars($branchData['company_logo'])?>">
      <label>Leyenda legal</label>
      <input type="text" name="branch_legend" value="<?=htmlspecialchars($branchData['invoice_legend'])?>">
      <label>Plantilla específica de la sucursal (si la dejas vacía se usará la global)</label>
      <textarea name="template_html_branch"><?=htmlspecialchars($branch_template)?></textarea>
      <div style="margin-top:8px;">
        <button class="btn" name="save_branch" type="submit">Guardar datos y plantilla de sucursal</button>
      </div>
    </form>
  </div>

  <div class="col">
    <h3>Vista previa rápida</h3>
    <p>La vista previa muestra la plantilla que se aplicará (plantilla por sucursal si existe, sino la global).</p>
    <div class="preview"><?= $previewHtml ?></div>
  </div>
</div>

</body>
</html>

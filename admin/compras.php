<?php
// admin/compras.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Incluir la conexión desde la carpeta includes usando ruta absoluta
require_once __DIR__ . '/../includes/db.php'; 

// Capturar ID de usuario admitiendo varios nombres de clave de sesión
$user_id =$_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0;

// Capturar el Rol (ya sea por nombre o por ID de la tabla de roles)
$user_role = $_SESSION['user_role'] ?? $_SESSION['rol'] ?? $_SESSION['role'] ?? $_SESSION['tipo'] ?? '';
$role_id   =$_SESSION['role_id'] ?? $_SESSION['id_rol'] ?? $_SESSION['rol_id'] ?? 0;

if (!$user_id) {
    header('Location: /mi_tienda/login.php');
    exit;
}

// Evaluación flexible de Administrador: por nombre ("admin", "administrador") o por ID de rol (1)
$is_admin = (
    strtolower((string)$user_role) === 'admin' || 
    strtolower((string)$user_role) === 'administrador' || 
    intval($role_id) === 1
);

// Comprobación de permiso si no es Administrador
if (!$is_admin) {
    $stmt =$pdo->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission_key = 'compras'");
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() == 0) {
        die("Acceso denegado. No tienes permiso para gestionar compras.");
    }
}

$message = '';$print_purchase_id = null;

// PROCESAR GUARDADO DE COMPRA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase'])) {
    $branch_id     = intval($_POST['branch_id'] ?? 0);
    $provider      = trim($_POST['provider_name'] ?? '');
    $invoice_no    = trim($_POST['invoice_number'] ?? '');
    $purchase_date =$_POST['purchase_date'] ?? date('Y-m-d');
    $items_json    =$_POST['items_json'] ?? '[]';
    $items         = json_decode($items_json, true);

    if ($branch_id > 0 && !empty($provider) && !empty($invoice_no) && is_array($items) && count($items) > 0) {
        try {
            $pdo->beginTransaction();

            $total_paid = 0;
            foreach ($items as$it) {
                $total_paid += floatval($it['line_total']);
            }

            // 1. Insertar Factura
            $stmt =$pdo->prepare("INSERT INTO purchases (branch_id, user_id, provider_name, invoice_number, purchase_date, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id,$user_id, $provider,$invoice_no, $purchase_date,$total_paid]);
            $purchase_id =$pdo->lastInsertId();

            // 2. Insertar ítems y actualizar stock en la tabla inventory (product_id, branch_id, quantity)
            $stmtItem =$pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty_purchased, packaging_qty, total_units, unit_cost, iva_percent, is_gift, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Consulta adaptada a tu tabla 'inventory' real
            $stmtStock =$pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

            foreach ($items as $it) {$p_id        = intval($it['product_id']);$qty         = max(1, intval($it['qty']));$pack_qty    = max(1, intval($it['packaging_qty']));$total_units = $qty * $pack_qty;
                $is_gift     = !empty($it['is_gift']) ? 1 : 0;
                $unit_cost   =$is_gift ? 0 : floatval($it['unit_cost']);$iva         = floatval($it['iva']);$line_total  = $is_gift ? 0 : floatval($it['line_total']);

                $stmtItem->execute([$purchase_id, $p_id,$qty, $pack_qty,$total_units, $unit_cost,$iva, $is_gift,$line_total]);
                
                // Actualiza o inserta las unidades en tu tabla 'inventory'
                $stmtStock->execute([$p_id, $branch_id,$total_units]);
            }

            $pdo->commit();$message = "Factura de compra registrada exitosamente.";
            $print_purchase_id =$purchase_id; // Disparar impresión
        } catch (Exception $e) {
            $pdo->rollBack();$message = "Error al guardar la compra: " . $e->getMessage();
        }
    } else {
        $message = "Por favor complete todos los campos obligatorios e ingrese al menos un producto.";
    }
}

// Cargar sucursales para el select
$branches =$pdo->query("SELECT id, name FROM branches WHERE active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ingreso de Compras / Facturas</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 20px; background: #f4f6f8; color: #333; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h2 { margin-top: 0; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        .form-row { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 5px; font-size: 14px; }
        input, select, button { padding: 9px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; }
        .btn { cursor: pointer; background: #2563eb; color: #fff; border: none; font-weight: 600; transition: background 0.2s; }
        .btn:hover { background: #1d4ed8; }
        .btn-success { background: #16a34a; font-size: 16px; padding: 12px; }
        .btn-success:hover { background: #15803d; }
        .search-box { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; max-height: 200px; overflow-y: auto; z-index: 100; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .search-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .search-item:hover { background: #eff6ff; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        th { background: #f8fafc; font-weight: 600; }
        .total-box { margin-top: 20px; text-align: right; font-size: 20px; font-weight: bold; color: #0f172a; }
        .alert { padding: 12px; background: #dcfce7; color: #166534; border-radius: 6px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
  
  <a href="pos.php" style="text-decoration: none;">
    <div style="display: inline-flex; align-items: center; gap: 8px; background-color: #2563eb; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-weight: 600; font-family: system-ui, sans-serif; cursor: pointer; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
        🛒 Ir al POS / Ventas
    </div>
</a>

    <h2>Ingreso de Factura de Compra</h2>

    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form id="purchaseForm" method="POST">
        <input type="hidden" name="save_purchase" value="1">
        <input type="hidden" id="items_json" name="items_json" value="[]">

        <div class="form-row">
            <div class="form-group">
                <label>Sucursal de Destino *</label>
                <select name="branch_id" required>
                    <option value="">Seleccione Sucursal</option>
                    <?php foreach ($branches as$b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Proveedor *</label>
                <input type="text" name="provider_name" required placeholder="Nombre del proveedor">
            </div>
            <div class="form-group">
                <label>N° Factura *</label>
                <input type="text" name="invoice_number" required placeholder="Ej: FCT-00192">
            </div>
            <div class="form-group">
                <label>Fecha Factura *</label>
                <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid #e2e8f0; margin: 20px 0;">

        <h3>Añadir Productos a la Compra</h3>
        <div class="form-row search-box">
            <div class="form-group" style="flex:2;">
                <label>Buscar Producto (Nombre o SKU)</label>
                <input type="text" id="product_search_input" placeholder="Escriba para buscar..." autocomplete="off">
                <div id="search_results" class="search-results" style="display:none;"></div>
            </div>
        </div>

        <!-- Campos dinámicos del ítem seleccionado -->
        <div class="form-row" id="item_editor" style="background: #f8fafc; padding: 15px; border-radius: 6px; align-items: flex-end;">
            <input type="hidden" id="sel_product_id">
            <div class="form-group" style="flex:2;">
                <label>Producto Seleccionado</label>
                <input type="text" id="sel_product_name" readonly placeholder="Ninguno" style="background:#e2e8f0;">
            </div>
            <div class="form-group">
                <label>Cantidad</label>
                <input type="number" id="sel_qty" min="1" value="1">
            </div>
            <div class="form-group">
                <label>Embalaje (Unid. x empaque)</label>
                <input type="number" id="sel_pack_qty" min="1" value="1">
            </div>
            <div class="form-group">
                <label>Valor Unitario</label>
                <input type="number" step="0.01" id="sel_unit_cost" placeholder="0.00">
            </div>
            <div class="form-group">
                <label>IVA %</label>
                <input type="number" step="0.01" id="sel_iva" value="0">
            </div>
            <div class="form-group" style="flex: 0 0 auto;">
                <label><input type="checkbox" id="sel_is_gift"> ¿Es Obsequio?</label>
            </div>
            <div class="form-group" style="flex: 0 0 auto;">
                <button type="button" class="btn" onclick="addItemToTable()">Agregar Ítem</button>
            </div>
        </div>

        <!-- Tabla de ítems agregados -->
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Cant. Empaques</th>
                    <th>Embalaje</th>
                    <th>Total Unidades Stock</th>
                    <th>Valor Unitario</th>
                    <th>IVA %</th>
                    <th>Obsequio</th>
                    <th>Subtotal</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="purchase_items_tbody">
                <tr><td colspan="9" style="text-align:center; opacity:0.7;">No hay productos agregados a la factura.</td></tr>
            </tbody>
        </table>

        <div class="total-box">
            Total Pagado: $<span id="grand_total">0.00</span>
        </div>

        <div style="margin-top: 25px; text-align: right;">
            <button type="submit" class="btn btn-success" style="width: 100%; max-width: 300px;">Guardar e Imprimir Soporte</button>
        </div>
    </form>
</div>

<script>
let addedItems = [];

// Búsqueda en vivo de productos por AJAX
const searchInput = document.getElementById('product_search_input');
const searchResults = document.getElementById('search_results');

searchInput.addEventListener('input', function() {
    const q = this.value.trim();
    if (q.length < 2) {
        searchResults.style.display = 'none';
        return;
    }
    fetch('/mi_tienda/admin/ajax/search_products.php?q=' + encodeURIComponent(q))
        .then(res => res.json())
        .then(data => {
            searchResults.innerHTML = '';
            if (data.length === 0) {
                searchResults.innerHTML = '<div class="search-item">No se encontraron resultados</div>';
            } else {
                data.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'search-item';
                    div.textContent = `${p.name} (SKU: ${p.sku}) - Costo ref: $${p.cost_initial || 0}`;
                    div.onclick = () => selectProduct(p);
                    searchResults.appendChild(div);
                });
            }
            searchResults.style.display = 'block';
        });
});

function selectProduct(p) {
    document.getElementById('sel_product_id').value = p.id;
    document.getElementById('sel_product_name').value = p.name;
    document.getElementById('sel_pack_qty').value = p.packaging_qty || 1;
    document.getElementById('sel_unit_cost').value = p.cost_initial || 0;
    document.getElementById('sel_iva').value = p.iva_percent || 0;
    document.getElementById('sel_is_gift').checked = false;
    toggleGiftState();
    searchResults.style.display = 'none';
    searchInput.value = '';
}

// Checkbox de obsequio desactiva campos de costo
document.getElementById('sel_is_gift').addEventListener('change', toggleGiftState);
function toggleGiftState() {
    const isGift = document.getElementById('sel_is_gift').checked;
    const costInput = document.getElementById('sel_unit_cost');
    const ivaInput = document.getElementById('sel_iva');
    if (isGift) {
        costInput.value = '0';
        ivaInput.value = '0';
        costInput.disabled = true;
        ivaInput.disabled = true;
    } else {
        costInput.disabled = false;
        ivaInput.disabled = false;
    }
}

function addItemToTable() {
    const pId = document.getElementById('sel_product_id').value;
    const pName = document.getElementById('sel_product_name').value;
    const qty = parseInt(document.getElementById('sel_qty').value) || 1;
    const packQty = parseInt(document.getElementById('sel_pack_qty').value) || 1;
    const isGift = document.getElementById('sel_is_gift').checked;
    const unitCost = isGift ? 0 : (parseFloat(document.getElementById('sel_unit_cost').value) || 0);
    const iva = isGift ? 0 : (parseFloat(document.getElementById('sel_iva').value) || 0);

    if (!pId) {
        alert('Por favor busque y seleccione un producto.');
        return;
    }

    const totalUnits = qty * packQty;
    const subtotal = qty * unitCost;
    const lineTotal = subtotal + (subtotal * (iva / 100));

    addedItems.push({
        product_id: pId,
        product_name: pName,
        qty: qty,
        packaging_qty: packQty,
        total_units: totalUnits,
        unit_cost: unitCost,
        iva: iva,
        is_gift: isGift,
        line_total: lineTotal
    });

    renderTable();
    clearEditor();
}

function clearEditor() {
    document.getElementById('sel_product_id').value = '';
    document.getElementById('sel_product_name').value = '';
    document.getElementById('sel_qty').value = 1;
    document.getElementById('sel_pack_qty').value = 1;
    document.getElementById('sel_unit_cost').value = '';
    document.getElementById('sel_iva').value = 0;
    document.getElementById('sel_is_gift').checked = false;
    toggleGiftState();
}

function renderTable() {
    const tbody = document.getElementById('purchase_items_tbody');
    tbody.innerHTML = '';
    let grandTotal = 0;

    if (addedItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; opacity:0.7;">No hay productos agregados a la factura.</td></tr>';
    } else {
        addedItems.forEach((item, index) => {
            grandTotal += item.line_total;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(item.product_name)}</td>
                <td>${item.qty}</td>
                <td>${item.packaging_qty}</td>
                <td><strong>${item.total_units}</strong></td>
                <td>$${item.unit_cost.toFixed(2)}</td>
                <td>${item.iva}%</td>
                <td>${item.is_gift ? '🎁 Obsequio' : 'No'}</td>
                <td>$${item.line_total.toFixed(2)}</td>
                <td><button type="button" style="background:#ef4444; color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer;" onclick="removeItem(${index})">Eliminar</button></td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('grand_total').textContent = grandTotal.toFixed(2);
    document.getElementById('items_json').value = JSON.stringify(addedItems);
}

function removeItem(index) {
    addedItems.splice(index, 1);
    renderTable();
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

// Impresión obligatoria automática tras guardar enviando a invoice_print.php
<?php if ($print_purchase_id): ?>
window.open('/mi_tienda/admin/invoice_print.php?purchase_id=<?= $print_purchase_id ?>', '_blank', 'width=400,height=600');
<?php endif; ?>
</script>

</body>
</html>
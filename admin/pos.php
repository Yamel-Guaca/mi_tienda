<?php
// admin/pos.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__.'/../includes/auth_functions.php';
require_once __DIR__.'/../includes/db.php';

require_role([1,3]);
$pdo=DB::getConnection();

// --- ENDPOINTS AJAX: BÚSQUEDA Y CREACIÓN RÁPIDA DE CLIENTES ---
if (isset($_GET['action'])) {
    if (ob_get_length()) ob_clean(); // Limpia espacios o avisos PHP previos para entregar JSON puro

    if ($_GET['action'] === 'search_customer') {
        header('Content-Type: application/json');
        try {
            $q = trim($_GET['q'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE name LIKE ? OR doc_num LIKE ? OR phone LIKE ? ORDER BY name ASC LIMIT 10");
            $stmt->execute(["%$q%", "%$q%", "%$q%"]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            echo json_encode([]);
        }
        exit;
    }

    if ($_GET['action'] === 'create_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');

            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'El nombre o razón social es obligatorio']);
                exit;
            }

            $docType = !empty($input['doc_type']) ? trim($input['doc_type']) : 'CC';
            $docNum  = !empty($input['doc_num']) ? trim($input['doc_num']) : null;
            $phone   = !empty($input['phone']) ? trim($input['phone']) : null;
            $email   = !empty($input['email']) ? trim($input['email']) : null;
            $address = !empty($input['address']) ? trim($input['address']) : null;

            $stmt = $pdo->prepare("INSERT INTO customers (name, doc_type, doc_num, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $docType, $docNum, $phone, $email, $address]);

            $newId = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'customer' => [
                    'id'       => $newId,
                    'name'     => $name,
                    'doc_type' => $docType,
                    'doc_num'  => $docNum ?? '',
                    'phone'    => $phone ?? '',
                    'email'    => $email ?? '',
                    'address'  => $address ?? ''
                ]
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Error en Base de Datos: ' . $e->getMessage()]);
        }
        exit;
    }
}

$currentBranchId=(int)($_SESSION['branch_id']??0);
$currentBranchName=$_SESSION['branch_name']??'No seleccionada';
$userId=(int)($_SESSION['user']['id']??$_SESSION['user_id']??0);

if(!$currentBranchId)die('No hay sucursal seleccionada. Vuelva al dashboard.');
if(!$userId)die('No se pudo identificar el usuario actual.');

/* ============================================================
   FUNCIONES DE INVENTARIO / PRESENTACIONES
============================================================ */
function posProductFactors(array $p):array{
    $packs=max(1,(int)($p['packaging_qty']??1));
    $hasInner=!empty($p['has_inner_units']);
    $inner=$hasInner?max(1,(int)($p['inner_unit_qty']??1)):1;
    return ['package'=>$packs*$inner,'inner'=>$inner,'individual'=>1];
}

function posPresentationFactor(array $p,string $level,int $tierQuantity=1):int{
    $tierQuantity=max(1,$tierQuantity);
    $f=posProductFactors($p);

    if($level==='package')return $tierQuantity*$f['package'];
    if($level==='inner'&&!empty($p['has_inner_units']))return $tierQuantity*$f['inner'];

    return $tierQuantity;
}

function posPresentationName(string $level,string $fallback='unidad'):string{
    if($level==='package')return $fallback?:'embalaje';
    if($level==='inner')return $fallback?:'paquete';
    return $fallback?:'unidad';
}

/* Detectar compatibilidad con product_prices.unit_level */
$hasPriceUnitLevel=false;
try{
    $columnCheck=$pdo->query("SHOW COLUMNS FROM product_prices LIKE 'unit_level'");
    $hasPriceUnitLevel=(bool)$columnCheck->fetch(PDO::FETCH_ASSOC);
}catch(Throwable $e){
    $hasPriceUnitLevel=false;
}

/* ============================================================
   OBTENER PRESENTACIÓN AUTORITATIVA DESDE MYSQL
============================================================ */
function getSalePresentation(PDO $pdo, int $productId, ?int $tierId, bool $hasPriceUnitLevel): array {
    $stmt = $pdo->prepare("
        SELECT id, name, sku, price_unit, packaging_type, packaging_qty,
               has_inner_units, inner_unit_qty, active
        FROM products
        WHERE id = ? AND active = 1
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException("Producto ID $productId no existe o está inactivo.");
    }

    if ($tierId) {
        $selectLevel = $hasPriceUnitLevel ? ", pp.unit_level" : "";
        $sql = "
            SELECT pp.id, pp.product_id, pp.price, pp.quantity, pp.unit_label
            " . ($hasPriceUnitLevel ? ", pp.unit_level" : "") . "
            FROM product_prices pp
            WHERE pp.id = ? AND pp.product_id = ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tierId, $productId]);
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tier) {
            throw new RuntimeException("La presentación seleccionada para {$product['name']} ya no existe.");
        }

        $tierQty = max(1, (int)($tier['quantity'] ?? 1));
        $label = trim((string)($tier['unit_label'] ?? 'unidad'));

        if ($hasPriceUnitLevel) {
            $level = (string)($tier['unit_level'] ?? 'individual');
            if (!in_array($level, ['individual', 'inner', 'package'], true)) {
                $level = 'individual';
            }
            $factor = posPresentationFactor($product, $level, $tierQty);
        } else {
            $level = 'legacy';
            $factor = $tierQty;
        }

        return [
            'product'      => $product,
            'tier_id'      => (int)$tier['id'],
            'price'        => (float)$tier['price'],
            'unit_label'   => $label ?: 'unidad',
            'unit_level'   => $level,
            'unit_qty'     => $tierQty,
            'factor'       => max(1, $factor),
            'display_name' => $product['name'] . ' - ' . ($label ?: 'unidad')
        ];
    }

    return [
        'product'      => $product,
        'tier_id'      => null,
        'price'        => (float)$product['price_unit'],
        'unit_label'   => 'unidad',
        'unit_level'   => 'individual',
        'unit_qty'     => 1,
        'factor'       => 1,
        'display_name' => $product['name']
    ];
}

/* ============================================================
   PROCESAR VENTA POST (CON CLIENTE Y MULTI-PAGO)
============================================================ */
$action=$_GET['action']??null;
$saleError='';

if($action==='sell'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $items=json_decode($_POST['items']??'[]',true);
    if(!is_array($items))$items=[];

    $paymentsDataJson = $_POST['payments_json'] ?? '[]';
    $paymentsList = json_decode($paymentsDataJson, true);
    if (!is_array($paymentsList)) $paymentsList = [];

    // Fallback si viene del formulario antiguo
    if (empty($paymentsList)) {
        $cashRec = max(0, (float)($_POST['cash_received'] ?? 0));
        $virtRec = max(0, (float)($_POST['virtual_received'] ?? 0));
        if ($cashRec > 0) $paymentsList[] = ['method' => 'efectivo', 'amount' => $cashRec, 'cash_received' => $cashRec];
        if ($virtRec > 0) $paymentsList[] = ['method' => 'virtual', 'amount' => $virtRec];
    }

    // Datos del Cliente
    $customerId = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $custName = trim($_POST['customer_name'] ?? 'Consumidor Final');
    $custDocType = trim($_POST['customer_doc_type'] ?? 'CC');
    $custDocNum = trim($_POST['customer_doc_num'] ?? '222222222222');
    $custPhone = trim($_POST['customer_phone'] ?? '');
    $custEmail = trim($_POST['customer_email'] ?? '');
    $custAddress = trim($_POST['customer_address'] ?? '');

    if(!$items){
        $saleError='El carrito está vacío.';
    }else{
        try{
            $pdo->beginTransaction();

            $stmt=$pdo->prepare("
                INSERT INTO orders(user_id, branch_id, customer_id, customer_name, customer_doc_type, customer_doc_num, customer_phone, customer_email, customer_address, total, status)
                VALUES(?,?,?,?,?,?,?,?,?,0,'completado')
            ");
            $stmt->execute([$userId, $currentBranchId, $customerId, $custName, $custDocType, $custDocNum, $custPhone, $custEmail, $custAddress]);
            $orderId=(int)$pdo->lastInsertId();

            $total=0;
            $validItems=0;

            foreach($items as $item){
                $pid=(int)($item['product_id']??0);
                $qty=(int)($item['quantity']??0);
                $tierId=!empty($item['tier_id'])?(int)$item['tier_id']:null;

                if($pid<=0||$qty<=0)continue;

                $sale=getSalePresentation($pdo,$pid,$tierId,$hasPriceUnitLevel);

                $product=$sale['product'];
                $price=(float)$sale['price'];
                $factor=max(1,(int)$sale['factor']);
                $stockUnitsRequired=$qty*$factor;

                if($stockUnitsRequired<=0){
                    throw new RuntimeException('Cantidad de inventario inválida para '.$product['name'].'.');
                }

                $stmt=$pdo->prepare("
                    SELECT quantity
                    FROM inventory
                    WHERE product_id=? AND branch_id=?
                    FOR UPDATE
                ");
                $stmt->execute([$pid,$currentBranchId]);
                $stock=$stmt->fetchColumn();
                $available=$stock===false?0:(int)$stock;

                if($available<$stockUnitsRequired){
                    $availablePresentations=intdiv(max(0,$available),$factor);
                    throw new RuntimeException(
                        'Stock insuficiente para '.$sale['display_name'].
                        '. Disponible: '.$availablePresentations.' '.$sale['unit_label'].
                        ' ('.$available.' unidades mínimas). Necesario: '.$stockUnitsRequired.' unidades mínimas.'
                    );
                }

                $subtotal=$price*$qty;
                $total+=$subtotal;

                $stmt=$pdo->prepare("
                    INSERT INTO order_items
                    (order_id,product_id,product_name,tier_id,unit_label,quantity,price,subtotal)
                    VALUES(?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $orderId,$pid,$sale['display_name'],$sale['tier_id'],
                    $sale['unit_label'],$qty,$price,$subtotal
                ]);

                $stmt=$pdo->prepare("
                    UPDATE inventory
                    SET quantity=quantity-?
                    WHERE product_id=? AND branch_id=?
                ");
                $stmt->execute([$stockUnitsRequired,$pid,$currentBranchId]);

                if($stmt->rowCount()!==1){
                    throw new RuntimeException('No se pudo actualizar el inventario de '.$product['name'].'.');
                }

                $note='Venta POS: '.$qty.' '.$sale['unit_label'].' = '.$stockUnitsRequired.' unidad(es) mínima(s).';

                $stmt=$pdo->prepare("
                    INSERT INTO inventory_movements
                    (product_id,branch_id,user_id,type,quantity,note)
                    VALUES(?,?,?,'salida',?,?)
                ");
                $stmt->execute([$pid,$currentBranchId,$userId,$stockUnitsRequired,$note]);

                $validItems++;
            }

            if($validItems===0)throw new RuntimeException('No existen productos válidos en el carrito.');
            if($total<=0)throw new RuntimeException('El total de la venta no es válido.');

            // VALIDACIÓN Y REGISTRO DE PAGOS DIVERSOS
            $totalCovered = 0;
            foreach ($paymentsList as $p) {
                $method = strtolower(trim($p['method'] ?? 'efectivo'));
                $amt = max(0, (float)($p['amount'] ?? 0));
                $cRec = max(0, (float)($p['cash_received'] ?? $amt));
                $cChg = max(0, (float)($p['change_given'] ?? 0));

                if ($amt <= 0 && $cRec <= 0) continue;

                $totalCovered += $amt;

                if ($method === 'efectivo') {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments
                        (order_id,method,amount,cash_received,change_given,status,user_id,created_at)
                        VALUES(?,'efectivo',?,?,?,'completado',?,NOW())
                    ");
                    $stmt->execute([$orderId, $amt, $cRec, $cChg, $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments
                        (order_id,method,amount,status,user_id,created_at)
                        VALUES(?,?,?,'completado',?,NOW())
                    ");
                    $stmt->execute([$orderId, $method, $amt, $userId]);
                }
            }

            if ($totalCovered + 0.01 < $total) {
                throw new RuntimeException('Pago insuficiente. Total: $'.number_format($total,2,',','.').' / Pagado: $'.number_format($totalCovered,2,',','.'));
            }

            $stmt=$pdo->prepare("UPDATE orders SET total=? WHERE id=?");
            $stmt->execute([$total,$orderId]);

            $pdo->commit();

            // REDIRECCIÓN Y LIMPIEZA DEL TICKET COMPLETADO
            echo '<script>
            try{
                let t = JSON.parse(localStorage.getItem("pos_tickets") || "null");
                let idx = parseInt(localStorage.getItem("pos_active_ticket") || "0", 10);
                if(Array.isArray(t) && t.length > 0){
                    if(t.length > 1){
                        t.splice(idx, 1);
                        if(idx >= t.length) idx = t.length - 1;
                    } else {
                        t[0].items = [];
                        t[0].name = "Ticket 1";
                        t[0].customer = null;
                        idx = 0;
                    }
                    localStorage.setItem("pos_tickets", JSON.stringify(t));
                    localStorage.setItem("pos_active_ticket", String(idx));
                }
                localStorage.removeItem("pos_cart");
            }catch(e){}
            window.open("/mi_tienda/admin/invoice_print.php?order_id='.$orderId.'","_blank");
            window.location.href="pos.php?mode=tactil&cleancart=1";
            </script>';
            exit;

        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $saleError=$e->getMessage();
        }
    }
}

/* ============================================================
   CATEGORÍAS / SUBCATEGORÍAS CON FILTRO DE VISIBILIDAD DE SUCURSAL
============================================================ */
$currentCategoryId=(int)($_GET['cat']??0);
$currentSubcategoryId=(int)($_GET['sub']??0);

// Cargar únicamente categorías visibles para esta sucursal
$stmt=$pdo->prepare("
    SELECT c.id, c.name, c.icon
    FROM categories c
    LEFT JOIN branch_category_visibility v
      ON v.category_id=c.id AND v.branch_id=?
    WHERE COALESCE(v.visible,1)=1
    ORDER BY c.name
");
$stmt->execute([$currentBranchId]);
$categories=$stmt->fetchAll(PDO::FETCH_ASSOC);

// Validar y cargar subcategorías visibles
$subcategories=[];
if($currentCategoryId>0){
    $stmt=$pdo->prepare("
        SELECT s.id, s.name, s.icon
        FROM subcategories s
        LEFT JOIN branch_subcategory_visibility v
          ON v.subcategory_id=s.id AND v.branch_id=?
        WHERE s.category_id=? AND COALESCE(v.visible,1)=1
        ORDER BY s.name
    ");
    $stmt->execute([$currentBranchId, $currentCategoryId]);
    $subcategories=$stmt->fetchAll(PDO::FETCH_ASSOC);
}

$currentCategoryName='';
$currentSubcategoryName='';

if($currentCategoryId>0){
    $stmt=$pdo->prepare("
        SELECT c.name 
        FROM categories c
        LEFT JOIN branch_category_visibility v ON v.category_id=c.id AND v.branch_id=?
        WHERE c.id=? AND COALESCE(v.visible,1)=1
    ");
    $stmt->execute([$currentBranchId, $currentCategoryId]);
    $currentCategoryName=(string)($stmt->fetchColumn()?:'');
    if(!$currentCategoryName) {
        $currentCategoryId=0;
        $currentSubcategoryId=0;
    }
}

if($currentSubcategoryId>0){
    $stmt=$pdo->prepare("
        SELECT s.name 
        FROM subcategories s
        LEFT JOIN branch_subcategory_visibility v ON v.subcategory_id=s.id AND v.branch_id=?
        WHERE s.id=? AND COALESCE(v.visible,1)=1
    ");
    $stmt->execute([$currentBranchId, $currentSubcategoryId]);
    $currentSubcategoryName=(string)($stmt->fetchColumn()?:'');
    if(!$currentSubcategoryName) {
        $currentSubcategoryId=0;
    }
}

/* ============================================================
   CONSULTAR PRODUCTOS Y PRESENTACIONES CON VISIBILIDAD POR SUCURSAL
============================================================ */

// 1. PRODUCTOS BASE
$whereBase = ["p.active=1", "COALESCE(cv.visible,1)=1", "COALESCE(sv.visible,1)=1"];
$paramsBase = [$currentBranchId, $currentBranchId, $currentBranchId];

if ($currentCategoryId > 0) {
    $whereBase[] = "p.category_id = ?";
    $paramsBase[] = $currentCategoryId;
}
if ($currentSubcategoryId > 0) {
    $whereBase[] = "p.subcategory_id = ?";
    $paramsBase[] = $currentSubcategoryId;
}

$whereBaseSql = implode(" AND ", $whereBase);

$stmt=$pdo->prepare("
    SELECT p.id AS product_id, NULL AS tier_id, p.name AS display_name, p.sku,
           p.price_unit AS price,
           COALESCE(
               (SELECT filename FROM product_images WHERE product_id=p.id AND is_main=1 LIMIT 1),
               (SELECT filename FROM product_images WHERE product_id=p.id LIMIT 1)
           ) AS image,
           COALESCE(i.quantity,0) AS stock_units,
           'unidad' AS unit_label, 1 AS unit_qty,
           'individual' AS unit_level, 1 AS conversion_factor,
           FLOOR(COALESCE(i.quantity,0)) AS stock_available,
           p.packaging_qty, p.has_inner_units, p.inner_unit_qty,
           p.category_id, p.subcategory_id
    FROM products p
    LEFT JOIN inventory i ON i.product_id=p.id AND i.branch_id=?
    LEFT JOIN branch_category_visibility cv ON cv.category_id=p.category_id AND cv.branch_id=?
    LEFT JOIN branch_subcategory_visibility sv ON sv.subcategory_id=p.subcategory_id AND sv.branch_id=?
    WHERE {$whereBaseSql}
    ORDER BY p.name
");
$stmt->execute($paramsBase);
$productsBase=$stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($productsBase as &$p){
    $p['stock_available']=(int)$p['stock_units'];
    $p['conversion_factor']=1;
}
unset($p);

// 2. PRODUCTOS POR PRESENTACIÓN (TIERS)
$whereTiers = ["p.active=1", "COALESCE(cv.visible,1)=1", "COALESCE(sv.visible,1)=1"];
$paramsTiers = [$currentBranchId, $currentBranchId, $currentBranchId];

if ($currentCategoryId > 0) {
    $whereTiers[] = "COALESCE(pp.category_id, p.category_id) = ?";
    $paramsTiers[] = $currentCategoryId;
}
if ($currentSubcategoryId > 0) {
    $whereTiers[] = "COALESCE(pp.subcategory_id, p.subcategory_id) = ?";
    $paramsTiers[] = $currentSubcategoryId;
}

$whereTiersSql = implode(" AND ", $whereTiers);

$stmt=$pdo->prepare("
    SELECT p.id AS product_id, pp.id AS tier_id,
           CONCAT(p.name,' - ',pp.unit_label) AS display_name,
           CONCAT(p.sku,'-',pp.unit_label) AS sku,
           pp.price,
           COALESCE(
               (SELECT filename FROM product_images WHERE id = pp.image_id LIMIT 1),
               (SELECT filename FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1),
               (SELECT filename FROM product_images WHERE product_id = p.id LIMIT 1)
           ) AS image,
           COALESCE(i.quantity,0) AS stock_units,
           pp.unit_label, pp.quantity AS unit_qty
           ".($hasPriceUnitLevel?", pp.unit_level":"").",
           p.packaging_qty, p.has_inner_units, p.inner_unit_qty,
           COALESCE(pp.category_id, p.category_id) AS category_id,
           COALESCE(pp.subcategory_id, p.subcategory_id) AS subcategory_id
    FROM product_prices pp
    INNER JOIN products p ON p.id=pp.product_id
    LEFT JOIN inventory i ON i.product_id=p.id AND i.branch_id=?
    LEFT JOIN branch_category_visibility cv ON cv.category_id=COALESCE(pp.category_id, p.category_id) AND cv.branch_id=?
    LEFT JOIN branch_subcategory_visibility sv ON sv.subcategory_id=COALESCE(pp.subcategory_id, p.subcategory_id) AND sv.branch_id=?
    WHERE {$whereTiersSql}
    ORDER BY p.name
");
$stmt->execute($paramsTiers);
$productsTiers=$stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($productsTiers as &$p){
    $tierQty=max(1,(int)($p['unit_qty']??1));

    if($hasPriceUnitLevel){
        $level=$p['unit_level']??'individual';
        if(!in_array($level,['individual','inner','package'],true))$level='individual';
        $factor=posPresentationFactor($p,$level,$tierQty);
    }else{
        $level='legacy';
        $factor=$tierQty;
    }

    $p['unit_level']=$level;
    $p['conversion_factor']=max(1,$factor);
    $p['stock_available']=intdiv(max(0,(int)$p['stock_units']),$p['conversion_factor']);
}
unset($p);

$products=array_merge($productsBase,$productsTiers);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Punto de Venta</title>
<link rel="stylesheet" href="../public/css/pos.css">
<link rel="stylesheet" href="../public/css/theme_dark.css">
<style>
.pos-search{display:inline-flex;align-items:center;gap:6px}.pos-search input#global-search{width:260px;max-width:50vw;min-width:180px;padding:9px 12px;font-size:15px;border-radius:8px;border:2px solid #0078d4;background:#fff;outline:none}.pos-search button{background:transparent;border:0;font-size:16px;padding:6px;cursor:pointer;color:#666}.pos-search input:focus{border-color:#005a9e;box-shadow:0 0 0 3px rgba(0,120,212,.2)}.sr-only{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
header{display:flex;justify-content:space-between;align-items:center;background:#2c3e50;padding:10px 20px;color:#fff;font-family:Arial,sans-serif}header h2{margin:0;font-size:20px}.header-left>div{margin-top:5px;display:flex;flex-wrap:wrap;gap:12px;font-size:14px}.header-left a{color:#ecf0f1;text-decoration:none;padding:6px 10px;border-radius:4px}.header-left a:hover{background:#1abc9c;color:#fff}.header-right{display:flex;gap:10px}.btn{padding:9px 13px;border-radius:5px;border:0;cursor:pointer}.header-right .btn{background:#3498db;color:#fff;text-decoration:none}.header-right .btn-danger,.btn-danger{background:#e74c3c;color:#fff}.btn-compras{display:inline-block;padding:8px 16px!important;background:#0a6!important;color:#fff!important;text-decoration:none;border-radius:4px;font-weight:bold}
.sale-error{background:#ffe7e7;border:1px solid #e79999;color:#9f1717;padding:12px;border-radius:7px;margin:10px 0;font-weight:bold}.stock-equivalence{font-size:11px;color:#777;margin-top:3px}.cart-equivalence{font-size:11px;color:#777;display:block}

/* BARRA DE TICKETS / CUENTAS EN ESPERA */
.cart-header-wrapper{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.cart-header-wrapper h3{margin:0}
.ticket-rename-btn{font-size:12px;color:#0078d4;cursor:pointer;font-weight:bold;background:rgba(0,120,212,0.12);padding:3px 8px;border-radius:4px;user-select:none;transition:all .15s ease}
.ticket-rename-btn:hover{background:rgba(0,120,212,0.22)}
.tickets-bar{display:flex;gap:6px;align-items:center;overflow-x:auto;padding:4px 2px 8px 2px;margin-bottom:8px;scrollbar-width:thin}
.ticket-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#e2e8f0;color:#334155;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:1px solid #cbd5e1;user-select:none;transition:all .15s ease}
.ticket-tab.active{background:#0078d4;color:#ffffff;border-color:#005a9e;box-shadow:0 2px 5px rgba(0,120,212,0.3)}
.ticket-tab .badge-count{background:#cbd5e1;color:#1e293b;padding:1px 5px;border-radius:10px;font-size:11px;font-weight:700}
.ticket-tab.active .badge-count{background:#ffffff;color:#0078d4}
.ticket-tab .close-tab{font-weight:bold;margin-left:2px;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;line-height:1;color:#64748b}
.ticket-tab.active .close-tab{color:#ffffff}
.ticket-tab .close-tab:hover{background:rgba(0,0,0,0.15);color:#e11d48}
.btn-add-ticket{background:#107c41;color:#ffffff;border:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
.btn-add-ticket:hover{background:#0b5a2f}

/* CLIENTE EN CARRITO */
.customer-box{background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.customer-info{font-size:13px;color:#1e293b;line-height:1.3}
.customer-info strong{display:block;color:#0f172a;font-size:14px}
.customer-actions{display:flex;gap:4px}
.btn-cust{padding:5px 8px;font-size:12px;border-radius:4px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-weight:600;color:#0078d4}
.btn-cust:hover{background:#e2e8f0}

/* PANEL DE COBRO MULTI-PAGO */
.cash-panel{position:fixed;right:20px;bottom:20px;width:420px;max-width:92vw;max-height:90vh;overflow-y:auto;background:#fff;border:1px solid #ccc;box-shadow:0 8px 25px rgba(0,0,0,.2);border-radius:10px;z-index:1000;font-family:Arial,sans-serif}
.cash-panel-inner{padding:16px}
.cash-panel h2{margin:0 0 10px;font-size:18px;color:#1e293b}
.summary-box{background:#f8fafc;border:1px solid #e2e8f0;padding:10px 12px;border-radius:8px;margin-bottom:12px}
.summary-box .row{display:flex;justify-content:space-between;align-items:center;margin:4px 0;font-size:14px}
.summary-box .row.total-row{font-size:18px;font-weight:bold;color:#0f172a;border-bottom:1px solid #cbd5e1;padding-bottom:4px;margin-bottom:6px}
.summary-box .row.pending-row{color:#d97706;font-weight:bold}

.summary-box .row.change-row{
    color:#15803d;
    font-weight:bold;
    font-size:18px;
    background:#eefdf4;
    padding:10px 12px;
    border-radius:8px;
    border:2px solid #bbf7d0;
    margin-top:10px;
}
.summary-box .row.change-row #cp-change{
    font-size:26px !important;
    font-weight:900 !important;
    color:#16a34a !important;
}

.pay-inputs-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.pay-input-item{display:flex;align-items:center;gap:8px;background:#f1f5f9;padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1}
.pay-input-item label{font-size:13px;font-weight:bold;width:110px;color:#334155}
.pay-input-item input{flex:1;padding:8px;font-size:16px;border:1px solid #cbd5e1;border-radius:4px;outline:none;text-align:right;font-weight:bold}
.pay-input-item input:focus{border-color:#0078d4;box-shadow:0 0 0 2px rgba(0,120,212,0.2)}
.pay-input-item .remove-pay-btn{background:transparent;border:none;color:#ef4444;font-weight:bold;cursor:pointer;font-size:16px;padding:2px 6px}

.btn-add-custom-pay{background:#e2e8f0;color:#0f172a;border:1px dashed #94a3b8;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:bold;cursor:pointer;width:100%;margin-bottom:12px;text-align:center}
.btn-add-custom-pay:hover{background:#cbd5e1}

.numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:10px 0}
.numpad button{padding:10px;font-size:15px;border-radius:6px;border:1px solid #ccc;background:#f8fafc;cursor:pointer;font-weight:bold}
.numpad button:hover{background:#e2e8f0}
.actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.btn.primary{background:#0078d4;color:#fff;font-weight:bold}
.btn.secondary{background:#eee;color:#333}
.error{color:#b00020;margin-top:6px;min-height:18px;font-weight:bold;font-size:13px}

/* MODALES CLIENTE */
.modal-overlay{position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2000;display:flex;align-items:center;justify-content:center}
.modal-box{background:#fff;padding:20px;border-radius:8px;width:400px;max-width:90vw;box-shadow:0 10px 25px rgba(0,0,0,0.2);font-family:Arial,sans-serif}
.modal-box h3{margin-top:0;margin-bottom:12px;font-size:18px;color:#0f172a}
.form-group{margin-bottom:10px}
.form-group label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px;color:#475569}
.form-group input, .form-group select{width:100%;padding:8px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:4px;font-size:14px}
.cust-search-item{padding:8px;border-bottom:1px solid #e2e8f0;cursor:pointer;font-size:13px}
.cust-search-item:hover{background:#f1f5f9}

@media(max-width:800px){.pos-search input#global-search{width:200px;font-size:15px;padding:10px}header{flex-direction:column;align-items:flex-start}.header-left>div{flex-direction:column;gap:8px}.header-right{margin-top:10px}}
</style>
</head>
<body>

<header>
<div class="header-left">
<h2>Punto de Venta</h2>
<div>
Sucursal: <strong><?=htmlspecialchars($currentBranchName,ENT_QUOTES,'UTF-8')?></strong>
<a href="/mi_tienda/admin/caja.php">Caja</a>
<a href="/mi_tienda/admin/invoices_list.php">Facturas</a>
<a href="#">Traslado de Mercancía</a>
<a href="/mi_tienda/admin/cierre_corresponsal.php">Cierre Corresponsal</a>
<a href="/mi_tienda/admin/dashboard.php">Administrador</a>
<a href="/mi_tienda/admin/inventario_bajo.php">Inventario Bajo</a>
<a href="/mi_tienda/admin/gastos.php">Gastos Local</a>
<a href="/mi_tienda/admin/compras.php">🛒 Ir a Compras</a>
<a href="manual.php" style="text-decoration: none;">
    <div style="display: inline-flex; align-items: center; gap: 8px; background-color: #0284c7; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">
        📖 Manual de Usuario
    </div>
</a>
</div>

</div>
<div class="header-right">
    <a href="/mi_tienda/admin/logout.php" style="background-color: #dc2626 !important; color: #ffffff !important; padding: 10px 16px !important; border-radius: 6px !important; font-weight: bold !important; text-decoration: none !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; border: 1px solid #b91c1c !important; font-size: 14px !important;">
        🚪 Cerrar sesión
    </a>
</div>
</header>

<div class="container">

<?php if($saleError!==''):?>
<div class="sale-error"><?=htmlspecialchars($saleError,ENT_QUOTES,'UTF-8')?></div>
<?php endif;?>

<div class="pos-layout">

<div class="breadcrumb">
<span onclick="goToCategories()" style="cursor:pointer;font-weight:bold;color:#0078d4;">Todos los Productos</span>
<?php if($currentCategoryId>0):?> › <span onclick="goToCategory(<?=$currentCategoryId?>)" style="cursor:pointer;font-weight:bold;color:#0078d4;"><?=htmlspecialchars($currentCategoryName,ENT_QUOTES,'UTF-8')?></span><?php endif;?>
<?php if($currentSubcategoryId>0):?> › <span><?=htmlspecialchars($currentSubcategoryName,ENT_QUOTES,'UTF-8')?></span><?php endif;?>
</div>

<!-- NAVEGACIÓN RÁPIDA DE CATEGORÍAS Y SUBCATEGORÍAS VISIBLES -->
<div class="cat-nav-bar" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;">
    <?php if($currentCategoryId === 0): ?>
        <?php foreach($categories as $catItem): ?>
            <button type="button" class="btn" onclick="goToCategory(<?= $catItem['id'] ?>)" style="padding:6px 12px;font-size:13px;border-radius:20px;background:#e2e8f0;color:#1e293b;border:1px solid #cbd5e1;cursor:pointer;font-weight:bold;">
                📁 <?= htmlspecialchars($catItem['name'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endforeach; ?>
    <?php elseif($currentSubcategoryId === 0 && !empty($subcategories)): ?>
        <button type="button" class="btn" onclick="goToCategories()" style="padding:6px 12px;font-size:13px;border-radius:20px;background:#0078d4;color:#fff;border:none;cursor:pointer;font-weight:bold;">
            « Ver Todas las Categorías
        </button>
        <?php foreach($subcategories as $subItem): ?>
            <button type="button" class="btn" onclick="goToSubcategory(<?= $currentCategoryId ?>, <?= $subItem['id'] ?>)" style="padding:6px 12px;font-size:13px;border-radius:20px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;cursor:pointer;font-weight:bold;">
                📂 <?= htmlspecialchars($subItem['name'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- BUSCADOR CON AUTOFOCUS POR DEFECTO -->
<div class="pos-search-wrapper" style="margin:8px 0 12px">
<label for="global-search" class="sr-only">Buscar productos</label>
<div class="pos-search">
<input id="global-search" type="search" inputmode="search" placeholder="Buscar producto (nombre o SKU)" autocomplete="off" autofocus>
<button id="global-search-clear" type="button" aria-label="Limpiar">✕</button>
</div>
</div>

<h3>Productos</h3>
<?php if($currentSubcategoryId>0):?>
<button class="btn" onclick="goToCategory(<?=$currentCategoryId?>)" style="margin-bottom:10px;">⬅ Volver a subcategorías</button>
<?php endif;?>

<div class="grid-tactil" id="products-grid">
<?php if(empty($products)): ?>
    <div style="grid-column: 1 / -1; padding: 20px; background: #fff3cd; color: #856404; border-radius: 8px; border: 1px solid #ffeeba;">
        No hay productos disponibles para esta categoría o visibilidad en la sucursal actual.
    </div>
<?php endif; ?>

<?php foreach($products as $p):
$available=(int)$p['stock_available'];
$factor=max(1,(int)$p['conversion_factor']);
$disabled=$available<=0;
$name=(string)$p['display_name'];$jsData=[
    'id'=>(int)$p['product_id'],
    'tier' => $p['tier_id'] !== null ? (int)$p['tier_id'] : null,
    'name'=>$name,
    'price'=>(float)$p['price'],
    'image'=>(string)($p['image']??''),
    'label'=>(string)($p['unit_label']??'unidad'),
    'factor'=>$factor,
    'stock'=>$available,
    'stockUnits'=>(int)$p['stock_units']
];
?>
<div class="product-card <?=$disabled?'disabled':''?>"
 data-product='<?=htmlspecialchars(json_encode($jsData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8')?>'
 data-product-name="<?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?>"
 data-product-sku="<?=htmlspecialchars($p['sku']??'',ENT_QUOTES,'UTF-8')?>"
 onclick="<?=$disabled?'':'addCardToCart(this)'?>">

<?php if(!empty($p['image'])):?>
<img src="/mi_tienda/uploads/products/<?=htmlspecialchars($p['image'],ENT_QUOTES,'UTF-8')?>" alt="">
<?php else:?>
<img src="/mi_tienda/uploads/products/no-image.png" alt="">
<?php endif;?>

<div class="product-name"><?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?></div>
<div class="product-price">$<?=number_format((float)$p['price'],0,',','.')?></div>
<div class="product-stock" style="color:<?=$disabled?'#d9534f':'#0a6'?>">
Stock: <?=$available?> <?=htmlspecialchars($p['unit_label']??'unidad',ENT_QUOTES,'UTF-8')?>
</div>
<?php if($factor>1):?>
<div class="stock-equivalence">1 presentación = <?=$factor?> unidades mínimas · Stock físico: <?=(int)$p['stock_units']?></div>
<?php endif;?>
</div>
<?php endforeach;?>
</div>

<div class="pos-right">
<div class="cart-header-wrapper">
    <h3>Carrito</h3>
    <span class="ticket-rename-btn" onclick="renameCurrentTicket()" title="Cambiar nombre a esta cuenta en espera">✏️ Renombrar</span>
</div>

<!-- BARRA DE TICKETS / CUENTAS EN ESPERA -->
<div class="tickets-bar" id="tickets-bar"></div>

<!-- BARRA DE CLIENTE SELECCIONADO EN TICKET -->
<div class="customer-box">
    <div class="customer-info" id="cust-info-display">
        <strong>Consumidor Final</strong>
        <small>CC: 222222222222</small>
    </div>
    <div class="customer-actions">
        <button class="btn-cust" type="button" onclick="openSearchCustModal()">🔍 Buscar</button>
        <button class="btn-cust" type="button" onclick="openNewCustModal()">+ Nuevo</button>
    </div>
</div>

<div class="cart-panel">
<table class="cart">
<thead><tr><th>Prod.</th><th>Present.</th><th>Cant.</th><th>Subt.</th><th></th></tr></thead>
<tbody id="cart-body"></tbody>
</table>
</div>

<div class="cart-total">Total: $<span id="total">0</span></div>
<div class="cart-actions">
<button class="btn btn-danger" type="button" onclick="clearCart()">Limpiar</button>
<button class="btn btn-secondary" type="button" id="open-cash-panel-2">Cobrar</button>
</div>

<form action="?action=sell&cat=<?=$currentCategoryId?>&sub=<?=$currentSubcategoryId?>" method="POST" id="sell-form">
    <input type="hidden" name="items" id="items-input">
    <input type="hidden" name="payments_json" id="payments-json-input">
    <!-- CAMPOS OCULTOS DE CLIENTE -->
    <input type="hidden" name="customer_id" id="cust-id-input">
    <input type="hidden" name="customer_name" id="cust-name-input">
    <input type="hidden" name="customer_doc_type" id="cust-doctype-input">
    <input type="hidden" name="customer_doc_num" id="cust-docnum-input">
    <input type="hidden" name="customer_phone" id="cust-phone-input">
    <input type="hidden" name="customer_email" id="cust-email-input">
    <input type="hidden" name="customer_address" id="cust-address-input">
</form>

<!-- MODAL COBRO MULTI-PAGO -->
<div id="cash-panel" class="cash-panel" aria-hidden="true" style="display:none">
<div class="cash-panel-inner">
<h2>Cobrar Venta</h2>

<div class="summary-box">
    <div class="row total-row">
        <span>Total a Pagar:</span>
        <span id="cp-total">$ 0</span>
    </div>
    <div class="row pending-row">
        <span>Falta por Pagar:</span>
        <span id="cp-pending">$ 0</span>
    </div>
    <div class="row change-row">
        <span>Vuelto / Cambio:</span>
        <span id="cp-change">$ 0</span>
    </div>
</div>

<div style="font-size:12px; font-weight:bold; color:#475569; margin-bottom:6px;">MÉTODOS DE PAGO:</div>
<div class="pay-inputs-list" id="pay-inputs-list">
    <!-- Se genera dinámicamente -->
</div>

<button type="button" class="btn-add-custom-pay" onclick="addCustomPayRow()">+ Agregar otro canal / pago extra</button>

<div class="numpad">
<button data-key="7" type="button">7</button><button data-key="8" type="button">8</button><button data-key="9" type="button">9</button>
<button data-key="4" type="button">4</button><button data-key="5" type="button">5</button><button data-key="6" type="button">6</button>
<button data-key="1" type="button">1</button><button data-key="2" type="button">2</button><button data-key="3" type="button">3</button>
<button data-key="0" type="button">0</button><button data-key="." type="button">.</button><button data-key="back" type="button">⌫</button>
</div>

<div class="actions">
<button id="cp-cancel" class="btn secondary" type="button">Cancelar</button>
<button id="cp-confirm" class="btn primary" disabled type="button">Confirmar Venta (Enter ↵)</button>
</div>
<div id="cp-error" class="error"></div>
</div>
</div>

</div>
</div>
</div>

<!-- MODAL BÚSQUEDA DE CLIENTE -->
<div class="modal-overlay" id="search-cust-modal" style="display:none">
<div class="modal-box">
<h3>Buscar Cliente</h3>
<div class="form-group">
    <input type="text" id="cust-search-query" placeholder="Buscar por Nombre, Cédula/NIT o Teléfono..." autocomplete="off">
</div>
<div id="cust-search-results" style="max-height:200px;overflow-y:auto;margin-bottom:10px;"></div>
<div style="display:flex;gap:8px;justify-content:space-between">
    <button type="button" class="btn-cust" onclick="selectCustomer(defaultCustomer)">Consumidor Final</button>
    <button type="button" class="btn btn-danger" onclick="closeSearchCustModal()">Cerrar</button>
</div>
</div>
</div>

<!-- MODAL NUEVO CLIENTE (FACTURACIÓN ELECTRÓNICA) -->
<div class="modal-overlay" id="new-cust-modal" style="display:none">
<div class="modal-box">
<h3>Registrar Nuevo Cliente</h3>
<form id="new-cust-form" onsubmit="saveNewCustomer(event)">
    <div class="form-group">
        <label>Nombre / Razón Social *</label>
        <input type="text" id="nc-name" required placeholder="Ej: Juan Pérez o Distribuidora S.A.S">
    </div>
    <div style="display:flex;gap:8px">
        <div class="form-group" style="flex:1">
            <label>Tipo Doc.</label>
            <select id="nc-doctype">
                <option value="CC">CC (Cédula)</option>
                <option value="NIT">NIT (Empresa)</option>
                <option value="CE">CE (Extranjería)</option>
                <option value="PP">Pasaporte</option>
            </select>
        </div>
        <div class="form-group" style="flex:2">
            <label>N° Documento / NIT</label>
            <input type="text" id="nc-docnum" placeholder="Ej: 1015234890">
        </div>
    </div>
    <div class="form-group">
        <label>Teléfono / WhatsApp</label>
        <input type="text" id="nc-phone" placeholder="Ej: 3001234567">
    </div>
    <div class="form-group">
        <label>Correo Electrónico (Fact. Electrónica)</label>
        <input type="email" id="nc-email" placeholder="cliente@correo.com">
    </div>
    <div class="form-group">
        <label>Dirección de Facturación</label>
        <input type="text" id="nc-address" placeholder="Ej: Calle 15 # 4-20">
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn btn-danger" onclick="closeNewCustModal()">Cancelar</button>
        <button type="submit" class="btn" style="background:#0078d4;color:#fff">Guardar y Seleccionar</button>
    </div>
</form>
</div>
</div>

<!-- MODAL CANTIDAD -->
<div class="modal-overlay" id="modal-overlay" style="display:none">
<div class="modal">
<h3 id="modal-product-name">Cantidad</h3>
<div id="modal-stock-info" style="font-size:13px;color:#666;margin-bottom:8px"></div>
<input type="number" id="modal-qty" min="1" step="1" value="1">
<div class="modal-buttons">
<button type="button" class="btn btn-danger" onclick="closeQtyModal()">Cancelar</button>
<button type="button" class="btn" onclick="confirmQty()">Agregar</button>
</div>
</div>
</div>

<audio id="beep-sound" src="data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YQAAAAA="></audio>

<script>
function goToCategories(){location.href="pos.php?mode=tactil";}
function goToCategory(id){location.href="pos.php?mode=tactil&cat="+id;}
function goToSubcategory(cat,sub){location.href="pos.php?mode=tactil&cat="+cat+"&sub="+sub;}
function escapeHtml(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML;}

const defaultCustomer = { id: 1, name: 'Consumidor Final', doc_type: 'CC', doc_num: '222222222222', phone: '', email: '', address: '' };

/* ============================================================
   FUNCIONES DE FORMATO MONETARIO (COLOMBIA / SEPARACIÓN DE MILES)
============================================================ */
function moneyFormat(v) {
    const num = Number(v) || 0;
    return '$ ' + num.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function parseVal(v) {
    if (typeof v === 'number') return v;
    let str = String(v || '').trim();
    if (!str) return 0;

    str = str.replace(/\$/g, '').trim();

    if (str.includes('.') && str.includes(',')) {
        str = str.replace(/\./g, '').replace(',', '.');
    } else if (str.includes('.')) {
        const parts = str.split('.');
        if (parts.length > 2 || (parts.length === 2 && parts[1].length === 3)) {
            str = str.replace(/\./g, '');
        }
    } else if (str.includes(',')) {
        str = str.replace(',', '.');
    }

    str = str.replace(/[^\d.]/g, '');
    return parseFloat(str) || 0;
}

function formatLiveInput(inp) {
    let raw = inp.value.replace(/[^\d]/g, '');
    if (!raw) {
        inp.value = '';
        return;
    }
    let val = parseInt(raw, 10);
    inp.value = val.toLocaleString('es-CO');
}

/* ============================================================
   ESTADO MULTI-TICKETS (CUENTAS EN ESPERA) Y CLIENTE
============================================================ */
let tickets = [];
let activeTicketIndex = 0;

function loadTickets() {
    try {
        tickets = JSON.parse(localStorage.getItem('pos_tickets') || 'null');
    } catch(e) { tickets = null; }

    if (!Array.isArray(tickets) || tickets.length === 0) {
        let oldCart = [];
        try { oldCart = JSON.parse(localStorage.getItem('pos_cart') || '[]'); } catch(e){}
        tickets = [{
            id: Date.now(),
            name: 'Ticket 1',
            items: Array.isArray(oldCart) ? oldCart : [],
            customer: {...defaultCustomer}
        }];
        try { localStorage.removeItem('pos_cart'); } catch(e){}
    }

    try {
        let savedIndex = parseInt(localStorage.getItem('pos_active_ticket') || '0', 10);
        activeTicketIndex = (savedIndex >= 0 && savedIndex < tickets.length) ? savedIndex : 0;
    } catch(e) { activeTicketIndex = 0; }
}

loadTickets();

(function(){
    try{
        const p=new URLSearchParams(location.search);
        if(p.get('cleancart')==='1'){
            const u=new URL(location.href);
            u.searchParams.delete('cleancart');
            history.replaceState({},document.title,u.toString());
        }
    }catch(e){}
})();

function getCurrentTicket() {
    if (!tickets[activeTicketIndex]) activeTicketIndex = 0;
    if (!tickets[activeTicketIndex]) {
        tickets = [{ id: Date.now(), name: 'Ticket 1', items: [], customer: {...defaultCustomer} }];
        activeTicketIndex = 0;
    }
    if (!tickets[activeTicketIndex].customer) {
        tickets[activeTicketIndex].customer = {...defaultCustomer};
    }
    return tickets[activeTicketIndex];
}

function getCurrentCart() {
    return getCurrentTicket().items;
}

function saveTickets() {
    try {
        localStorage.setItem('pos_tickets', JSON.stringify(tickets));
        localStorage.setItem('pos_active_ticket', String(activeTicketIndex));
    } catch(e) {}
}

function renderTicketsBar() {
    const bar = document.getElementById('tickets-bar');
    if (!bar) return;

    let html = '';
    tickets.forEach((t, idx) => {
        const isActive = idx === activeTicketIndex;
        const itemCount = (t.items || []).reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
        
        html += `<div class="ticket-tab ${isActive ? 'active' : ''}" onclick="switchTicket(${idx})">
            <span>${escapeHtml(t.name || ('Ticket ' + (idx + 1)))}</span>
            ${itemCount > 0 ? `<span class="badge-count">${itemCount}</span>` : ''}
            <span class="close-tab" onclick="closeTicket(${idx}, event)" title="Cerrar esta cuenta">✕</span>
        </div>`;
    });

    html += `<button type="button" class="btn-add-ticket" onclick="addNewTicket()" title="Abrir una nueva cuenta en espera (+ F4)">+ Nuevo</button>`;

    bar.innerHTML = html;
}

function addNewTicket() {
    const nextNum = tickets.length + 1;
    tickets.push({
        id: Date.now(),
        name: 'Ticket ' + nextNum,
        items: [],
        customer: {...defaultCustomer}
    });
    activeTicketIndex = tickets.length - 1;
    saveTickets();
    renderCart();
    document.getElementById('global-search')?.focus();
}

function switchTicket(index) {
    if (index < 0 || index >= tickets.length) return;
    activeTicketIndex = index;
    saveTickets();
    renderCart();
    document.getElementById('global-search')?.focus();
}

function closeTicket(index, event) {
    if (event) event.stopPropagation();

    const t = tickets[index];
    if (!t) return;

    if (t.items && t.items.length > 0) {
        if (!confirm(`¿Desea cerrar y descartar la cuenta "${t.name}" con ${t.items.length} producto(s)?`)) {
            return;
        }
    }

    tickets.splice(index, 1);

    if (tickets.length === 0) {
        tickets = [{ id: Date.now(), name: 'Ticket 1', items: [], customer: {...defaultCustomer} }];
        activeTicketIndex = 0;
    } else if (activeTicketIndex >= tickets.length) {
        activeTicketIndex = tickets.length - 1;
    }

    saveTickets();
    renderCart();
    document.getElementById('global-search')?.focus();
}

function renameCurrentTicket() {
    const current = tickets[activeTicketIndex];
    if (!current) return;

    const newName = prompt('Nombre para esta cuenta / ticket (Ej: Cliente camisa azul, Mesa 2):', current.name);
    if (newName !== null) {
        const trimmed = newName.trim();
        current.name = trimmed !== '' ? trimmed : ('Ticket ' + (activeTicketIndex + 1));
        saveTickets();
        renderCart();
    }
}

/* GESTIÓN DE CLIENTES EN POS */
function renderCustomerDisplay() {
    const ticket = getCurrentTicket();
    const cust = ticket.customer || defaultCustomer;
    const disp = document.getElementById('cust-info-display');
    if (disp) {
        disp.innerHTML = `<strong>${escapeHtml(cust.name)}</strong>
        <small>${escapeHtml(cust.doc_type || 'CC')}: ${escapeHtml(cust.doc_num || 'S/N')} ${cust.phone ? '· Tel: '+escapeHtml(cust.phone) : ''}</small>`;
    }
    document.getElementById('cust-id-input').value = cust.id || '';
    document.getElementById('cust-name-input').value = cust.name || 'Consumidor Final';
    document.getElementById('cust-doctype-input').value = cust.doc_type || 'CC';
    document.getElementById('cust-docnum-input').value = cust.doc_num || '';
    document.getElementById('cust-phone-input').value = cust.phone || '';
    document.getElementById('cust-email-input').value = cust.email || '';
    document.getElementById('cust-address-input').value = cust.address || '';
}

function selectCustomer(cust) {
    const ticket = getCurrentTicket();
    ticket.customer = cust;
    saveTickets();
    renderCustomerDisplay();
    closeSearchCustModal();
}

function openSearchCustModal() {
    document.getElementById('search-cust-modal').style.display = 'flex';
    const q = document.getElementById('cust-search-query');
    q.value = ''; q.focus();
    searchCustomers('');
}

function closeSearchCustModal() {
    document.getElementById('search-cust-modal').style.display = 'none';
}

function searchCustomers(query) {
    fetch('pos.php?action=search_customer&q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(data => {
            const res = document.getElementById('cust-search-results');
            if (!data || !data.length) { res.innerHTML = '<div style="padding:10px;color:#777">No se encontraron clientes.</div>'; return; }
            let html = '';
            data.forEach(c => {
                html += `<div class="cust-search-item" onclick='selectCustomer(${JSON.stringify(c)})'>
                    <strong>${escapeHtml(c.name)}</strong> (${escapeHtml(c.doc_type)}: ${escapeHtml(c.doc_num)})
                    ${c.phone ? '<br><small>Tel: '+escapeHtml(c.phone)+'</small>' : ''}
                </div>`;
            });
            res.innerHTML = html;
        });
}

document.getElementById('cust-search-query')?.addEventListener('input', function() {
    searchCustomers(this.value);
});

function openNewCustModal() {
    document.getElementById('new-cust-modal').style.display = 'flex';
    document.getElementById('nc-name').focus();
}

function closeNewCustModal() {
    document.getElementById('new-cust-modal').style.display = 'none';
}

function saveNewCustomer(e) {
    e.preventDefault();

    const nameInput    = document.getElementById('nc-name');
    const docTypeInput = document.getElementById('nc-doctype');
    const docNumInput  = document.getElementById('nc-docnum');
    const phoneInput   = document.getElementById('nc-phone');
    const emailInput   = document.getElementById('nc-email');
    const addressInput = document.getElementById('nc-address');

    const data = {
        name: nameInput ? nameInput.value.trim() : '',
        doc_type: docTypeInput ? docTypeInput.value : 'CC',
        doc_num: docNumInput ? docNumInput.value.trim() : '',
        phone: phoneInput ? phoneInput.value.trim() : '',
        email: emailInput ? emailInput.value.trim() : '',
        address: addressInput ? addressInput.value.trim() : ''
    };

    if (!data.name) {
        alert('El nombre o razón social es obligatorio.');
        return;
    }

    fetch('pos.php?action=create_customer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(async response => {
        const rawText = await response.text();
        try {
            return JSON.parse(rawText);
        } catch (err) {
            throw new Error("Respuesta no válida del servidor: " + rawText);
        }
    })
    .then(res => {
        if (res.success) {
            selectCustomer(res.customer);
            closeNewCustModal();
            document.getElementById('new-cust-form').reset();
        } else {
            alert(res.error || 'No se pudo guardar el cliente.');
        }
    })
    .catch(error => {
        alert('Error al guardar cliente: ' + error.message);
    });
}

function cardData(card){
    try{return JSON.parse(card.getAttribute('data-product')||'{}');}catch(e){return {};}
}

function addCardToCart(card){
    const p=cardData(card);
    if(!p.id||p.stock<=0)return;
    addToCart(p,1);
}

function cartUsedStock(productId){
    let totalUnits = 0;
    tickets.forEach(ticket => {
        (ticket.items || []).forEach(x => {
            if (Number(x.product_id) === Number(productId)) {
                totalUnits += (Number(x.quantity) || 0) * (Number(x.conversion_factor) || 1);
            }
        });
    });
    return totalUnits;
}

function addToCart(p,quantity=1){
    quantity=parseInt(quantity,10)||1;
    if(quantity<=0)return;

    const required=quantity*(parseInt(p.factor,10)||1);
    const already=cartUsedStock(p.id);
    const stockUnits=parseInt(p.stockUnits,10)||0;

    if(already+required>stockUnits){
        alert('Stock insuficiente. Disponible en inventario considerando cuentas abiertas: '+(stockUnits - already)+' unidades mínimas.');
        return;
    }

    const cart = getCurrentCart();
    const index=cart.findIndex(x=>
        Number(x.product_id)===Number(p.id)&&
        String(x.tier_id??'')===String(p.tier??'')
    );

    if(index>=0) cart[index].quantity+=quantity;
    else cart.push({
        product_id:Number(p.id),
        tier_id:p.tier===null?null:Number(p.tier),
        name:p.name,
        price:Number(p.price)||0,
        quantity:quantity,
        image:p.image||'',
        unit_label:p.label||'unidad',
        conversion_factor:parseInt(p.factor,10)||1,
        stock_units:stockUnits,
        stock_available:parseInt(p.stock,10)||0
    });

    renderCart();
    playBeep();
}

function renderCart(){
    const cart = getCurrentCart();
    const tbody=document.getElementById('cart-body');
    const totalEl=document.getElementById('total');
    const itemsInput=document.getElementById('items-input');

    renderTicketsBar();
    renderCustomerDisplay();

    if(!tbody||!totalEl||!itemsInput)return;

    let html='',total=0;

    cart.forEach((item,i)=>{
        const subtotal=(Number(item.price)||0)*(Number(item.quantity)||0);
        const units=(Number(item.quantity)||0)*(Number(item.conversion_factor)||1);
        total+=subtotal;

        html+=`<tr>
        <td>${escapeHtml(item.name)}</td>
        <td>${escapeHtml(item.unit_label||'unidad')}
        ${Number(item.conversion_factor)>1?`<span class="cart-equivalence">${units} unidades mín.</span>`:''}</td>
        <td>${item.quantity}</td>
        <td>$${subtotal.toLocaleString('es-CO', {minimumFractionDigits:0, maximumFractionDigits:2})}</td>
        <td><button class="btn btn-danger" type="button" onclick="removeItem(${i})">X</button></td>
        </tr>`;
    });

    tbody.innerHTML=html;
    totalEl.textContent=total.toLocaleString('es-CO', {minimumFractionDigits:0, maximumFractionDigits:2});
    itemsInput.value=JSON.stringify(cart);
    saveTickets();
}

function removeItem(i){
    const cart = getCurrentCart();
    cart.splice(i,1);
    renderCart();
}

function clearCart(){
    const cart = getCurrentCart();
    if(!cart.length)return;
    if(!confirm('¿Vaciar carrito de la cuenta activa?'))return;
    tickets[activeTicketIndex].items = [];
    renderCart();
}

/* CANTIDAD MODAL */
let currentProduct=null;

function openQtyModalFromCard(card){
    const p=cardData(card);
    if(!p.id||p.stock<=0)return;
    currentProduct=p;

    const overlay=document.getElementById('modal-overlay');
    const qty=document.getElementById('modal-qty');
    const title=document.getElementById('modal-product-name');
    const info=document.getElementById('modal-stock-info');

    title.textContent=p.name;
    qty.value=1;
    qty.max=p.stock;
    info.textContent='Disponible: '+p.stock+' '+p.label+
        (p.factor>1?' · '+p.factor+' unidades mínimas por presentación':'');
    overlay.style.display='flex';
    qty.focus();
}

function closeQtyModal(){
    document.getElementById('modal-overlay').style.display='none';
    currentProduct=null;
    document.getElementById('global-search')?.focus();
}

function confirmQty(){
    if(!currentProduct)return;
    const qty=parseInt(document.getElementById('modal-qty').value,10);
    if(!qty||qty<=0)return;
    addToCart(currentProduct,qty);
    closeQtyModal();
}

/* LONG PRESS */
document.querySelectorAll('.product-card:not(.disabled)').forEach(card=>{
    let timer=null,longPressed=false;

    const start=()=>{
        longPressed=false;
        timer=setTimeout(()=>{
            longPressed=true;
            openQtyModalFromCard(card);
        },500);
    };

    const cancel=()=>{
        if(timer){clearTimeout(timer);timer=null;}
        if(longPressed)setTimeout(()=>longPressed=false,100);
    };

    card.addEventListener('mousedown',start);
    card.addEventListener('touchstart',start,{passive:true});
    card.addEventListener('mouseup',cancel);
    card.addEventListener('mouseleave',cancel);
    card.addEventListener('touchend',cancel);
    card.addEventListener('touchcancel',cancel);

    card.addEventListener('click',e=>{
        if(longPressed){
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    },true);
});

/* ============================================================
   SISTEMA DE COBRO MULTI-MÉTODOS / PAGOS MIXTOS
============================================================ */
let activePayInput = null;
let customPayCounter = 0;

const defaultPayMethods = [
    { key: 'efectivo', label: '💵 Efectivo Recibido' },
    { key: 'Pago Virtual', label: '📱 Pago Virtual' },
];

function initPayInputs() {
    const list = document.getElementById('pay-inputs-list');
    if (!list) return;

    let html = '';
    defaultPayMethods.forEach(m => {
        html += `<div class="pay-input-item">
            <label for="pay-input-${m.key}">${m.label}</label>
            <input type="text" id="pay-input-${m.key}" class="pay-amount-input" data-method="${m.key}" inputmode="numeric" placeholder="0" autocomplete="off">
        </div>`;
    });

    list.innerHTML = html;
    customPayCounter = 0;

    attachPayInputEvents();
    activePayInput = document.getElementById('pay-input-efectivo');
}

function attachPayInputEvents() {
    document.querySelectorAll('.pay-amount-input').forEach(inp => {
        inp.removeEventListener('focus', onPayFocus);
        inp.removeEventListener('input', onPayInput);
        inp.removeEventListener('keydown', onPayKeydown);

        inp.addEventListener('focus', onPayFocus);
        inp.addEventListener('input', onPayInput);
        inp.addEventListener('keydown', onPayKeydown);
    });
}

function onPayFocus() {
    activePayInput = this;
}

function onPayInput() {
    formatLiveInput(this);
    updatePayTotals();
}

function onPayKeydown(e) {
    if (e.key === 'Enter' || e.key === 'Intro') {
        e.preventDefault();
        const confirmBtn = document.getElementById('cp-confirm');
        if (confirmBtn && !confirmBtn.disabled) {
            confirmBtn.click();
        }
    }
}

function addCustomPayRow() {
    customPayCounter++;
    const key = 'custom_' + customPayCounter;
    const name = prompt('Nombre del canal o pago adicional (Ej: Transferencia Bancolombia, Bono, etc.):', 'Otro ' + customPayCounter);
    if (!name) return;

    const list = document.getElementById('pay-inputs-list');
    const div = document.createElement('div');
    div.className = 'pay-input-item';
    div.innerHTML = `
        <label for="pay-input-${key}">🏷️ ${escapeHtml(name)}</label>
        <input type="text" id="pay-input-${key}" class="pay-amount-input" data-method="${escapeHtml(name)}" inputmode="numeric" placeholder="0" autocomplete="off">
        <button type="button" class="remove-pay-btn" onclick="removePayRow(this)" title="Eliminar este canal">✕</button>
    `;
    list.appendChild(div);

    attachPayInputEvents();
    const newInp = div.querySelector('input');
    newInp.focus();
    updatePayTotals();
}

function removePayRow(btn) {
    const item = btn.closest('.pay-input-item');
    if (item) item.remove();
    updatePayTotals();
}

function updatePayTotals() {
    const totalOrder = parseVal(document.getElementById('total').textContent);
    
    let cashReceived = 0;
    let otherPaymentsSum = 0;

    document.querySelectorAll('.pay-amount-input').forEach(inp => {
        const val = parseVal(inp.value);
        const m = inp.getAttribute('data-method') || '';
        if (m === 'efectivo') {
            cashReceived = val;
        } else {
            otherPaymentsSum += val;
        }
    });

    const pendingForCash = Math.max(0, totalOrder - otherPaymentsSum);
    const totalCovered = otherPaymentsSum + cashReceived;

    const pendingAmount = Math.max(0, totalOrder - totalCovered);
    const changeAmount = (cashReceived > pendingForCash) ? (cashReceived - pendingForCash) : 0;

    document.getElementById('cp-total').textContent = moneyFormat(totalOrder);
    document.getElementById('cp-pending').textContent = moneyFormat(pendingAmount);
    document.getElementById('cp-change').textContent = moneyFormat(changeAmount);

    const confirmBtn = document.getElementById('cp-confirm');
    const errorEl = document.getElementById('cp-error');

    if (totalOrder <= 0) {
        confirmBtn.disabled = true;
        errorEl.textContent = 'El carrito está vacío.';
    } else if (totalCovered < totalOrder - 0.01) {
        confirmBtn.disabled = true;
        errorEl.textContent = 'Falta cubrir ' + moneyFormat(pendingAmount);
    } else {
        confirmBtn.disabled = false;
        errorEl.textContent = '';
    }
}

(function(){
    const panel = document.getElementById('cash-panel');
    const openBtn = document.getElementById('open-cash-panel-2');
    const confirmBtn = document.getElementById('cp-confirm');
    const cancelBtn = document.getElementById('cp-cancel');
    const form = document.getElementById('sell-form');

    openBtn.addEventListener('click', () => {
        const cart = getCurrentCart();
        if (!cart.length) { alert('El carrito está vacío.'); return; }

        initPayInputs();
        panel.style.display = 'block';
        panel.setAttribute('aria-hidden', 'false');
        updatePayTotals();
        
        const firstCash = document.getElementById('pay-input-efectivo');
        if (firstCash) firstCash.focus();
    });

    document.querySelectorAll('.numpad button').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!activePayInput) return;
            const k = btn.dataset.key;
            if (k === 'back') {
                let raw = activePayInput.value.replace(/[^\d]/g, '');
                raw = raw.slice(0, -1);
                activePayInput.value = raw ? parseInt(raw, 10).toLocaleString('es-CO') : '';
            } else if (k !== '.') {
                let raw = activePayInput.value.replace(/[^\d]/g, '') + (k || '');
                activePayInput.value = parseInt(raw, 10).toLocaleString('es-CO');
            }
            updatePayTotals();
            activePayInput.focus();
        });
    });

    cancelBtn.addEventListener('click', () => {
        panel.style.display = 'none';
        panel.setAttribute('aria-hidden', 'true');
        document.getElementById('cp-error').textContent = '';
        document.getElementById('global-search')?.focus();
    });

    confirmBtn.addEventListener('click', () => {
        const cart = getCurrentCart();
        if (!cart.length) return;

        const totalOrder = parseVal(document.getElementById('total').textContent);
        const paymentsList = [];
        let totalCovered = 0;

        let otherPaymentsSum = 0;
        document.querySelectorAll('.pay-amount-input').forEach(inp => {
            const m = inp.getAttribute('data-method') || '';
            if (m !== 'efectivo') otherPaymentsSum += parseVal(inp.value);
        });

        const pendingForCash = Math.max(0, totalOrder - otherPaymentsSum);

        document.querySelectorAll('.pay-amount-input').forEach(inp => {
            const val = parseVal(inp.value);
            const m = inp.getAttribute('data-method') || 'efectivo';

            if (val > 0 || (m === 'efectivo' && pendingForCash > 0)) {
                if (m === 'efectivo') {
                    const cashApplied = Math.min(val, pendingForCash);
                    const changeGiven = Math.max(0, val - pendingForCash);
                    paymentsList.push({
                        method: 'efectivo',
                        amount: cashApplied,
                        cash_received: val,
                        change_given: changeGiven
                    });
                    totalCovered += val;
                } else {
                    paymentsList.push({
                        method: m,
                        amount: val
                    });
                    totalCovered += val;
                }
            }
        });

        if (totalCovered < totalOrder - 0.01) {
            document.getElementById('cp-error').textContent = 'Pago insuficiente.';
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Procesando...';

        document.getElementById('items-input').value = JSON.stringify(cart);
        document.getElementById('payments-json-input').value = JSON.stringify(paymentsList);

        form.submit();
    });
})();

/* BÚSQUEDA Y AUTO-FOCUS CON OCULTAMIENTO DE CATEGORÍAS */
(function(){
    const input = document.getElementById('global-search');
    const clear = document.getElementById('global-search-clear');
    const grid  = document.getElementById('products-grid');
    const catNav = document.querySelector('.cat-nav-bar'); // Selecciona el contenedor de categorías

    if(!input || !grid) return;

    function filter(term){
        term = String(term || '').trim().toLowerCase();

        // Oculta la barra de categorías si hay búsqueda activa; la vuelve a mostrar si está vacía
        if (catNav) {
            catNav.style.display = term !== '' ? 'none' : 'flex';
        }

        grid.querySelectorAll('.product-card').forEach(card => {
            const name = (card.dataset.productName || '').toLowerCase();
            const sku  = (card.dataset.productSku || '').toLowerCase();
            card.style.display = !term || name.includes(term) || sku.includes(term) ? '' : 'none';
        });
    }

    let timer;
    input.addEventListener('input', function(){
        clearTimeout(timer);
        timer = setTimeout(() => filter(this.value), 120);
    });

    clear.addEventListener('click', () => {
        input.value = '';
        filter('');
        input.focus();
    });

    try {
        const q = new URLSearchParams(location.search).get('q') || '';
        if(q) { input.value = q; filter(q); }
    } catch(e){}
})();

function playBeep(){
    const beep = document.getElementById('beep-sound');
    if(beep){
        beep.currentTime = 0;
        beep.play().catch(() => {});
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('global-search');
    if (searchInput) {
        searchInput.focus();
    }
});

/* TECLAS DE ACCESO RÁPIDO */
document.addEventListener('keydown', e => {
    const overlay = document.getElementById('modal-overlay');
    const modalQty = overlay && overlay.style.display === 'flex';

    const cashPanel = document.getElementById('cash-panel');
    const isCashPanelOpen = cashPanel && cashPanel.style.display !== 'none';

    if (isCashPanelOpen && (e.key === 'Enter' || e.key === 'Intro')) {
        const confirmBtn = document.getElementById('cp-confirm');
        if (confirmBtn && !confirmBtn.disabled) {
            e.preventDefault();
            confirmBtn.click();
            return;
        }
    }

    if(e.key === 'Escape' && modalQty) { closeQtyModal(); return; }
    if(e.key === 'Enter' && modalQty) { e.preventDefault(); confirmQty(); return; }

    if(!modalQty && !isCashPanelOpen){
        if(e.key === 'F1'){
            e.preventDefault();
            document.getElementById('global-search')?.focus();
        }
        if(e.key === 'F2'){
            e.preventDefault();
            document.getElementById('open-cash-panel-2')?.click();
        }
        if(e.key === 'F3'){
            e.preventDefault();
            clearCart();
        }
        if(e.key === 'F4'){
            e.preventDefault();
            addNewTicket();
        }
    }
});

renderCart();
</script>
</body>
</html>
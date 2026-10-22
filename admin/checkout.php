<?php
// admin/checkout.php
// Procesa ventas POS por JSON o POST.
// IMPORTANTE: inventory.quantity e inventory_movements.quantity trabajan en UNIDADES MÍNIMAS.

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__.'/../includes/auth_functions.php';
require_once __DIR__.'/../includes/db.php';

$pdo=DB::getConnection();

/* ============================================================
   1. RESPUESTA JSON + SESIÓN
============================================================ */
function jsonResponse(bool $success,string $message,array $extra=[],int $status=200):never{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id=(int)($_SESSION['user']['id']??$_SESSION['user_id']??$_SESSION['id_usuario']??$_SESSION['id']??0);
$branch_id=(int)($_SESSION['branch_id']??0);

if(!$user_id)jsonResponse(false,'No autenticado',[],401);
if(!$branch_id)jsonResponse(false,'Sucursal no definida',[],400);

/* ============================================================
   2. LEER JSON O POST
============================================================ */
$raw=file_get_contents('php://input');
$data=json_decode($raw,true);
if(!is_array($data))$data=$_POST;

$items=$data['items']??[];
$payments=$data['payments']??[];

if(is_string($items)){
    $tmp=json_decode($items,true);
    $items=is_array($tmp)?$tmp:[];
}
if(is_string($payments)){
    $tmp=json_decode($payments,true);
    $payments=is_array($tmp)?$tmp:[];
}

if(!is_array($items)||!$items)jsonResponse(false,'No hay productos en la venta',[],400);
if(!is_array($payments)||!$payments)jsonResponse(false,'No se recibió información de pago',[],400);

/* ============================================================
   3. CONVERSIÓN DE PRESENTACIONES

   package    = embalaje completo
   inner      = paquete/contenedor
   individual = unidad mínima

   Ejemplo vasos:
   60 paquetes por caja
   50 vasos por paquete

   package    = 3000 unidades
   inner      = 50 unidades
   individual = 1 unidad
============================================================ */
function conversionFactor(array $product,string $level):int{
    $packages=max(1,(int)($product['packaging_qty']??1));
    $hasInner=!empty($product['has_inner_units']);
    $inner=$hasInner?max(1,(int)($product['inner_unit_qty']??1)):1;

    return match($level){
        'package'=>$packages*$inner,
        'inner'=>$inner,
        'individual'=>1,
        default=>throw new RuntimeException('Presentación de venta inválida.')
    };
}

function presentationName(string $level):string{
    return match($level){
        'package'=>'embalaje',
        'inner'=>'paquete/contenedor',
        'individual'=>'unidad',
        default=>'presentación'
    };
}

/* ============================================================
   4. CONSULTAS PREPARADAS
============================================================ */
$stmtProduct=$pdo->prepare("
    SELECT id,name,sku,price,price_unit,packaging_type,packaging_qty,
           has_inner_units,inner_unit_qty,active
    FROM products
    WHERE id=? AND active=1
    LIMIT 1
");

$stmtTier=$pdo->prepare("
    SELECT id,product_id,unit_label,unit_level,quantity,price
    FROM product_prices
    WHERE id=? AND product_id=?
    LIMIT 1
");

$stmtStock=$pdo->prepare("
    SELECT id,quantity
    FROM inventory
    WHERE product_id=? AND branch_id=?
    FOR UPDATE
");

$stmtInventoryUpdate=$pdo->prepare("
    UPDATE inventory
    SET quantity=quantity-?
    WHERE product_id=? AND branch_id=? AND quantity>=?
");

$stmtOrderItem=$pdo->prepare("
    INSERT INTO order_items
    (order_id,product_id,product_name,tier_id,unit_label,quantity,price,subtotal)
    VALUES(?,?,?,?,?,?,?,?)
");

$stmtMovement=$pdo->prepare("
    INSERT INTO inventory_movements
    (product_id,branch_id,user_id,type,quantity,note,created_at)
    VALUES(?,?,?,'salida',?,?,NOW())
");

/* ============================================================
   5. PROCESAR VENTA
============================================================ */
try{
    $pdo->beginTransaction();

    /* Método de pago principal */
    $payment_method=count($payments)===1
        ?trim((string)($payments[0]['type']??'efectivo'))
        :'mixto';

    if($payment_method==='')$payment_method='efectivo';

    /* Primera referencia no efectiva */
    $transaction_ref=null;
    foreach($payments as $p){
        $type=trim((string)($p['type']??''));
        if($type!==''&&$type!=='efectivo'&&!empty($p['ref'])){
            $transaction_ref=trim((string)$p['ref']);
            break;
        }
    }

    /* Crear orden */
    $stmt=$pdo->prepare("
        INSERT INTO orders
        (user_id,branch_id,total,status,payment_method,transaction_ref,created_at)
        VALUES(?,?,0,'completado',?,?,NOW())
    ");
    $stmt->execute([$user_id,$branch_id,$payment_method,$transaction_ref]);

    $order_id=(int)$pdo->lastInsertId();
    if($order_id<=0)throw new RuntimeException('No fue posible crear la orden.');

    $total=0;

    /* ========================================================
       6. PRODUCTOS
    ======================================================== */
    foreach($items as $item){
        $pid=(int)($item['product_id']??0);
        $saleQty=(int)($item['quantity']??0);
        $tierId=!empty($item['tier_id'])?(int)$item['tier_id']:null;

        if($pid<=0||$saleQty<=0)
            throw new RuntimeException('La venta contiene un producto o cantidad inválida.');

        /* Producto real desde BD */
        $stmtProduct->execute([$pid]);
        $product=$stmtProduct->fetch(PDO::FETCH_ASSOC);

        if(!$product)
            throw new RuntimeException("El producto ID {$pid} no existe o está inactivo.");

        $level='individual';
        $unitLabel='unidad';
        $tierQuantity=1;
        $price=0;

        /* ====================================================
           6.1 PRECIO POR PRESENTACIÓN
        ==================================================== */
        if($tierId){
            $stmtTier->execute([$tierId,$pid]);
            $tier=$stmtTier->fetch(PDO::FETCH_ASSOC);

            if(!$tier)
                throw new RuntimeException("La presentación seleccionada no pertenece a {$product['name']}.");

            $level=(string)($tier['unit_level']??'individual');

            if(!in_array($level,['package','inner','individual'],true))
                throw new RuntimeException("Presentación inválida para {$product['name']}.");

            if($level==='inner'&&empty($product['has_inner_units']))
                throw new RuntimeException("{$product['name']} no tiene unidades internas configuradas.");

            $unitLabel=trim((string)($tier['unit_label']??''));
            if($unitLabel==='')$unitLabel=presentationName($level);

            /*
             * quantity de product_prices permite presentaciones como:
             * 1 paquete
             * 6 unidades
             * 12 unidades
             * etc.
             */
            $tierQuantity=max(1,(int)($tier['quantity']??1));
            $price=(float)($tier['price']??0);

            if($price<=0)
                throw new RuntimeException("La presentación {$unitLabel} de {$product['name']} no tiene precio válido.");
        }else{
            /*
             * Sin tier se considera unidad individual.
             * El precio SIEMPRE se obtiene de la BD.
             */
            $level='individual';
            $unitLabel='unidad';
            $tierQuantity=1;
            $price=(float)($product['price_unit']??0);

            if($price<=0)$price=(float)($product['price']??0);

            if($price<=0)
                throw new RuntimeException("{$product['name']} no tiene precio válido.");
        }

        /* ====================================================
           6.2 CONVERTIR A UNIDADES MÍNIMAS
        ==================================================== */
        $factor=conversionFactor($product,$level);
        $unitsPerSale=$factor*$tierQuantity;
        $unitsToRemove=$saleQty*$unitsPerSale;

        if($unitsToRemove<=0)
            throw new RuntimeException("Conversión inválida para {$product['name']}.");

        /*
         * Ejemplo:
         *
         * package:
         * 1 × 60 × 50 = 3000 unidades
         *
         * inner:
         * 1 × 50 = 50 unidades
         *
         * individual:
         * 1 × 1 = 1 unidad
         */

        /* ====================================================
           6.3 BLOQUEAR STOCK REAL
        ==================================================== */
        $stmtStock->execute([$pid,$branch_id]);
        $inventory=$stmtStock->fetch(PDO::FETCH_ASSOC);

        if(!$inventory)
            throw new RuntimeException("{$product['name']} no tiene inventario registrado en esta sucursal.");

        $available=(int)$inventory['quantity'];

        /* ====================================================
           6.4 EVITAR VENTA SIN STOCK
        ==================================================== */
        if($available<$unitsToRemove){
            $availablePresentations=intdiv($available,max(1,$unitsPerSale));

            throw new RuntimeException(
                "Stock insuficiente de {$product['name']}. ".
                "Disponible: {$available} unidad(es), equivalente a ".
                "{$availablePresentations} {$unitLabel}(s). ".
                "La venta requiere {$unitsToRemove} unidad(es)."
            );
        }

        /* ====================================================
           6.5 DESCONTAR INVENTARIO
           Segunda protección: quantity>=unitsToRemove
        ==================================================== */
        $stmtInventoryUpdate->execute([
            $unitsToRemove,
            $pid,
            $branch_id,
            $unitsToRemove
        ]);

        if($stmtInventoryUpdate->rowCount()!==1)
            throw new RuntimeException("No se pudo descontar el inventario de {$product['name']}.");

        /* ====================================================
           6.6 PRECIO Y SUBTOTAL
           El precio recibido desde JS NO se utiliza.
        ==================================================== */
        $subtotal=round($price*$saleQty,2);
        $total+=$subtotal;

        /* ====================================================
           6.7 GUARDAR ITEM
        ==================================================== */
        $stmtOrderItem->execute([
            $order_id,
            $pid,
            $product['name'],
            $tierId,
            $unitLabel,
            $saleQty,
            $price,
            $subtotal
        ]);

        /* ====================================================
           6.8 MOVIMIENTO EN UNIDADES MÍNIMAS
        ==================================================== */
        $remaining=$available-$unitsToRemove;

        $movementNote=
            "Venta POS #{$order_id}: {$saleQty} {$unitLabel}(s), ".
            "{$unitsPerSale} unidad(es) por presentación, ".
            "{$unitsToRemove} unidad(es) descontadas. ".
            "Stock restante: {$remaining}.";

        $stmtMovement->execute([
            $pid,
            $branch_id,
            $user_id,
            $unitsToRemove,
            $movementNote
        ]);
    }

    /* ========================================================
       7. TOTAL DE LA ORDEN
    ======================================================== */
    $total=round($total,2);

    if($total<=0)
        throw new RuntimeException('El total de la venta no es válido.');

    $stmt=$pdo->prepare("UPDATE orders SET total=? WHERE id=?");
    $stmt->execute([$total,$order_id]);

    /* ========================================================
       8. VALIDAR PAGOS
    ======================================================== */
    $sumPayments=0;

    foreach($payments as $p){
        $amount=(float)($p['amount']??0);
        if($amount>0)$sumPayments+=$amount;
    }

    $sumPayments=round($sumPayments,2);

    if($sumPayments<$total){
        throw new RuntimeException(
            'Pago insuficiente. Total: $'.
            number_format($total,2,'.','').
            ' | Recibido: $'.
            number_format($sumPayments,2,'.','')
        );
    }

    $changeTotal=round(max(0,$sumPayments-$total),2);

    /* ========================================================
       9. REGISTRAR PAGOS
       Si falla cualquiera, TODA la venta hace rollback.
    ======================================================== */
    $stmtPayment=$pdo->prepare("
        INSERT INTO payments
        (order_id,amount,method,reference,status,cash_received,change_given,user_id,created_at)
        VALUES(?,?,?,?,'completado',?,?,?,NOW())
    ");

    $changeAssigned=false;
    $validPayments=0;

    foreach($payments as $p){
        $amount=(float)($p['amount']??0);
        if($amount<=0)continue;

        $type=trim((string)($p['type']??'otro'));
        if($type==='')$type='otro';

        $ref=isset($p['ref'])&&trim((string)$p['ref'])!==''
            ?trim((string)$p['ref'])
            :null;

        $cash_received=null;
        $change_given=null;

        if($type==='efectivo'){
            $cash_received=$amount;

            if(!$changeAssigned){
                $change_given=$changeTotal;
                $changeAssigned=true;
            }else{
                $change_given=0;
            }
        }

        $stmtPayment->execute([
            $order_id,
            $amount,
            $type,
            $ref,
            $cash_received,
            $change_given,
            $user_id
        ]);

        $validPayments++;
    }

    if($validPayments===0)
        throw new RuntimeException('No se registró ningún pago válido.');

    /* ========================================================
       10. CONFIRMAR TODO
    ======================================================== */
    $pdo->commit();

    $receipt_url='/mi_tienda/admin/invoice_print.php?order_id='.$order_id;

    jsonResponse(true,'Venta procesada correctamente',[
        'order_id'=>$order_id,
        'total'=>$total,
        'paid'=>$sumPayments,
        'change'=>$changeTotal,
        'receipt_url'=>$receipt_url
    ]);

}catch(Throwable $e){

    /* Cualquier fallo revierte orden, items, stock, movimientos y pagos. */
    if($pdo->inTransaction())$pdo->rollBack();

    error_log('checkout.php error: '.$e->getMessage());

    jsonResponse(
        false,
        $e->getMessage()?:'Error interno al procesar la venta',
        [],
        400
    );
}
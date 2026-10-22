<?php
// admin/inventario.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__.'/../includes/auth_functions.php';
require_once __DIR__.'/../includes/db.php';

require_role([1,4]);
$pdo=DB::getConnection();
$msg='';
$action=$_GET['action']??null;

/* ============================================================
   1. SUCURSAL Y USUARIO
============================================================ */
$currentBranchId=(int)($_GET['branch_id']??$_SESSION['branch_id']??0);
if(!$currentBranchId)die('No hay sucursal seleccionada. Vuelva al dashboard.');

$stmtB=$pdo->prepare("SELECT name FROM branches WHERE id=?");
$stmtB->execute([$currentBranchId]);
$currentBranchName=$stmtB->fetchColumn()?:($_SESSION['branch_name']??'Seleccionada');

$allBranches=$pdo->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$user_id=$_SESSION['user_id']??$_SESSION['user']['id']??$_SESSION['id_usuario']??$_SESSION['id']??null;
if(!$user_id)die('Error de sesión: No se identificó el usuario actual.');

/* ============================================================
   2. PRODUCTOS Y CONVERSIONES
   inventory.quantity siempre representa UNIDADES MÍNIMAS.
============================================================ */
$products=$pdo->query("
    SELECT id,name,sku,packaging_type,packaging_qty,has_inner_units,inner_unit_qty
    FROM products WHERE active=1 ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/*
 Ejemplo vasos:
 packaging_qty=60
 has_inner_units=1
 inner_unit_qty=50

 package    = caja completa = 60*50 = 3000 vasos
 inner      = paquete       = 50 vasos
 individual = vaso          = 1 vaso
*/
function inventoryFactors(array $p):array{
    $packs=max(1,(int)($p['packaging_qty']??1));
    $has=!empty($p['has_inner_units']);
    $inner=$has?max(1,(int)($p['inner_unit_qty']??1)):1;
    return [
        'package'=>$packs*$inner,
        'inner'=>$inner,
        'individual'=>1
    ];
}

function presentationName(string $level):string{
    return match($level){
        'package'=>'embalaje(s)',
        'inner'=>'paquete(s)',
        default=>'unidad(es)'
    };
}

/* Obtiene producto y convierte la cantidad indicada a unidades mínimas. */
function movementData(PDO $pdo,int $productId,string $level,int $qty):?array{
    $s=$pdo->prepare("
        SELECT id,name,sku,packaging_type,packaging_qty,has_inner_units,inner_unit_qty
        FROM products WHERE id=? AND active=1 LIMIT 1
    ");
    $s->execute([$productId]);
    $p=$s->fetch(PDO::FETCH_ASSOC);
    if(!$p)return null;

    $f=inventoryFactors($p);
    if(!isset($f[$level]))$level='individual';

    return [
        'product'=>$p,
        'level'=>$level,
        'factor'=>$f[$level],
        'units'=>$qty*$f[$level],
        'factors'=>$f
    ];
}

/* Desglosa unidades mínimas en cajas + paquetes + unidades sueltas. */
function stockBreakdown(array $row):array{
    $stock=max(0,(int)($row['quantity']??0));
    $packs=max(1,(int)($row['packaging_qty']??1));
    $has=!empty($row['has_inner_units']);
    $inner=$has?max(1,(int)($row['inner_unit_qty']??1)):1;
    $boxUnits=$packs*$inner;

    $boxes=intdiv($stock,$boxUnits);
    $remaining=$stock%$boxUnits;

    if($has){
        $packages=intdiv($remaining,$inner);
        $units=$remaining%$inner;
    }else{
        $packages=0;
        $units=$remaining;
    }

    return [
        'boxes'=>$boxes,
        'packages'=>$packages,
        'units'=>$units,
        'box_units'=>$boxUnits,
        'package_units'=>$inner
    ];
}

/* ============================================================
   3. ENTRADA DE INVENTARIO
============================================================ */
if($action==='entrada'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $product_id=(int)($_POST['product_id']??0);
    $qty=(int)($_POST['quantity']??0);
    $level=$_POST['unit_level']??'individual';
    $note=trim($_POST['note']??'');

    if($product_id>0&&$qty>0){
        try{
            $data=movementData($pdo,$product_id,$level,$qty);
            if(!$data){
                $msg='El producto no existe o está inactivo.';
            }else{
                $units=(int)$data['units'];
                $level=$data['level'];
                $pdo->beginTransaction();

                $s=$pdo->prepare("
                    INSERT INTO inventory(product_id,branch_id,quantity)
                    VALUES(?,?,?)
                    ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)
                ");
                $s->execute([$product_id,$currentBranchId,$units]);

                $movementNote="Entrada: $qty ".presentationName($level)." = $units unidad(es) mínima(s).";
                if($note!=='')$movementNote.=" Nota: $note";

                $m=$pdo->prepare("
                    INSERT INTO inventory_movements(product_id,branch_id,user_id,type,quantity,note)
                    VALUES(?,?,?,'entrada',?,?)
                ");
                $m->execute([$product_id,$currentBranchId,$user_id,$units,$movementNote]);

                $pdo->commit();
                $msg="Entrada registrada: $qty ".presentationName($level)." = $units unidad(es).";
            }
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $msg='Error al registrar entrada: '.$e->getMessage();
        }
    }else $msg='Producto y cantidad deben ser válidos.';
}

/* ============================================================
   4. SALIDA DE INVENTARIO
============================================================ */
if($action==='salida'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $product_id=(int)($_POST['product_id']??0);
    $qty=(int)($_POST['quantity']??0);
    $level=$_POST['unit_level']??'individual';
    $note=trim($_POST['note']??'');

    if($product_id>0&&$qty>0){
        try{
            $data=movementData($pdo,$product_id,$level,$qty);
            if(!$data){
                $msg='El producto no existe o está inactivo.';
            }else{
                $units=(int)$data['units'];
                $level=$data['level'];
                $pdo->beginTransaction();

                /* Bloqueamos la fila mientras comprobamos/descontamos stock. */
                $s=$pdo->prepare("
                    SELECT quantity FROM inventory
                    WHERE product_id=? AND branch_id=?
                    FOR UPDATE
                ");
                $s->execute([$product_id,$currentBranchId]);
                $stock=$s->fetchColumn();
                $available=$stock===false?0:(int)$stock;

                if($available<$units){
                    $pdo->rollBack();
                    $msg="Stock insuficiente. Hay $available unidad(es) y se necesitan $units.";
                }else{
                    $s=$pdo->prepare("
                        UPDATE inventory SET quantity=quantity-?
                        WHERE product_id=? AND branch_id=?
                    ");
                    $s->execute([$units,$product_id,$currentBranchId]);

                    $movementNote="Salida: $qty ".presentationName($level)." = $units unidad(es) mínima(s).";
                    if($note!=='')$movementNote.=" Nota: $note";

                    $m=$pdo->prepare("
                        INSERT INTO inventory_movements(product_id,branch_id,user_id,type,quantity,note)
                        VALUES(?,?,?,'salida',?,?)
                    ");
                    $m->execute([$product_id,$currentBranchId,$user_id,$units,$movementNote]);

                    $pdo->commit();
                    $remaining=$available-$units;
                    $msg="Salida registrada: $units unidad(es). Stock restante: $remaining.";
                }
            }
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $msg='Error al registrar salida: '.$e->getMessage();
        }
    }else $msg='Producto y cantidad deben ser válidos.';
}

/* ============================================================
   5. ACTUALIZACIÓN MASIVA
   La cantidad introducida aquí también son UNIDADES MÍNIMAS.
============================================================ */
if($action==='actualizarMasivo'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $quantities=$_POST['quantities']??[];

    if(!is_array($quantities)||!$quantities){
        $msg='No se recibieron cantidades para actualizar.';
    }else{
        try{
            $pdo->beginTransaction();

            $checkProduct=$pdo->prepare("SELECT id FROM products WHERE id=?");
            $check=$pdo->prepare("SELECT id,quantity FROM inventory WHERE product_id=? AND branch_id=?");
            $update=$pdo->prepare("UPDATE inventory SET quantity=? WHERE id=? AND branch_id=?");
            $insert=$pdo->prepare("INSERT INTO inventory(product_id,branch_id,quantity) VALUES(?,?,?)");
            $movement=$pdo->prepare("
                INSERT INTO inventory_movements(product_id,branch_id,user_id,type,quantity,note)
                VALUES(?,?,?,'ajuste',?,?)
            ");

            foreach($quantities as $productId=>$newQty){
                $productId=(int)$productId;
                $newQty=(int)$newQty;
                if($productId<=0||$newQty<0)continue;

                $checkProduct->execute([$productId]);
                if(!$checkProduct->fetchColumn())continue;

                $check->execute([$productId,$currentBranchId]);
                $row=$check->fetch(PDO::FETCH_ASSOC);

                if($row){
                    $oldQty=(int)$row['quantity'];
                    if($oldQty!==$newQty){
                        $update->execute([$newQty,$row['id'],$currentBranchId]);
                        $movement->execute([
                            $productId,$currentBranchId,$user_id,$newQty,
                            "Ajuste masivo: $oldQty -> $newQty unidades. Usuario ID: $user_id"
                        ]);
                    }
                }else{
                    $insert->execute([$productId,$currentBranchId,$newQty]);
                    $movement->execute([
                        $productId,$currentBranchId,$user_id,$newQty,
                        "Registro inicial: $newQty unidades. Usuario ID: $user_id"
                    ]);
                }
            }

            $pdo->commit();
            $msg='Inventario actualizado masivamente.';
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $msg='Error al actualizar inventario: '.$e->getMessage();
        }
    }
}

/* ============================================================
   6. BÚSQUEDA Y PAGINACIÓN
============================================================ */
$search=trim($_GET['search']??'');
$page=max(1,(int)($_GET['page']??1));
$perPage=50;
$offset=($page-1)*$perPage;

$where="WHERE p.active=1";
$paramsCount=[];
$paramsSelect=[$currentBranchId];

if($search!==''){
    $where.=" AND (p.name LIKE ? OR p.sku LIKE ?)";
    $paramsCount[]="%$search%";
    $paramsCount[]="%$search%";
    $paramsSelect[]="%$search%";
    $paramsSelect[]="%$search%";
}

$count=$pdo->prepare("SELECT COUNT(*) FROM products p $where");
$count->execute($paramsCount);
$total=(int)$count->fetchColumn();
$totalPages=max(1,(int)ceil($total/$perPage));

$sql="
SELECT
    p.id AS product_id,p.name AS product_name,p.sku,
    p.packaging_type,p.packaging_qty,p.has_inner_units,p.inner_unit_qty,
    COALESCE(i.id,0) AS inventory_id,
    COALESCE(i.quantity,0) AS quantity,
    COALESCE(i.min_quantity,0) AS min_quantity
FROM products p
LEFT JOIN inventory i ON i.product_id=p.id AND i.branch_id=?
$where
ORDER BY p.name
LIMIT ? OFFSET ?
";

$stmt=$pdo->prepare($sql);
$idx=1;
foreach($paramsSelect as $k=>$v){
    $stmt->bindValue($idx++,$v,$k===0?PDO::PARAM_INT:PDO::PARAM_STR);
}
$stmt->bindValue($idx++,$perPage,PDO::PARAM_INT);
$stmt->bindValue($idx++,$offset,PDO::PARAM_INT);
$stmt->execute();
$inventory=$stmt->fetchAll(PDO::FETCH_ASSOC);

function buildPageUrl(int $pageNumber):string{
    $params=$_GET;
    $params['page']=$pageNumber;
    return htmlspecialchars($_SERVER['PHP_SELF'].'?'.http_build_query($params),ENT_QUOTES,'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-between; align-items:center; }
    header h2 { margin:0; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; text-align:left; }
    .msg { background:#dff0d8; color:#3c763d; padding:10px; border-radius:6px; margin-bottom:10px; font-weight:bold; }
    .error { background:#f8d7da; color:#721c24; padding:10px; border-radius:6px; margin-bottom:10px; }
    form { margin-top:20px; background:#fafafa; padding:15px; border-radius:6px; }
    input, select, textarea { width:100%; padding:8px; margin-bottom:10px; box-sizing:border-box; }
    button { padding:8px 12px; background:#0a6; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    h3 { margin-top:30px; }
    .top-actions { display:flex; gap:10px; margin-bottom:10px; align-items:center; flex-wrap:wrap; }
    .top-actions button, .top-actions .small-btn { padding:8px 10px; }
    .small-btn { background:#eee; border:1px solid #ccc; cursor:pointer; border-radius:4px; text-decoration:none; color:#333; display:inline-block; }
    .editable-input { width:100%; box-sizing:border-box; padding:6px; }
    nav.pager { margin-top:12px; }
    nav.pager a { margin-right:8px; text-decoration:none; color:#0a6; }
    nav.pager a.current { font-weight:bold; color:#000; }
    .table-scroll { max-height:520px; overflow:auto; border:1px solid #eee; padding:6px; border-radius:6px; }
    .branch-selector { background:#fff; color:#333; font-weight:bold; padding:6px 12px; border-radius:4px; border:1px solid #ccc; width:auto; margin:0; }
</style>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet" />
</head>
<body>

<header>
    <h2>Inventario — Sucursal: <?= htmlspecialchars($currentBranchName) ?></h2>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="top-actions">
  <!-- Selector Dinámico de Sucursal -->
  <form method="GET" style="margin:0; background:none; padding:0; display:inline-flex; align-items:center; gap:5px;">
      <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
      <label style="font-weight:bold;">Sucursal:</label>
      <select name="branch_id" onchange="this.form.submit()" class="branch-selector">
          <?php foreach ($allBranches as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $b['id'] == $currentBranchId ? 'selected' : '' ?>>
                  <?= htmlspecialchars($b['name']) ?>
              </option>
          <?php endforeach; ?>
      </select>
  </form>

  <!-- Buscador en tiempo real por Servidor (GET) -->
  <form method="GET" style="margin:0; background:none; padding:0; flex:1; display:flex; gap:5px;">
      <input type="hidden" name="branch_id" value="<?= $currentBranchId ?>">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar producto o SKU en todo el inventario..." style="margin:0;">
      <button type="submit">Buscar</button>
      <?php if ($search !== ''): ?>
          <a href="/mi_tienda/admin/inventario.php?branch_id=<?= $currentBranchId ?>" class="small-btn">Limpiar</a>
      <?php endif; ?>
  </form>

  <button type="button" onclick="exportTableToCSV('inventario_<?= date('Ymd_His') ?>.csv')">Descargar Inventario</button>
</div>

<h3>Registrar Entrada de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=entrada&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>">

    <label>Producto</label>
    <select id="entrada-product" name="product_id" required>
    <option value="">Seleccione producto</option>
    <?php foreach($products as $p):?>
    <?php
    $pack=max(1,(int)($p['packaging_qty']??1));
    $has=!empty($p['has_inner_units']);
    $inner=$has?max(1,(int)($p['inner_unit_qty']??1)):1;
    ?>
    <option value="<?=(int)$p['id']?>"
    data-pack="<?=$pack?>"
    data-inner="<?=$inner?>"
    data-has-inner="<?=$has?1:0?>">
    <?=htmlspecialchars($p['name'])?> (<?=htmlspecialchars($p['sku'])?>)
    </option>
    <?php endforeach;?>
    </select>

    <label>Presentación</label>
    <select name="unit_level" id="entrada-level" required>
    <option value="package">Embalaje completo</option>
    <option value="inner">Paquete / contenedor</option>
    <option value="individual">Unidad individual</option>
    </select>

    <label>Cantidad</label>
    <input type="number" name="quantity" id="entrada-qty" min="1" placeholder="Cantidad" required>
    <div id="entrada-info" class="conversion-info" style="display:none"></div>

    <label>Nota</label>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>
    <button type="submit">Registrar Entrada</button>
</form>

<!-- ============================================================
    SALIDA
============================================================ -->
    <h3>Registrar Salida de Inventario</h3>
<form method="POST" action="/mi_tienda/admin/inventario.php?action=salida&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>">
    <label>Producto</label>
    <select id="salida-product" name="product_id" required>
    <option value="">Seleccione producto</option>
    <?php foreach($products as $p):?>
    <?php
    $pack=max(1,(int)($p['packaging_qty']??1));
    $has=!empty($p['has_inner_units']);
    $inner=$has?max(1,(int)($p['inner_unit_qty']??1)):1;
    ?>
    <option value="<?=(int)$p['id']?>"
    data-pack="<?=$pack?>"
    data-inner="<?=$inner?>"
    data-has-inner="<?=$has?1:0?>">
    <?=htmlspecialchars($p['name'])?> (<?=htmlspecialchars($p['sku'])?>)
    </option>
    <?php endforeach;?>
    </select>

    <label>Presentación</label>
    <select name="unit_level" id="salida-level" required>
    <option value="package">Embalaje completo</option>
    <option value="inner">Paquete / contenedor</option>
    <option value="individual">Unidad individual</option>
    </select>

    <label>Cantidad</label>
    <input type="number" name="quantity" id="salida-qty" min="1" placeholder="Cantidad" required>
    <div id="salida-info" class="conversion-info" style="display:none"></div>

    <label>Nota</label>
    <textarea name="note" placeholder="Nota (opcional)"></textarea>
    <button type="submit">Registrar Salida</button>
</form>

<!-- ============================================================
     INVENTARIO ACTUAL
============================================================ -->
<h3>Inventario Actual</h3>
    <p class="stock-detail"><strong>Cantidad = unidades mínimas.</strong> El desglose muestra automáticamente embalajes completos, paquetes y unidades sueltas.</p>

<form method="POST" action="/mi_tienda/admin/inventario.php?action=actualizarMasivo&branch_id=<?= $currentBranchId ?>&search=<?= urlencode($search) ?>" id="massUpdateForm">
    <div class="table-scroll">
        <table id="inventoryTable">
            <thead>
            <tr><th>Producto</th><th>SKU</th><th>Unidades totales</th><th>Desglose</th><th>Mínimo</th></tr>
            </thead>
            <tbody>

            <?php if(!$inventory):?>
            <tr><td colspan="5" style="text-align:center">No se encontraron productos.</td></tr>
            <?php else:?>

            <?php foreach($inventory as $i):?>
            <?php $d=stockBreakdown($i);$has=!empty($i['has_inner_units']);?>
            <tr>
            <td><?=htmlspecialchars($i['product_name'])?></td>
            <td><?=htmlspecialchars($i['sku'])?></td>
            <td>
            <input class="editable-input" type="number"
            name="quantities[<?=(int)$i['product_id']?>]"
            value="<?=(int)$i['quantity']?>" min="0" step="1">
            </td>

            <td class="stock-detail">
            <?php if($has):?>
            <strong><?=$d['boxes']?></strong> embalaje(s) +
            <strong><?=$d['packages']?></strong> paquete(s) +
            <strong><?=$d['units']?></strong> unidad(es)
            <br>1 embalaje = <?=$d['box_units']?> unidades
            <br>1 paquete = <?=$d['package_units']?> unidades
            <?php else:?>
            <strong><?=(int)$i['quantity']?></strong> unidad(es)
            <?php endif;?>
            </td>

            <td><?=(int)$i['min_quantity']?></td>
            </tr>
            <?php endforeach;?>

            <?php endif;?>
            </tbody>
        </table>
    </div>
        <div style="margin-top:12px"><button type="submit">Guardar cambios masivos</button></div>
</form>

<!-- ============================================================
     PAGINACIÓN
============================================================ -->
<?php if ($totalPages > 1): ?>
  <nav class="pager" aria-label="Paginación inventario">
    <?php if ($page > 1): ?>
      <a href="<?= buildPageUrl($page - 1) ?>" class="small-btn">« Anterior</a>
    <?php endif; ?>

    <?php
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
      if ($start > 1) {
          echo '<a href="' . buildPageUrl(1) . '">1</a>';
          if ($start > 2) echo ' ... ';
      }
      for ($p = $start; $p <= $end; $p++):
    ?>
      <a href="<?= buildPageUrl($p) ?>" class="<?= $p == $page ? 'current' : '' ?>"><?= $p ?></a>
    <?php endfor;
      if ($end < $totalPages) {
          if ($end < $totalPages - 1) echo ' ... ';
          echo '<a href="' . buildPageUrl($totalPages) . '">' . $totalPages . '</a>';
      }
    ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= buildPageUrl($page + 1) ?>" class="small-btn">Siguiente »</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>

<script>
$(function(){
    $('#entrada-product,#salida-product').select2({placeholder:'Buscar producto...',width:'100%'});

    $('#entrada-product,#entrada-level,#entrada-qty').on('change input',function(){
        updateConversion('entrada');
    });

    $('#salida-product,#salida-level,#salida-qty').on('change input',function(){
        updateConversion('salida');
    });

    $('#massUpdateForm').on('submit',function(e){
        if(!confirm('¿Guardar los cambios? Las cantidades representan unidades mínimas.'))e.preventDefault();
    });

    updatePresentationOptions('entrada');
    updatePresentationOptions('salida');

    $('#entrada-product').on('change',function(){updatePresentationOptions('entrada');});
    $('#salida-product').on('change',function(){updatePresentationOptions('salida');});
});

/* Oculta "paquete" si el producto no tiene unidades internas. */
function updatePresentationOptions(prefix){
    const product=document.getElementById(prefix+'-product');
    const level=document.getElementById(prefix+'-level');
    if(!product||!level)return;

    const option=product.options[product.selectedIndex];
    if(!option||!option.value)return;

    const hasInner=option.dataset.hasInner==='1';
    const innerOption=level.querySelector('option[value="inner"]');

    if(innerOption){
        innerOption.disabled=!hasInner;
        innerOption.style.display=hasInner?'block':'none';
    }

    if(!hasInner&&level.value==='inner')level.value='individual';
    updateConversion(prefix);
}

/* Vista previa: convierte caja/paquete/unidad a unidades mínimas. */
function updateConversion(prefix){
    const product=document.getElementById(prefix+'-product');
    const level=document.getElementById(prefix+'-level');
    const qty=document.getElementById(prefix+'-qty');
    const info=document.getElementById(prefix+'-info');
    if(!product||!level||!qty||!info)return;

    const option=product.options[product.selectedIndex];
    if(!option||!option.value){
        info.style.display='none';
        return;
    }

    const packs=Math.max(1,parseInt(option.dataset.pack||1,10));
    const inner=Math.max(1,parseInt(option.dataset.inner||1,10));
    const hasInner=option.dataset.hasInner==='1';
    const amount=Math.max(0,parseInt(qty.value||0,10));

    let factor=1;
    let name='unidad individual';

    if(level.value==='package'){
        factor=packs*inner;
        name='embalaje completo';
    }else if(level.value==='inner'){
        factor=inner;
        name='paquete';
    }

    const total=amount*factor;

    info.innerHTML=
        '<strong>Conversión:</strong> '+amount+' '+name+'(s) = <strong>'+total+' unidad(es)</strong>.'+
        (hasInner?'<br>1 paquete = '+inner+' unidades. 1 embalaje = '+(packs*inner)+' unidades.':'');

    info.style.display=amount>0?'block':'none';
}

/* Exportar inventario actual incluyendo el valor de los inputs. */
function exportTableToCSV(filename){
    const csv=[];
    document.querySelectorAll('#inventoryTable tr').forEach(row=>{
        const data=[];
        row.querySelectorAll('th,td').forEach(cell=>{
            const input=cell.querySelector('input');
            let text=input?input.value:cell.innerText;
            text=String(text).replace(/(\r\n|\n|\r)/gm,' ').replace(/,/g,'').trim();
            data.push('"'+text.replace(/"/g,'""')+'"');
        });
        csv.push(data.join(','));
    });

    const blob=new Blob([csv.join('\n')],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download=filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>
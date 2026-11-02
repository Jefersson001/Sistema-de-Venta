<?php
declare(strict_types=1);
require_once __DIR__.'/inventory-controller.php';

function renderInventoryLots(): void
{
    $page=['area'=>'lots','available'=>true,'errors'=>[],'flash'=>$_SESSION['inventory_flash']??null,'csrf'=>$_SESSION['auth_csrf'],'record'=>[],
        'view'=>isset($_GET['initialize'])?'initialize':((isset($_GET['id'])||isset($_GET['new']))?'form':'list')];
    unset($_SESSION['inventory_flash']);
    try{
        $db=createDatabaseConnection();$id=inventoryId($_GET['id']??null);
        $page['products']=$db->query("SELECT id,name,tracking_mode,expiration_enabled FROM inventory_products WHERE product_type='goods' AND tracking_mode<>'none' ORDER BY name,id")->fetchAll();
        $page['locations']=$db->query("SELECT l.id,l.name,w.name AS warehouse_name FROM inventory_locations l JOIN inventory_warehouses w ON w.id=l.warehouse_id WHERE l.location_type='internal' ORDER BY w.name,l.name")->fetchAll();
        if(isset($_GET['id'])){
            $q=$db->prepare('SELECT t.*,p.name AS product_name FROM inventory_lots t JOIN inventory_products p ON p.id=t.product_id WHERE t.id=?');$q->execute([$id]);$page['record']=$q->fetch()?:[];
            if(!$page['record']){http_response_code(404);$page['available']=false;$page['errors'][]='El lote o serie solicitado no existe.';}
        }
        if($_SERVER['REQUEST_METHOD']==='POST'&&$page['available']){
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='Recarga la página para renovar la sesión.';}
            else try{
                if(($_POST['action']??'')==='initialize_tracking'&&$page['view']==='initialize'){
                    trackingInitialize($db,inventoryId($_POST['product_id']??null)??0,inventoryId($_POST['location_id']??null)??0,$_POST['tracking']??[]);
                    $_SESSION['inventory_flash']='Existencias identificadas. Las cantidades generales se conservaron.';
                }elseif(($_POST['action']??'')==='save_lot'&&$page['view']==='form'){
                    $product=$id?(int)$page['record']['product_id']:(inventoryId($_POST['product_id']??null)??0);
                    $db->beginTransaction();
                    try{
                        $p=trackingProduct($db,$product,true);if($p['tracking_mode']==='none')throw new InvalidArgumentException('Selecciona un producto con seguimiento.');
                        $expiration=trackingDate($_POST['expiration_date']??null);
                        if($id){
                            $q=$db->prepare('SELECT version FROM inventory_lots WHERE id=? FOR UPDATE');$q->execute([$id]);
                            if((int)$q->fetchColumn()!==inventoryId($_POST['version']??null))throw new InvalidArgumentException('La ficha cambió en otra ventana. Recarga antes de guardar.');
                            if($p['expiration_enabled']&&!$expiration)throw new InvalidArgumentException('Este producto requiere vencimiento.');
                            $dates=[];foreach(['alert_days','removal_days','best_before_days'] as $key){$d=$expiration?(new DateTimeImmutable($expiration))->modify('-'.(int)$p[$key].' days')->format('Y-m-d'):null;$dates[]=trackingDate($d);}
                            $db->prepare('UPDATE inventory_lots SET expiration_date=?,alert_date=?,removal_date=?,best_before_date=?,version=version+1 WHERE id=?')->execute([$expiration,...$dates,$id]);
                        }else{
                            $name=is_string($_POST['name']??null)?trim($_POST['name']):'';
                            $q=$db->prepare('SELECT id FROM inventory_lots WHERE product_id=? AND name=?');$q->execute([$product,$name]);if($q->fetchColumn())throw new InvalidArgumentException('Ese identificador ya existe para este producto.');
                            $lot=trackingLot($db,$p,['name'=>$name,'expiration_date'=>$expiration],true);$id=(int)$lot['id'];
                        }
                        $db->commit();
                    }catch(Throwable $e){$db->rollBack();throw $e;}
                    $_SESSION['inventory_flash']='Lote o serie guardado. Crear la ficha no modifica existencias.';
                }else throw new InvalidArgumentException('Acción no válida.');
                header('Location: lotes.php'.($id?'?id='.$id:''),true,303);exit;
            }catch(InvalidArgumentException $e){$page['errors'][]=$e->getMessage();}
        }
        if($page['view']==='initialize'){
            $page['pending']=$db->query("SELECT s.product_id,s.location_id,s.quantity-COALESCE((SELECT SUM(t.quantity) FROM inventory_lot_stock t WHERE t.product_id=s.product_id AND t.location_id=s.location_id),0) AS pending,p.name,l.name AS location_name FROM inventory_stock s JOIN inventory_products p ON p.id=s.product_id JOIN inventory_locations l ON l.id=s.location_id WHERE p.tracking_mode<>'none' AND l.location_type='internal' HAVING pending>0 ORDER BY p.name,l.name")->fetchAll();
        }elseif($page['view']==='form'&&$id&&$page['available']){
            $q=$db->prepare("SELECT s.*,l.name AS location_name,l.location_type FROM inventory_lot_stock s JOIN inventory_locations l ON l.id=s.location_id WHERE s.lot_id=? AND l.location_type='internal' ORDER BY l.name");$q->execute([$id]);$page['balances']=$q->fetchAll();
            foreach($page['balances'] as &$balance){$balance['reserved']=trackingReserved($db,$id,(int)$balance['location_id']);$balance['available']=bcsub($balance['quantity'],$balance['reserved'],2);}unset($balance);
            $where=[];foreach(['receipt','delivery','internal'] as $type)$where[]="(o.operation_type='$type' AND ".permissionWhere(permissionOperationResource($type),'o').')';
            $q=$db->prepare("SELECT m.*,l.name AS location_name,o.name AS operation_name,o.operation_type,c.name AS contact_name FROM inventory_lot_moves m JOIN inventory_locations l ON l.id=m.location_id LEFT JOIN inventory_operations o ON o.id=m.operation_id LEFT JOIN contacts c ON c.id=o.contact_id WHERE m.lot_id=? AND (m.reason='initial' OR (".implode(' OR ',$where).")) ORDER BY m.id DESC LIMIT 200");$q->execute([$id]);$page['moves']=$q->fetchAll();
        }elseif($page['view']==='list'){
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';$page['productId']=inventoryId($_GET['product_id']??null);$page['locationId']=inventoryId($_GET['location_id']??null);
            $page['mode']=in_array($_GET['mode']??'',['lot','serial'],true)?$_GET['mode']:'';
            $page['expiry']=in_array($_GET['expiry']??'',['expired','alert'],true)?$_GET['expiry']:'';
            $where=' WHERE 1=1';$params=[];
            if($page['search']!==''){$where.=' AND (t.name LIKE ? OR p.name LIKE ?)';$params[]='%'.$page['search'].'%';$params[]='%'.$page['search'].'%';}
            if($page['productId']){$where.=' AND t.product_id=?';$params[]=$page['productId'];}
            if($page['mode']){$where.=' AND t.tracking_mode=?';$params[]=$page['mode'];}
            if($page['locationId']){$where.=' AND EXISTS(SELECT 1 FROM inventory_lot_stock ls WHERE ls.lot_id=t.id AND ls.location_id=? AND ls.quantity>0)';$params[]=$page['locationId'];}
            if($page['expiry']){$where.=$page['expiry']==='expired'?' AND t.expiration_date<?':' AND COALESCE(t.alert_date,t.expiration_date)<=?';$params[]=trackingToday();}
            $from=' FROM inventory_lots t JOIN inventory_products p ON p.id=t.product_id';
            $q=$db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($params);$page['total']=(int)$q->fetchColumn();$page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(inventoryId($_GET['page']??null)??1,$page['pages']);
            $q=$db->prepare("SELECT t.*,p.name AS product_name,COALESCE((SELECT SUM(s.quantity) FROM inventory_lot_stock s JOIN inventory_locations loc ON loc.id=s.location_id WHERE s.lot_id=t.id AND loc.location_type='internal'),0) AS quantity".$from.$where.' ORDER BY t.id DESC LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));$q->execute($params);$page['lots']=$q->fetchAll();
        }
    }catch(PDOException $e){error_log('Seguimiento: '.$e->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudo cargar el seguimiento. Verifica su instalación.';}
    renderModulePage('inventario',false,$page);
}

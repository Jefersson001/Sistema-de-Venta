<?php
declare(strict_types=1);
require_once __DIR__.'/restaurant-repository.php';
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/restaurant-pos.php';

function restaurantEscape(mixed $value): string { return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function restaurantUrl(string $file,array $query=[]): string { return $file.($query?'?'.http_build_query($query):''); }
function restaurantRows(PDO $db,string $sql,array $parameters=[]): array
{ $q=$db->prepare($sql);$q->execute($parameters);return $q->fetchAll(); }
function restaurantMapRows(PDO $db): array
{
    $rows=restaurantRows($db,"SELECT t.*, ar.name AS area_name, ar.sort_order, x.table_id AS occupied,
        a.id AS account_id,a.name AS account_name,a.opened_at,u.name AS waiter_name
        FROM restaurant_tables t JOIN restaurant_areas ar ON ar.id=t.area_id
        LEFT JOIN restaurant_active_tables x ON x.table_id=t.id
        LEFT JOIN restaurant_accounts a ON a.id=x.account_id AND ".permissionWhere('restaurante.accounts','a')."
        LEFT JOIN auth_users u ON u.id=a.waiter_id
        WHERE t.company_id=? AND t.active=1 AND ar.active=1 ORDER BY ar.sort_order,ar.name,t.position_y,t.position_x,t.name",[companyActiveId()]);
    if($db->query("SHOW TABLES LIKE 'restaurant_account_carts'")->fetch())foreach($rows as &$row)if($row['account_id']){
        $cart=restaurantCart($db,(int)$row['account_id']);$row['consumption_total']=$cart['total'].' '.$cart['currency'];
    }unset($row);
    return $rows;
}
function renderRestaurant(string $view): void
{
    try {renderRestaurantPage($view);}
    catch(InvalidArgumentException $e){authStop(in_array($e->getCode(),[403,404,409],true)?$e->getCode():422,$e->getMessage());}
    catch(Throwable $e){error_log('Restaurante: '.$e->getMessage());authStop(503,'No se pudo cargar el restaurante. Intenta nuevamente.',str_contains($_SERVER['HTTP_ACCEPT']??'','application/json'));}
}
function renderRestaurantPage(string $view): void
{
    $page=['area'=>match($view){'configuration'=>'configuration','account','accounts'=>'accounts',default=>'map'},
        'view'=>$view,'error'=>null,'notice'=>$_SESSION['restaurant_notice']??null,'installed'=>false];
    unset($_SESSION['restaurant_notice']);
    $db=createDatabaseConnection();
    $page['installed']=(bool)$db->query("SHOW TABLES LIKE 'restaurant_requests'")->fetch();
    if(!$page['installed']){renderModulePage('restaurante',$view==='configuration',$page);return;}
    $id=isset($_GET['id'])?restaurantInt($_GET['id']):null;
    if(in_array($view,['account','table'],true)&&!$id&&!isset($_GET['new']))throw new InvalidArgumentException('Selecciona una mesa o una cuenta.',404);
    $section=($_GET['section']??'restaurant_areas')==='restaurant_tables'?'restaurant_tables':'restaurant_areas';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try {
            $action=$_POST['action']??'';
            if($view==='account' && $id && in_array($action,['pos_add','pos_quantity','pos_remove','pos_note','pos_checkout'],true)){
                if($action==='pos_checkout')restaurantCheckout($db,$id,$_POST);else restaurantCartCommand($db,$id,$_POST);
                if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){
                    $cart=restaurantCart($db,$id);$h='restaurantEscape';ob_start();require dirname(__DIR__).'/html/restaurant/cart.php';$html=ob_get_clean();
                    header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'html'=>$html,'version'=>$cart['account']['version'],'closed'=>$cart['account']['status']==='closed'],JSON_THROW_ON_ERROR);return;
                }
                $destination=restaurantUrl('cuenta.php',['id'=>$id]);$message=$action==='pos_checkout'?'Factura emitida y cobro registrado.':'Pedido guardado.';
            }elseif($view==='configuration'){
                if($action==='setup_tables'){
                    restaurantQuickSetup($db,$_POST);$destination='index.php';$message='Mesas creadas. Toca una para agregar productos.';
                }else{
                    if($action!==($section==='restaurant_areas'?'save_restaurant_area':'save_restaurant_table'))throw new InvalidArgumentException('Acción no válida.');
                    if($section==='restaurant_areas')restaurantSaveArea($db,$_POST,$id);else restaurantSaveTable($db,$_POST,$id);
                    $destination=restaurantUrl('configuracion.php',['section'=>$section]);$message='Configuración guardada.';
                }
            }elseif($view==='account' && isset($_GET['new'])){
                if($action!=='save_account')throw new InvalidArgumentException('Acción no válida.');
                $account=restaurantOpenAccount($db,$_POST);$destination=restaurantUrl('cuenta.php',['id'=>$account]);$message='Cuenta abierta. La mesa ya está asignada.';
            }elseif($view==='account' && $id){
                restaurantAccountAction($db,$id,$_POST);$destination=restaurantUrl('cuenta.php',['id'=>$id]);$message='Cuenta actualizada.';
            }elseif($view==='table' && $id && $action==='change_table_service'){
                restaurantChangeService($db,$id,$_POST);$destination=restaurantUrl('mesa.php',['id'=>$id]);$message='Estado de la mesa actualizado.';
            }else throw new InvalidArgumentException('Acción no válida.');
            $_SESSION['restaurant_notice']=$message;header('Location: '.$destination,true,303);exit;
        }catch(InvalidArgumentException $e){
            $status=in_array($e->getCode(),[403,404,409],true)?$e->getCode():422;http_response_code($status);$page['error']=$e->getMessage();
        }catch(PDOException $e){
            if(($e->errorInfo[1]??null)===1062){http_response_code(409);$page['error']='Ese nombre, posición o mesa ya está en uso. Recarga antes de continuar.';}
            else {error_log('Restaurante: '.$e->getMessage());http_response_code(503);$page['error']='No se pudo guardar. Recarga para comprobar el resultado antes de reintentar.';}
        }
        if($page['error'] && str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){
            header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$page['error']],JSON_THROW_ON_ERROR);return;
        }
    }
    try {
        $page['areas']=restaurantRows($db,'SELECT * FROM restaurant_areas WHERE company_id=? ORDER BY sort_order,name,id',[companyActiveId()]);
        if($view==='configuration'){
            $page['section']=$section;
            $page['editing']=$id!==null||isset($_GET['new']);
            $page['record']=$id?restaurantRecord($db,$section,$id):null;
            $page['tables']=restaurantRows($db,'SELECT t.*,a.name AS area_name FROM restaurant_tables t JOIN restaurant_areas a ON a.id=t.area_id WHERE t.company_id=? ORDER BY a.sort_order,a.name,t.name',[companyActiveId()]);
        }elseif($view==='account' && isset($_GET['new'])){
            permissionRequire('restaurante.tables','read');
            $page['table']=restaurantRecord($db,'restaurant_tables',restaurantInt($_GET['table_id']??null));
            $tableArea=restaurantRecord($db,'restaurant_areas',(int)$page['table']['area_id']);
            $occupied=restaurantRows($db,'SELECT table_id FROM restaurant_active_tables WHERE table_id=?',[(int)$page['table']['id']]);
            if($occupied||$page['table']['service_state']!=='free'||!$page['table']['active']||!$tableArea['active']) {
                http_response_code(409);$page['error']='La mesa ya no está libre para abrir una cuenta. Vuelve al mapa para consultar su estado.';$page['view']='error';
            }
            $page['waiters']=restaurantWaiters($db);
            if(permissionScope('restaurante.accounts','create')==='own')$page['waiters']=array_values(array_filter($page['waiters'],static fn($u)=>(int)$u['id']===(int)permissionUser()['id']));
        }elseif($view==='account' && $id){
            $page['record']=restaurantRecord($db,'restaurant_accounts',$id);
            $q=$db->prepare('SELECT name FROM auth_users WHERE id=?');$q->execute([$page['record']['waiter_id']]);$page['waiter']=$q->fetchColumn();
            $page['assignments']=restaurantRows($db,'SELECT s.*,t.name AS table_name,ar.name AS area_name FROM restaurant_table_assignments s JOIN restaurant_tables t ON t.id=s.table_id JOIN restaurant_areas ar ON ar.id=t.area_id WHERE s.account_id=? ORDER BY s.id DESC',[$id]);
            $page['events']=restaurantRows($db,'SELECT e.*,u.name AS actor_name FROM restaurant_audit_events e LEFT JOIN auth_users u ON u.id=e.created_by WHERE e.account_id=? ORDER BY e.id DESC LIMIT 100',[$id]);
            $page['freeTables']=permissionHas('restaurante.tables')?array_values(array_filter(restaurantMapRows($db),static fn($t)=>!$t['occupied']&&$t['service_state']==='free')):[];
            $page['posReady']=(bool)$db->query("SHOW TABLES LIKE 'restaurant_account_items'")->fetch();
            if($page['posReady']){
                $page['cart']=restaurantCart($db,$id);
                $pair=$page['cart']['header']?:accountingCurrencies($db);$currency=$page['cart']['currency'];
                $page['products']=restaurantRows($db,'SELECT p.id,p.name,p.sale_price,p.currency_code,p.category_id,c.name AS category_name FROM inventory_products p LEFT JOIN inventory_categories c ON c.id=p.category_id WHERE p.sale_enabled=1 AND p.company_id=? ORDER BY p.name',[companyActiveId()]);
                $page['productCategories']=restaurantRows($db,'SELECT id,name,parent_id,restaurant_enabled FROM inventory_categories WHERE company_id=? ORDER BY name,id',[companyActiveId()]);
                $page['restaurantCategoryIds']=inventoryRestaurantCategoryIds($page['productCategories']);
                $page['productCategories']=array_values(array_filter($page['productCategories'],static fn($category)=>in_array((int)$category['id'],$page['restaurantCategoryIds'],true)));
                $page['products']=array_values(array_filter($page['products'],static fn($product)=>in_array((int)$product['category_id'],$page['restaurantCategoryIds'],true)));
                foreach($page['products'] as &$product){try{$product['display_price']=accountingConvert($product['sale_price'],$product['currency_code'],$currency,$pair);$product['available']=true;}catch(InvalidArgumentException){$product['display_price']=null;$product['available']=false;}}unset($product);
                if(permissionRecord('restaurante.accounts',$id,'confirm') && permissionRecord('restaurante.accounts',$id,'pay')){
                    $page['clients']=restaurantRows($db,"SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.active=1 AND t.system_key='client' ORDER BY c.name");
                    $page['locations']=restaurantRows($db,"SELECT id,name FROM inventory_locations WHERE location_type='internal' ORDER BY name");
                    $page['cashAccounts']=restaurantRows($db,'SELECT id,name FROM accounting_payment_accounts WHERE currency_code IS NULL OR currency_code=? ORDER BY name',[$currency]);
                    $page['methods']=restaurantRows($db,'SELECT id,name FROM accounting_payment_methods ORDER BY name');
                }
            }
        }elseif($view==='table' && $id){
            $page['table']=restaurantRecord($db,'restaurant_tables',$id);
            $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$id]);$page['occupied']=$q->fetchColumn();
        }elseif($view==='accounts'){
            $page['accounts']=restaurantRows($db,'SELECT a.*,u.name AS waiter_name FROM restaurant_accounts a JOIN auth_users u ON u.id=a.waiter_id WHERE a.company_id=? AND '.permissionWhere('restaurante.accounts','a').' ORDER BY a.id DESC LIMIT 200',[companyActiveId()]);
        }else $page['tables']=restaurantMapRows($db);
    }catch(InvalidArgumentException $e){http_response_code(404);$page['error']=$e->getMessage();$page['view']='error';}
    renderModulePage('restaurante',$view==='configuration',$page);
}

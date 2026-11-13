<?php
declare(strict_types=1);
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/direct-sales-operations.php';

function renderDirectSales(bool $requests=false,bool $warehouseStock=false): void {
    authPrivacyHeaders();$db=createDatabaseConnection();
    $resource=$warehouseStock?'venta_directa.stock':($requests?'venta_directa.requests':'venta_directa.sales');
    if(!$requests&&!$warehouseStock&&isset($_GET['credit_customer_id'])){
        $customer=accessId($_GET['credit_customer_id']);$q=$db->prepare("SELECT c.id FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=? AND c.company_id=? AND t.system_key='client' AND ".permissionWhere('contactos.contacts','c'));$q->execute([$customer,companyActiveId()]);if(!$q->fetchColumn())authStop(403,'Cliente no autorizado.');
        header('Content-Type: application/json; charset=utf-8');echo json_encode(directCredit($db,(int)$customer),JSON_THROW_ON_ERROR);return;
    }
    if(!$requests&&!$warehouseStock&&!isset($_GET['id'])&&!isset($_GET['view'])&&$_SERVER['REQUEST_METHOD']==='GET'&&(!permissionHas('venta_directa.sales','create')||!permissionContact())){
        header('Location: '.(permissionHas('venta_directa.stock')?'inventario.php':(permissionHas('venta_directa.requests')?'solicitudes.php':'index.php?view=history')),true,303);return;
    }
    $page=['requests'=>$requests,'errors'=>[],'csrf'=>$_SESSION['auth_csrf'],'key'=>bin2hex(random_bytes(32)),
        'seller'=>null,'sellers'=>[],'products'=>[],'customers'=>[],'terms'=>[],'rows'=>[],'detail'=>null,'lines'=>[],
        'locations'=>[],'input'=>[],'can_create'=>permissionHas($resource,'create')&&permissionContact()>0,
        'warehouse_stock'=>$warehouseStock,'supply_rows'=>[],'source'=>null,'source_stock'=>[]];
    $id=accessId($_GET['id']??null);
    if(isset($_GET['id'])&&!$id)authStop(404,'El registro no está disponible.');
    if(isset($_GET['view'])&&(!is_string($_GET['view'])||!in_array($_GET['view'],['stock','history'],true)))authStop(404,'La vista no está disponible.');
    try {
        if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $input=$_POST;$page['input']=array_filter($input,'is_string');
            if(isset($input['approved'])&&is_array($input['approved']))$page['input']['approved']=$input['approved'];
            if(isset($input['lines_json'])){
                if(!is_string($input['lines_json'])||strlen($input['lines_json'])>30000||isset($input['lines']))throw new InvalidArgumentException('El carrito no es válido.');
                try{$input['lines']=json_decode($input['lines_json'],true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('No se pudo leer el carrito.');}
                $page['input']['lines']=$input['lines'];
            }
            if(($input['action']??'')==='direct_checkout'&&!$requests){$id=directCheckout($db,$input);header('Location: index.php?id='.$id,true,303);return;}
            if(($input['action']??'')==='direct_request'&&$requests){$id=directCreateRequest($db,$input);header('Location: solicitudes.php?id='.$id,true,303);return;}
            if(($input['action']??'')==='direct_payment'&&!$requests&&$id){directRegisterPayment($db,$id,$input,$_FILES);header('Location: index.php?id='.$id.'&payment_registered=1',true,303);return;}
            if(($input['action']??'')==='direct_dispatch'&&$requests&&$id){directDispatchRequest($db,$id,$input);header('Location: solicitudes.php?id='.$id,true,303);return;}
            if($requests&&$id&&in_array($input['action']??'',['direct_approve','direct_cancel'],true)){
                directDecideRequest($db,$id,$input,$input['action']==='direct_approve');header('Location: solicitudes.php?id='.$id,true,303);return;
            }
            throw new InvalidArgumentException('Acción no válida.');
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,409],true)?$e->getCode():422);$page['errors'][]=$e->getMessage();}
    catch(Throwable $e){error_log('Venta directa: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo completar la operación. Puedes reintentar con el mismo carrito; no se duplicará una venta ya confirmada.';}
    try {
        $page['settings']=accountingCurrencies($db);
        if(!$warehouseStock&&$page['can_create']&&($_GET['view']??'')!=='history'&&!$id){
        $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id
            WHERE c.company_id=? AND c.active=1 AND t.system_key='salesperson' AND c.id IN (".implode(',',permissionContacts()?:[0]).") ORDER BY c.name");
        $q->execute([companyActiveId()]);$page['sellers']=$q->fetchAll();
        $seller=accessId($page['input']['seller_id']??$_GET['seller_id']??null)?:permissionContact();
        if($seller){try{$page['seller']=directSeller($db,(string)$seller,$resource,'create');}catch(InvalidArgumentException $e){$page['errors'][]=$e->getMessage();}}
        if($page['seller'])$page['products']=directProducts($db,(int)$page['seller']['location_id'],$requests);
        }
        if(!$requests&&!$warehouseStock){
            $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.company_id=? AND c.active=1 AND t.system_key='client' AND ".permissionWhere('contactos.contacts','c')." ORDER BY c.name");
            $q->execute([companyActiveId()]);$page['customers']=$q->fetchAll();
            $q=$db->prepare('SELECT id,name,days FROM accounting_payment_terms WHERE company_id=? ORDER BY days,name');$q->execute([companyActiveId()]);$page['terms']=$q->fetchAll();
            $q=$db->query('SELECT d.id,d.request_key,d.payment_mode,d.seller_id,d.sale_id,d.operation_id,d.invoice_id,d.created_at,i.status AS invoice_status,i.name,i.total_amount,i.currency_code,i.due_date,i.payment_state,c.name AS customer_name,s.name AS seller_name
                FROM direct_sale_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id JOIN contacts c ON c.id=i.contact_id JOIN contacts s ON s.id=d.seller_id
                WHERE '.permissionWhere($resource,'d').' ORDER BY d.id DESC LIMIT 100');$page['rows']=$q->fetchAll();
            if($id){
                $q=$db->prepare('SELECT d.*,i.status AS invoice_status,i.name,i.invoice_date,i.due_date,i.currency_code,i.total_amount,i.payment_state,c.name AS customer_name,s.name AS seller_name FROM direct_sale_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id JOIN contacts c ON c.id=i.contact_id JOIN contacts s ON s.id=d.seller_id WHERE d.id=? AND '.permissionWhere($resource,'d'));
                $q->execute([$id]);$page['detail']=$q->fetch();
                if(!$page['detail'])authStop(404,'La venta no está disponible.');
                $q=$db->prepare('SELECT id,product_name,quantity,unit_price,total_amount FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY position,id');$q->execute([$page['detail']['invoice_id']]);$page['lines']=$q->fetchAll();
                $page['sale_record']=directSaleRecord($db,$id);$page['payment_details']=invoicePaymentDetails($db,(int)$page['detail']['invoice_id']);
                $page['accounts']=$db->query('SELECT id,name,currency_code FROM accounting_payment_accounts WHERE company_id='.(int)companyActiveId().' ORDER BY name')->fetchAll();
                $page['methods']=$db->query('SELECT id,name FROM accounting_payment_methods WHERE company_id='.(int)companyActiveId().' ORDER BY name')->fetchAll();
            }
        }elseif($requests){
            $q=$db->query('SELECT r.*,c.name AS seller_name FROM direct_stock_requests r JOIN contacts c ON c.id=r.seller_id WHERE '.permissionWhere($resource,'r').' ORDER BY r.id DESC LIMIT 100');$page['rows']=$q->fetchAll();
            if($id){
                $q=$db->prepare('SELECT r.*,c.name AS seller_name,l.name AS destination_name FROM direct_stock_requests r JOIN contacts c ON c.id=r.seller_id JOIN inventory_locations l ON l.id=r.destination_location_id WHERE r.id=? AND '.permissionWhere($resource,'r'));$q->execute([$id]);$page['detail']=$q->fetch();
                if(!$page['detail'])authStop(404,'La solicitud no está disponible.');
                $q=$db->prepare('SELECT r.*,p.name AS product_name FROM direct_stock_request_lines r JOIN inventory_products p ON p.id=r.product_id WHERE r.request_id=? ORDER BY r.id');$q->execute([$id]);$page['lines']=$q->fetchAll();
            }
        }
        if($warehouseStock||($requests&&permissionScope($resource,'confirm')==='all'&&permissionHas('venta_directa.stock'))){
            $page['locations']=directCentralLocations($db);
            $selected=accessId($page['input']['source_location_id']??$_GET['source_location_id']??(($page['detail']['status']??'')==='approved'?$page['detail']['source_location_id']:null));
            if($selected){foreach($page['locations'] as $location)if((int)$location['id']===$selected)$page['source']=$location;
                if(!$page['source']){
                    if(($page['detail']['status']??'')==='approved')$page['errors'][]='El almacén aprobado ya no está disponible. Cancela la solicitud y genera otra.';
                    else authStop(403,'El origen no está disponible para abastecimiento en esta empresa.');
                }
            }else{$page['source']=$page['locations'][0]??null;}
            if($page['source']){
                $page['supply_rows']=directSupplyStock($db,(int)$page['source']['id']);
                foreach($page['supply_rows'] as $row)$page['source_stock'][(int)$row['id']]=$row['available'];
                if(($page['detail']['request_kind']??'')==='return'){
                    $page['source_stock']=[];foreach(directSupplyStock($db,(int)$page['detail']['seller_location_id']) as $row)$page['source_stock'][(int)$row['id']]=$row['available'];
                }
            }
        }
    }catch(Throwable $e){error_log('Catálogo de venta directa: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo cargar el módulo. Comprueba que las migraciones de Ventas Directas estén aplicadas.';$page['settings']??=['primary_code'=>''];}
    $databaseStatus=getDatabaseStatus();$moduleKey='venta_directa';$homeHref='../../index.php';$isHomePage=false;
    ob_start();require dirname(__DIR__).'/html/direct-sales/page.php';echo permissionRenderHtml(ob_get_clean());
}

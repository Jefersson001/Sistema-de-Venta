<?php
declare(strict_types=1);
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/direct-purchases-repository.php';

function renderDirectPurchases(): void {
    authPrivacyHeaders();$db=createDatabaseConnection();$id=accessId($_GET['id']??null);
    if(isset($_GET['id'])&&!$id)authStop(404,'Compra no disponible.');
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
    $page=['errors'=>[],'input'=>[],'key'=>bin2hex(random_bytes(32)),'detail'=>null,'rows'=>[],'lines'=>[],'catalogs'=>[]];
    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $page['input']=array_filter($_POST,'is_string');
            if(($_POST['action']??'')!=='direct_purchase')throw new InvalidArgumentException('Acción no válida.');
            if(!is_string($_POST['lines_json']??null)||strlen($_POST['lines_json'])>200000)throw new InvalidArgumentException('El carrito no es válido.');
            $input=$_POST;
            try{$input['lines']=json_decode($input['lines_json'],true,12,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('No se pudo leer el carrito.');}
            $id=directPurchaseCheckout($db,$input);header('Location: index.php?id='.$id,true,303);return;
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,409],true)?$e->getCode():422);$page['errors'][]=$e->getMessage();}
    catch(Throwable $e){error_log('Compra directa: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo completar la compra. Reintenta con el mismo carrito para evitar duplicados.';}
    $q=$db->prepare('SELECT d.*,p.name,p.currency_code,p.total_amount,p.order_date,c.name AS supplier_name,c.phone AS supplier_phone,i.payment_state FROM direct_purchase_checkouts d JOIN purchase_orders p ON p.id=d.purchase_id JOIN contacts c ON c.id=p.supplier_id JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.company_id=? AND '.permissionWhere('compra_directa.purchases','d').' ORDER BY d.id DESC LIMIT 100');
    $q->execute([companyActiveId()]);$page['rows']=$q->fetchAll();
    if($id){
        permissionRequire('compra_directa.purchases','read',$id);
        $q=$db->prepare('SELECT d.*,p.name,p.currency_code,p.total_amount,p.order_date,c.name AS supplier_name,c.phone AS supplier_phone,i.name AS invoice_name,i.due_date,i.payment_state FROM direct_purchase_checkouts d JOIN purchase_orders p ON p.id=d.purchase_id JOIN contacts c ON c.id=p.supplier_id JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.id=? AND d.company_id=?');$q->execute([$id,companyActiveId()]);$page['detail']=$q->fetch();if(!$page['detail'])authStop(404,'Compra no disponible.');
        $page['lines']=loadPurchaseOrder($db,(int)$page['detail']['purchase_id'])['lines'];
    }elseif(($_GET['view']??'')!=='history'){$page['catalogs']=directPurchaseCatalogs($db);}
    $modules=require __DIR__.'/config/modules.php';$module=$modules['compra_directa'];$moduleKey='compra_directa';$databaseStatus=getDatabaseStatus();
    ob_start();require dirname(__DIR__).'/html/direct-purchases/page.php';echo permissionRenderHtml(ob_get_clean());
}

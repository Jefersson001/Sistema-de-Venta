<?php
declare(strict_types=1);
require_once __DIR__.'/php/auth.php';
require_once __DIR__.'/php/permissions.php';
$GLOBALS['permission_runtime']=true;$GLOBALS['permission_user']=authRequireLogin(true);
header('Content-Type: application/json; charset=UTF-8');
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'Solo se admite consulta.',true);}
try{
    $kind=$_GET['kind']??null;$type=$_GET['type']??'customer';$id=accessId($_GET['id']??null);
    if(!$id||!is_string($kind)||!in_array($kind,['order','invoice'],true)||!is_string($type)||!in_array($type,['customer','supplier'],true))authStop(404,'Documento no disponible.',true);
    if(isset($_GET['scope'])&&(!is_string($_GET['scope'])||!hash_equals(companyScopeToken(),$_GET['scope'])))authStop(409,'La selección de empresas cambió. Recarga la lista.',true);
    $resource=$kind==='order'?'ventas.orders':permissionInvoiceResource($type);permissionRequire($resource,'read',$id,true);
    $table=$kind==='order'?'sales_quotations':'accounting_invoices';$lineTable=$kind==='order'?'sales_quotation_lines':'accounting_invoice_lines';$fk=$kind==='order'?'quotation_id':'invoice_id';
    $db=createDatabaseConnection();$q=$db->prepare('SELECT d.id,d.name FROM '.$table.' d WHERE d.id=? AND '.permissionWhere($resource,'d').($kind==='invoice'?' AND d.invoice_type=?':''));$q->execute($kind==='invoice'?[$id,$type]:[$id]);$record=$q->fetch();
    if(!$record)authStop(404,'Documento no disponible.',true);
    $q=$db->prepare('SELECT product_name AS name,quantity FROM '.$lineTable.' WHERE '.$fk.'=? ORDER BY position,id');$q->execute([$id]);
    echo json_encode(['name'=>$record['name'],'products'=>$q->fetchAll(),'scope'=>companyScopeToken()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){error_log('Consulta de productos del documento: '.$e->getMessage());authStop(503,'No se pudieron consultar los productos. Intenta nuevamente.',true);}

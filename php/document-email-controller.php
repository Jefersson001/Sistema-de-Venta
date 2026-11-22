<?php
declare(strict_types=1);
require_once __DIR__.'/document-email.php';
require_once __DIR__.'/module-page.php';
function renderDocumentEmail(string $kind='invoice'): void {
    authPrivacyHeaders();$id=accessId($_GET['id']??null);$type=$kind==='invoice'?($_GET['type']??'customer'):'customer';if(!$id||!is_string($type)||!in_array($type,['customer','supplier'],true))authStop(404,'Documento no disponible.');
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
    $db=createDatabaseConnection();$errors=[];$input=[];$embedded=($_GET['embedded']??'')==='1';
    try{$invoice=emailSource($db,$kind,$id,$type);}catch(InvalidArgumentException $e){authStop(404,$e->getMessage());}
    $choices=emailSourceChoices($db,$invoice,$kind);
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        if(($_POST['action']??'')!=='send_documents')throw new InvalidArgumentException('Acción no válida.');
        $log=sendEmailDocuments($db,$kind,$id,$type,$_POST);header('Location: enviar.php?'.http_build_query(['type'=>$type,'id'=>$id,'log'=>$log]+($embedded?['embedded'=>'1']:[])),true,303);return;
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===409?409:422);$errors[]=$e->getMessage();$input=$_POST;}
    catch(Throwable){http_response_code(503);$errors[]='No se pudo preparar el correo. Revisa la configuración de esta empresa o comunícate con el administrador.';$input=$_POST;}
    $settings=loadCompanyMail($db,(int)$invoice['company_id']);
    $q=$db->prepare("SELECT id,recipient,subject,documents_json,status,sent_at,error_message,created_at,from_email FROM document_email_log WHERE company_id=? AND ((origin_kind=? AND origin_id=?) OR (origin_kind='invoice' AND origin_id IS NULL AND invoice_id=?)) ORDER BY id DESC LIMIT 10");$q->execute([$invoice['company_id'],$kind,$id,$kind==='invoice'?$id:0]);$logs=$q->fetchAll();
    $source=match($kind){'order'=>'index.php?id='.$id,'delivery'=>'operaciones.php?type=delivery&id='.$id,default=>'index.php?'.http_build_query(['type'=>$type,'id'=>$id])};
    $modules=require __DIR__.'/config/modules.php';$moduleKey=match($kind){'order'=>'ventas','delivery'=>'inventario',default=>'contabilidad'};$module=$modules[$moduleKey];$databaseStatus=getDatabaseStatus();ob_start();require dirname(__DIR__).'/html/accounting/email.php';echo permissionRenderHtml(ob_get_clean());
}

<?php
declare(strict_types=1);
require_once __DIR__.'/document-share.php';
require_once __DIR__.'/module-page.php';

function renderWhatsappSettings(): void {
    authPrivacyHeaders();$db=createDatabaseConnection();$company=companyActiveId();$errors=[];
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        if(($_POST['action']??'')!=='save_whatsapp')throw new InvalidArgumentException('Acción no válida.');
        saveWhatsappSettings($db,$_POST);header('Location: whatsapp.php?saved=1',true,303);return;
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===409?409:422);$errors[]=$e->getMessage();}
    catch(Throwable){http_response_code(503);$errors[]='No se pudo guardar la configuración.';}
    $settings=loadWhatsappSettings($db,$company);if($errors)$settings=array_replace($settings,array_intersect_key($_POST,$settings),['enabled'=>(int)(($_POST['enabled']??'')==='1')]);
    $q=$db->prepare('SELECT name FROM business_company WHERE id=?');$q->execute([$company]);$companyName=$q->fetchColumn();
    $publicUrl='';$urlError='';try{$publicUrl=documentPublicBaseUrl();}catch(InvalidArgumentException $e){$urlError=$e->getMessage();}
    $modules=require __DIR__.'/config/modules.php';$moduleKey='ajustes';$module=$modules[$moduleKey];$databaseStatus=getDatabaseStatus();
    ob_start();require dirname(__DIR__).'/html/settings/whatsapp.php';echo permissionRenderHtml(ob_get_clean());
}

function renderDocumentShare(string $kind='invoice'): void {
    authPrivacyHeaders();$id=accessId($_GET['id']??null);$type=$kind==='invoice'?($_GET['type']??'customer'):'customer';
    if(!$id||!is_string($type)||!in_array($type,['customer','supplier'],true))authStop(404,'Documento no disponible.');
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
    $db=createDatabaseConnection();$company=companyActiveId();$errors=[];$input=[];$embedded=($_GET['embedded']??'')==='1';
    try{$record=emailSource($db,$kind,$id,$type);}catch(InvalidArgumentException $e){authStop(404,$e->getMessage());}
    $choices=emailSourceChoices($db,$record,$kind);$settings=loadWhatsappSettings($db,$company);
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        $action=$_POST['action']??'';$shareId=0;
        if($action==='prepare_whatsapp')$shareId=prepareWhatsappShare($db,$kind,$id,$type,$_POST);
        elseif($action==='revoke_whatsapp'){$revokeId=accessId($_POST['share_id']??null);if(!$revokeId)throw new InvalidArgumentException('Enlace no válido.');revokeWhatsappShare($db,$kind,$id,$type,$revokeId);}
        else throw new InvalidArgumentException('Acción no válida.');
        header('Location: compartir.php?'.http_build_query(['type'=>$type,'id'=>$id]+($shareId?['share'=>$shareId]:[])+($embedded?['embedded'=>'1']:[])),true,303);return;
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===409?409:422);$errors[]=$e->getMessage();$input=$_POST;}
    catch(Throwable){http_response_code(503);$errors[]='No se pudo preparar el enlace. Revisa la configuración y la clave de cifrado del servidor.';$input=$_POST;}
    $q=$db->prepare("SELECT COALESCE(NULLIF(TRIM(mobile),''),phone) FROM contacts WHERE id=? AND company_id=?");$q->execute([$record['contact_id'],$company]);$phone=$q->fetchColumn()?:'';
    $q=$db->prepare('SELECT * FROM document_share_links WHERE company_id=? AND origin_kind=? AND origin_id=? ORDER BY id DESC LIMIT 20');$q->execute([$company,$kind,$id]);$links=$q->fetchAll();$prepared=null;$urls=null;
    foreach($links as &$link){$link['active']=!$link['revoked_at']&&$link['expires_at']>(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s');if($link['active']&&(string)$link['id']===($_GET['share']??''))$prepared=$link;}unset($link);
    if($prepared)try{$urls=whatsappShareUrls($prepared);}catch(Throwable){$errors[]='No se pudo recuperar el enlace. Verifica la clave de cifrado del servidor.';}
    $source=match($kind){'order'=>'index.php?id='.$id,'delivery'=>'operaciones.php?type=delivery&id='.$id,default=>'index.php?'.http_build_query(['type'=>$type,'id'=>$id])};
    $modules=require __DIR__.'/config/modules.php';$moduleKey=match($kind){'order'=>'ventas','delivery'=>'inventario',default=>'contabilidad'};$module=$modules[$moduleKey];$databaseStatus=getDatabaseStatus();
    ob_start();require dirname(__DIR__).'/html/accounting/whatsapp.php';echo permissionRenderHtml(ob_get_clean());
}

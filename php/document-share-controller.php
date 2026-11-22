<?php
declare(strict_types=1);
require_once __DIR__.'/document-share.php';
require_once __DIR__.'/module-page.php';

function whatsappWizardResult(PDO $db,string $kind,int $id,string $type,int $share,bool $embedded): array {
    $q=$db->prepare('SELECT * FROM document_share_links WHERE id=? AND company_id=? AND origin_kind=? AND origin_id=?');$q->execute([$share,companyActiveId(),$kind,$id]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Mensaje preparado no disponible.');
    $urls=whatsappShareUrls($row);$q=$db->prepare('SELECT COUNT(*) FROM document_share_links WHERE company_id=? AND origin_kind=? AND origin_id=?');$q->execute([companyActiveId(),$kind,$id]);
    return ['ok'=>true,'share_id'=>(int)$row['id'],'phone'=>$row['recipient_phone'],'message_only'=>(bool)$row['message_only'],'expires_at'=>$row['expires_at'],'urls'=>$urls,'history_count'=>(int)$q->fetchColumn(),'marked_sent_at'=>$row['marked_sent_at'],'marked_sent_action'=>$row['marked_sent_action'],
        'previous_share_id'=>(string)whatsappPreviousPreparation($db,$kind,$id,companyActiveId())['id'],'next_request_key'=>bin2hex(random_bytes(32)),
        'view_url'=>'compartir.php?'.http_build_query(['type'=>$type,'id'=>$id,'share'=>$share]+($embedded?['embedded'=>'1']:[]))];
}

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
    $db=createDatabaseConnection();$company=companyActiveId();$errors=[];$input=[];$repeatConfirmation=false;$embedded=($_GET['embedded']??'')==='1';
    try{$record=emailSource($db,$kind,$id,$type);}catch(InvalidArgumentException $e){authStop(404,$e->getMessage());}
    $choices=emailSourceChoices($db,$record,$kind);$settings=loadWhatsappSettings($db,$company);
    $json=$_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'',['prepare_whatsapp','mark_whatsapp_sent'],true)&&str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        $action=$_POST['action']??'';$shareId=0;
        if($action==='prepare_whatsapp')$shareId=prepareWhatsappShare($db,$kind,$id,$type,$_POST);
        elseif($action==='mark_whatsapp_sent'){
            $shareId=accessId($_POST['share_id']??null);$interaction=$_POST['interaction']??null;if(!$shareId||!is_string($interaction))throw new InvalidArgumentException('Acción de WhatsApp no válida.');$marked=markWhatsappSent($db,$kind,$id,$type,$shareId,$interaction);
            if($json){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>true,'share_id'=>(int)$marked['id'],'marked_sent_at'=>$marked['marked_sent_at'],'marked_sent_action'=>$marked['marked_sent_action']],JSON_THROW_ON_ERROR);return;}
        }
        elseif($action==='revoke_whatsapp'){$revokeId=accessId($_POST['share_id']??null);if(!$revokeId)throw new InvalidArgumentException('Enlace no válido.');revokeWhatsappShare($db,$kind,$id,$type,$revokeId);}
        else throw new InvalidArgumentException('Acción no válida.');
        if($json){header('Content-Type: application/json; charset=UTF-8');echo json_encode(whatsappWizardResult($db,$kind,$id,$type,$shareId,$embedded),JSON_THROW_ON_ERROR);return;}
        header('Location: compartir.php?'.http_build_query(['type'=>$type,'id'=>$id]+($shareId?['share'=>$shareId]:[])+($embedded?['embedded'=>'1']:[])),true,303);return;
    }catch(WhatsappReprepareConfirmationRequired){http_response_code(409);$repeatConfirmation=true;$input=$_POST;}
    catch(InvalidArgumentException $e){http_response_code($e->getCode()===409?409:422);$errors[]=$e->getMessage();$input=$_POST;}
    catch(Throwable $e){http_response_code(503);$errors[]=documentShareFailure($e instanceof PDOException?new DocumentSharePreparationException('storage',$e):$e);$input=$_POST;}
    if($json){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'confirmation_required'=>$repeatConfirmation,'previous_share_id'=>(string)(whatsappPreviousPreparation($db,$kind,$id,$company)['id']??0),'message'=>$errors[0]??'Confirma si deseas preparar otro mensaje.'],JSON_THROW_ON_ERROR);return;}
    $q=$db->prepare('SELECT phone FROM contacts WHERE id=? AND company_id=?');$q->execute([$record['contact_id'],$company]);$phone=$q->fetchColumn()?:'';
    $q=$db->prepare('SELECT * FROM document_share_links WHERE company_id=? AND origin_kind=? AND origin_id=? ORDER BY id DESC LIMIT 20');$q->execute([$company,$kind,$id]);$links=$q->fetchAll();$prepared=null;$urls=null;
    foreach($links as &$link){$link['active']=!empty($link['message_only'])||(!$link['revoked_at']&&$link['expires_at']>(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s'));if($link['active']&&(string)$link['id']===($_GET['share']??''))$prepared=$link;}unset($link);
    if($prepared)try{$urls=whatsappShareUrls($prepared);}catch(Throwable $e){$errors[]=documentShareFailure(new DocumentSharePreparationException('recovery',$e));}
    if($prepared&&!$input){$input=['phone'=>$prepared['recipient_phone'],'documents'=>[]];if($prepared['content_json']!==null)$input['documents']=json_decode($prepared['content_json'],true,16,JSON_THROW_ON_ERROR);else{$q=$db->prepare('SELECT document_key FROM document_share_files WHERE share_id=? AND company_id=?');$q->execute([$prepared['id'],$company]);$input['documents']=$q->fetchAll(PDO::FETCH_COLUMN);}}
    $previousPreparation=whatsappPreviousPreparation($db,$kind,$id,$company);$messagePreview='';try{$messagePreview=whatsappRenderedMessage($db,$record,$kind,$settings,$choices,true,false);}catch(Throwable){$messagePreview='No se pudo generar la vista previa. Revisa la plantilla de WhatsApp de esta empresa.';}
    $source=match($kind){'order'=>'index.php?id='.$id,'delivery'=>'operaciones.php?type=delivery&id='.$id,default=>'index.php?'.http_build_query(['type'=>$type,'id'=>$id])};
    $modules=require __DIR__.'/config/modules.php';$moduleKey=match($kind){'order'=>'ventas','delivery'=>'inventario',default=>'contabilidad'};$module=$modules[$moduleKey];$databaseStatus=getDatabaseStatus();
    ob_start();require dirname(__DIR__).'/html/accounting/whatsapp.php';echo permissionRenderHtml(ob_get_clean());
}

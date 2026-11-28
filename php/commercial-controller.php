<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/commercial-repository.php';
require_once __DIR__.'/ui-icons.php';
require_once __DIR__.'/commercial-admin-auth.php';
function renderCommercialSite(): void {
    authStartSession();authPrivacyHeaders();
    if($_SERVER['REQUEST_METHOD']==='POST'){header('Location: '.authBasePath().'/portal-demo.php',true,303);return;}
    if($_SERVER['REQUEST_METHOD']!=='GET')authStop(405,'Método no admitido.');
    $input=[];$plans=commercialPlans();$modules=commercialModules();$sectors=commercialSectors();$e='authEscape';
    require dirname(__DIR__).'/html/commercial/site.php';
}
function renderCommercialRequests(): void {
    $user=commercialAdminRequire();authPrivacyHeaders();
    $errors=[];$method=$_SERVER['REQUEST_METHOD'];$record=null;$history=[];$edit=[];
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');authStop(405,'Método no admitido.');}
    $db=createDatabaseConnection(true);
    $status=is_string($_GET['status']??null)&&isset(commercialStatuses()[$_GET['status']])?$_GET['status']:'';$search=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,160):'';
    $requestedPage=max(1,(int)($_GET['page']??1));$tab=($_POST['active_tab']??$_GET['tab']??'datos');if(!is_string($tab)||!in_array($tab,['datos','seguimiento','notas'],true))$tab='datos';
    $recordRequested=$method==='POST'||isset($_GET['request']);$recordId=filter_var($method==='POST'?($_POST['id']??null):($_GET['request']??null),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($method==='POST')try{
        if(!authCheckCsrf($_POST['csrf']??null))throw new InvalidArgumentException('Recarga la página antes de guardar.',403);
        if(isset($_GET['request'])&&filter_var($_GET['request'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==$recordId)throw new InvalidArgumentException('La ficha no coincide con la solicitud enviada.',403);
        $action=$_POST['action']??'save';if(!in_array($action,['save','approve','reject'],true))throw new InvalidArgumentException('Acción no disponible.');
        $changes=$_POST;if($action==='approve')$changes['status']='approved';if($action==='reject')$changes['status']='rejected';
        commercialUpdateDemo($db,$changes,(int)$user['id']);header('Location: '.authBasePath().'/solicitudes-demo.php?'.http_build_query(['request'=>(int)$recordId,'tab'=>$tab,'status'=>$status,'q'=>$search,'page'=>$requestedPage,'saved'=>'1']),true,303);return;
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,409],true)?$e->getCode():422);$errors[]=$e->getMessage();}
    $where=[];$params=[];if($status!==''){$where[]='status=?';$params[]=$status;}if($search!==''){$where[]='(full_name LIKE ? OR business_name LIKE ? OR email LIKE ? OR phone LIKE ?)';array_push($params,...array_fill(0,4,'%'.$search.'%'));}
    $where=$where?' WHERE '.implode(' AND ',$where):'';$q=$db->prepare('SELECT COUNT(*) FROM commercial_demo_requests'.$where);$q->execute($params);$total=(int)$q->fetchColumn();
    $pages=max(1,(int)ceil($total/20));$current=min(max(1,(int)($_GET['page']??1)),$pages);$q=$db->prepare('SELECT * FROM commercial_demo_requests'.$where.' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET '.(($current-1)*20));$q->execute($params);$rows=$q->fetchAll();
    $summary=array_fill_keys(array_keys(commercialStatuses()),0);foreach($db->query('SELECT status,COUNT(*) AS quantity FROM commercial_demo_requests GROUP BY status')->fetchAll() as $count)if(isset($summary[$count['status']]))$summary[$count['status']]=(int)$count['quantity'];
    if($recordRequested){
        if($recordId){$q=$db->prepare('SELECT * FROM commercial_demo_requests WHERE id=?');$q->execute([$recordId]);$record=$q->fetch()?:null;}
        if(!$record){if(!$errors)http_response_code(404);$errors[]='No encontramos esa solicitud.';}
        else{
            $q=$db->prepare('SELECT status,message,created_at FROM commercial_demo_events WHERE request_id=? ORDER BY id');$q->execute([$recordId]);$history=$q->fetchAll();
            $edit=['status'=>$record['status'],'public_message'=>$record['public_message']??'','internal_notes'=>$record['internal_notes'],'version'=>(string)$record['version']];
            if($method==='POST'&&$errors&&authCheckCsrf($_POST['csrf']??null))foreach(array_keys($edit) as $key)if(is_string($_POST[$key]??null)&&mb_strlen($_POST[$key])<=2000)$edit[$key]=$_POST[$key];
        }
    }
    $context=['status'=>$status,'q'=>$search,'page'=>$current];$base=authBasePath();$listUrl=$base.'/solicitudes-demo.php?'.http_build_query($context);
    $statuses=commercialStatuses();$plans=commercialPlans();$sectors=commercialSectors();$e='authEscape';require dirname(__DIR__).'/html/commercial/requests.php';
}

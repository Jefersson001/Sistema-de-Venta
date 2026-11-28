<?php
declare(strict_types=1);
require_once __DIR__.'/commercial-portal-auth.php';
require_once __DIR__.'/ui-icons.php';

function renderCommercialPortal(): void {
    authStartSession();authPrivacyHeaders();$errors=[];$input=[];$user=null;$rows=[];$events=[];$pages=1;$current=1;
    $counts=array_fill_keys(array_keys(commercialStatuses()),0);$total=0;$canRequest=false;$blockedRequest=null;$hasApprovedDemo=false;
    $requestDetail=null;$detailEvents=[];$detailRequested=isset($_GET['request'])&&($_GET['section']??'')!=='cuenta';
    $detailTab=($_GET['tab']??'')==='seguimiento'?'seguimiento':'datos';
    $status=is_string($_GET['status']??null)&&isset(commercialStatuses()[$_GET['status']])?$_GET['status']:'';
    $view=is_string($_GET['view']??null)&&in_array($_GET['view'],['login','registro','cuenta'],true)?$_GET['view']:'';
    $plan=is_string($_GET['plan']??null)&&isset(commercialPlans()[$_GET['plan']])?$_GET['plan']:($_SESSION['portal_plan']??'undecided');$_SESSION['portal_plan']=$plan;
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))authStop(405,'Método no admitido.');
    try{
        $user=commercialPortalUser();$view=$user?'cuenta':($view==='registro'?'registro':'login');
        if($method==='POST'){
            foreach(['full_name','business_name','email','phone','sector','city','company_count','user_count','plan_key','contact_method','requirements','consent'] as $key)if(is_string($_POST[$key]??null)&&strlen($_POST[$key])<=10000)$input[$key]=$_POST[$key];
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16000)throw new InvalidArgumentException('El formulario es demasiado extenso.');
            if(!authCheckCsrf($_POST['csrf']??null))throw new InvalidArgumentException('El formulario venció. Recarga e inténtalo de nuevo.',403);
            $action=$_POST['action']??'';$db=createDatabaseConnection(true);
            if($action==='logout'){
                commercialPortalForget();header('Location: '.authBasePath().'/portal-demo.php',true,303);return;
            }
            if(!$user&&in_array($action,['register','login'],true)){
                $view=$action==='register'?'registro':'login';
                $account=$action==='register'?commercialPortalRegister($db,$_POST,$_SERVER['REMOTE_ADDR']??''):commercialPortalLogin($db,$_POST,$_SERVER['REMOTE_ADDR']??'');
                commercialPortalSession($account);header('Location: '.authBasePath().'/portal-demo.php',true,303);return;
            }
            if(!$user){header('Location: '.authBasePath().'/portal-demo.php?view=login',true,303);return;}
            if($action==='request'){
                $key=$_POST['request_key']??null;
                if(!is_string($key)||!isset($_SESSION['commercial_form_keys'][$key])||$_SESSION['commercial_form_keys'][$key]<time()-7200)throw new InvalidArgumentException('El formulario venció. Recarga e inténtalo de nuevo.',403);
                if(($_POST['website']??'')!=='')throw new InvalidArgumentException('No se pudo validar la solicitud.');
                $data=commercialValidateDemo(array_replace($_POST,array_intersect_key($user,array_flip(['full_name','business_name','email','phone']))));
                commercialSaveDemo($db,$data,$key,$_SERVER['REMOTE_ADDR']??'',(int)$user['id']);
                $_SESSION['portal_success']='Recibimos tu solicitud. Aquí podrás consultar su seguimiento.';header('Location: '.authBasePath().'/portal-demo.php?solicitud=recibida',true,303);return;
            }
            if($action==='password'){
                commercialPortalLimit($db,'password',(string)$user['id'],10);$password=authPassword($_POST['password']??null);
                if(!is_string($_POST['password_confirmation']??null)||!hash_equals($password,$_POST['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
                $q=$db->prepare('SELECT password_hash,auth_version FROM commercial_portal_accounts WHERE id=?');$q->execute([$user['id']]);$account=$q->fetch();
                $old=$_POST['current_password']??null;if(!is_string($old)||strlen($old)>72||!password_verify($old,$account['password_hash']))throw new InvalidArgumentException('La contraseña actual no es correcta.');
                $q=$db->prepare('UPDATE commercial_portal_accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND auth_version=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),$user['id'],$account['auth_version']]);
                if(!$q->rowCount())throw new InvalidArgumentException('Tu cuenta cambió. Inicia sesión de nuevo.',409);
                commercialPortalSession(['id'=>$user['id'],'auth_version'=>(int)$account['auth_version']+1]);$_SESSION['portal_success']='Contraseña actualizada.';header('Location: '.authBasePath().'/portal-demo.php?section=cuenta',true,303);return;
            }
            throw new InvalidArgumentException('Acción no admitida.');
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[401,403,409,429],true)?$e->getCode():422);$errors[]=$e->getMessage();}
    catch(Throwable $e){error_log('Portal comercial: '.$e->getMessage());http_response_code(503);$errors[]='El portal no está disponible por el momento. Inténtalo nuevamente más tarde.';}
    $success=$method==='GET'?($_SESSION['portal_success']??''):'';if($method==='GET')unset($_SESSION['portal_success']);
    $formKey=bin2hex(random_bytes(32));$keys=$_SESSION['commercial_form_keys']??[];$keys=array_filter($keys,static fn($issued)=>$issued>=time()-7200);$keys[$formKey]=time();$_SESSION['commercial_form_keys']=array_slice($keys,-8,null,true);
    if($user)try{
        $db=createDatabaseConnection(true);$q=$db->prepare('SELECT status,COUNT(*) AS quantity FROM commercial_demo_requests WHERE portal_account_id=? GROUP BY status');$q->execute([$user['id']]);foreach($q->fetchAll() as $count)if(isset($counts[$count['status']]))$counts[$count['status']]=(int)$count['quantity'];$total=array_sum($counts);
        $blockedRequest=commercialBlockingDemo($db,(int)$user['id']);$canRequest=$blockedRequest===null;
        $hasApprovedDemo=$counts['approved']>0;
        $filteredTotal=$status!==''?$counts[$status]:$total;$pages=max(1,(int)ceil($filteredTotal/20));$current=min($pages,max(1,(int)($_GET['page']??1)));
        $where='portal_account_id=?';$params=[$user['id']];if($status!==''){$where.=' AND status=?';$params[]=$status;}
        $q=$db->prepare('SELECT id,sector,city,company_count,user_count,plan_key,contact_method,requirements,status,created_at FROM commercial_demo_requests WHERE '.$where.' ORDER BY id DESC LIMIT 20 OFFSET '.(($current-1)*20));$q->execute($params);$rows=$q->fetchAll();
        $q=$db->prepare('SELECT e.request_id,e.status,e.message,e.created_at FROM commercial_demo_events e JOIN commercial_demo_requests r ON r.id=e.request_id WHERE r.portal_account_id=? AND r.id IN ('.implode(',',array_map(static fn($r)=>(int)$r['id'],$rows)?:[0]).') ORDER BY e.id');$q->execute([$user['id']]);foreach($q->fetchAll() as $event)$events[$event['request_id']][]=$event;
        if($detailRequested){
            $detailId=filter_var($_GET['request'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($detailId){$q=$db->prepare('SELECT id,full_name,business_name,email,phone,sector,city,company_count,user_count,plan_key,contact_method,requirements,status,created_at FROM commercial_demo_requests WHERE id=? AND portal_account_id=?');$q->execute([$detailId,$user['id']]);$requestDetail=$q->fetch()?:null;}
            if(!$requestDetail){http_response_code(404);$errors[]='No encontramos esa solicitud en tu cuenta.';}
            else{$q=$db->prepare('SELECT status,message,created_at FROM commercial_demo_events WHERE request_id=? ORDER BY id');$q->execute([$requestDetail['id']]);$detailEvents=$q->fetchAll();}
        }
    }catch(Throwable $e){error_log('Seguimiento comercial: '.$e->getMessage());http_response_code(503);$errors[]='No pudimos cargar el seguimiento. Inténtalo más tarde.';}
    $plans=commercialPlans();$sectors=commercialSectors();$statuses=commercialStatuses();$e='authEscape';$base=authBasePath();
    require dirname(__DIR__).'/html/commercial/portal.php';
}

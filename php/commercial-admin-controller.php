<?php
declare(strict_types=1);
require_once __DIR__.'/commercial-admin-auth.php';
require_once __DIR__.'/ui-icons.php';
function renderCommercialAdmin(): void {
    authStartSession();authPrivacyHeaders();$errors=[];$input=[];$user=null;$setup=false;$success='';
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))authStop(405,'Método no admitido.');
    try{
        $db=createDatabaseConnection(true);$user=commercialAdminCurrent();
        if($method==='GET'&&isset($_GET['setup'])){
            $token=$_GET['setup'];
            if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!commercialAdminSetupValid($db,hash('sha256',$token)))throw new InvalidArgumentException('El enlace de habilitación no es válido o ya venció.',403);
            $_SESSION['commercial_admin_setup_hash']=hash('sha256',$token);header('Location: '.authBasePath().'/admin-comercial.php?view=activar',true,303);return;
        }
        $setup=!$user&&commercialAdminSetupValid($db,$_SESSION['commercial_admin_setup_hash']??null);
        if($method==='POST'){
            foreach(['name','email'] as $key)if(is_string($_POST[$key]??null)&&strlen($_POST[$key])<=1000)$input[$key]=$_POST[$key];
            if((int)($_SERVER['CONTENT_LENGTH']??0)>8000)throw new InvalidArgumentException('El formulario es demasiado extenso.');
            if(!authCheckCsrf($_POST['csrf']??null))throw new InvalidArgumentException('El formulario venció. Recarga la página.',403);
            $action=$_POST['action']??'';$ip=$_SERVER['REMOTE_ADDR']??'';
            if($action==='logout'){
                commercialAdminForget();header('Location: '.authBasePath().'/admin-comercial.php',true,303);return;
            }
            if(!$user&&$action==='activate'){
                commercialPortalLimit($db,'admin-setup',$ip,5);$account=commercialAdminActivate($db,$_POST,$_SESSION['commercial_admin_setup_hash']??null);commercialAdminSession($account);
                header('Location: '.authBasePath().'/solicitudes-demo.php',true,303);return;
            }
            if(!$user&&$action==='login'){
                $account=commercialAdminLogin($db,$_POST,$ip);commercialAdminSession($account);header('Location: '.authBasePath().'/solicitudes-demo.php',true,303);return;
            }
            if($user&&$action==='password'){
                commercialPortalLimit($db,'admin-password',(string)$user['id'],10);$account=commercialAdminChangePassword($db,(int)$user['id'],$_POST);commercialAdminSession($account);
                header('Location: '.authBasePath().'/admin-comercial.php?view=cuenta&saved=1',true,303);return;
            }
            throw new InvalidArgumentException('Acción no disponible.',403);
        }
        if($user&&($_GET['view']??'')!=='cuenta'){header('Location: '.authBasePath().'/solicitudes-demo.php',true,303);return;}
        if($user&&($_GET['saved']??'')==='1')$success='Contraseña actualizada.';
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[401,403,409,429],true)?$e->getCode():422);$errors[]=$e->getMessage();}
    catch(Throwable $e){error_log('Acceso comercial: '.$e->getMessage());http_response_code(503);$errors[]='El acceso comercial no está disponible. Comprueba que se aplicaron las migraciones e inténtalo más tarde.';}
    $base=authBasePath();$e='authEscape';require dirname(__DIR__).'/html/commercial/admin-access.php';
}

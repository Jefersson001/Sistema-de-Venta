<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/database-status.php';

function renderAuthentication(string $mode): void
{
    authPrivacyHeaders();$errors=[];$input=[];$next=authSafeNext($_GET['next']??$_POST['next']??null);
    $user=null;
    try {
        authStartSession();
        $db=createDatabaseConnection();
        if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){header('Allow: GET, POST');authStop(405,'El método solicitado no está disponible.');}
        if($mode==='login') {
            if($logged=authCurrentUser()){header('Location: '.authBasePath().($logged['must_change_password']?'/cambiar-contrasena.php':'/index.php'),true,303);return;}
        } elseif($mode==='activate') {
            if(isset($_GET['token'])) {
                $token=$_GET['token'];
                if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!authBootstrapValid($db,hash('sha256',$token)))authStop(403,'El enlace de activación venció o no es válido.',false,authBasePath().'/login.php');
                $_SESSION['auth_setup_hash']=hash('sha256',$token);$_SESSION['auth_csrf']=bin2hex(random_bytes(32));
                header('Location: '.authBasePath().'/activar.php',true,303);return;
            }
            if(!is_string($_SESSION['auth_setup_hash']??null)||!authBootstrapValid($db,$_SESSION['auth_setup_hash']))authStop(403,'Necesitas un enlace de activación vigente generado desde el equipo del negocio.',false,authBasePath().'/login.php');
        } else $user=authRequireLogin(false,true);
        if($_SERVER['REQUEST_METHOD']==='POST') {
            if(!authCheckCsrf($_POST['csrf']??null)){http_response_code(403);$errors[]='La sesión del formulario no es válida. Recarga la página.';}
            else {
                try {
                    if($mode==='login') {
                        $result=authAttemptLogin($db,$_POST['login']??null,$_POST['password']??null,$_SERVER['REMOTE_ADDR']??'');
                        if($result['blocked']){http_response_code(429);$errors[]='Demasiados intentos. Espera 15 minutos antes de volver a intentarlo.';}
                        elseif(!$result['user']){$errors[]='Usuario o contraseña incorrectos.';}
                        else {
                            $logged=authEstablishSession($db,(int)$result['user']['id'],(int)$result['user']['auth_version']);
                            header('Location: '.($logged['must_change_password']?authBasePath().'/cambiar-contrasena.php':$next),true,303);return;
                        }
                    } elseif($mode==='activate') {
                        $id=createFirstAdministrator($db,$_POST,$_SESSION['auth_setup_hash']);authEstablishSession($db,$id);
                        header('Location: '.authBasePath().'/index.php',true,303);return;
                    } else {
                        changeAuthPassword($db,(int)$user['id'],(int)$user['auth_version'],$_POST['current_password']??null,$_POST['password']??null,$_POST['password_confirmation']??null);
                        authEstablishSession($db,(int)$user['id'],(int)$user['auth_version']+1);$_SESSION['auth_flash']='Contraseña actualizada.';
                        header('Location: '.authBasePath().'/perfil.php',true,303);return;
                    }
                } catch(InvalidArgumentException $exception){if(in_array($exception->getCode(),[403,409],true))http_response_code($exception->getCode());$errors[]=$exception->getMessage();}
            }
            foreach(['name','login','email'] as $key)if(is_string($_POST[$key]??null))$input[$key]=$_POST[$key];
        }
    }catch(Throwable $exception){error_log('Acceso: '.$exception->getMessage());authStop(503,'No se pudo abrir el acceso. Verifica la instalación de Ajustes.');}
    require dirname(__DIR__).'/html/auth/form.php';
}

function renderAuthProfile(): void
{
    $user=authRequireLogin();authPrivacyHeaders();
    if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'El perfil solo admite consultas.');}
    $flash=$_SESSION['auth_flash']??null;unset($_SESSION['auth_flash']);$databaseStatus=getDatabaseStatus();
    require dirname(__DIR__).'/html/auth/profile.php';
}

function logoutAuthentication(): void
{
    authPrivacyHeaders();
    try{authStartSession();}catch(Throwable $exception){error_log('Cerrar sesión: '.$exception->getMessage());authStop(503,'No se pudo comprobar la sesión.');}
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');authStop(405,'Usa Cerrar sesión desde el menú de tu usuario.');}
    if(!authCheckCsrf($_POST['csrf']??null))authStop(403,'La sesión del formulario no es válida.');
    authForgetSession();header('Location: '.authBasePath().'/login.php',true,303);
}

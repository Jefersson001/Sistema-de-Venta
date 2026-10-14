<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/auth-repository.php';

function authBasePath(): string { return '/project'; }
function authEscape(mixed $value): string {return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

function authStartSession(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    session_name('NEXOSESSID');
    session_set_cookie_params(['lifetime'=>0,'path'=>authBasePath().'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
        'httponly'=>true,'samesite'=>'Lax']);
    if(!@session_start())throw new RuntimeException('No se pudo iniciar la sesión.');
    $_SESSION['auth_csrf']??=bin2hex(random_bytes(32));
}

function authCheckCsrf(mixed $token): bool
{
    authStartSession();return is_string($token)&&hash_equals($_SESSION['auth_csrf'],$token);
}

function authPrivacyHeaders(): void
{
    header('Cache-Control: no-store');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
}

function authForgetSession(): void
{
    authStartSession();$_SESSION=[];session_regenerate_id(true);$_SESSION['auth_csrf']=bin2hex(random_bytes(32));
    unset($GLOBALS['auth_request_user']);
}

function authEstablishSession(PDO $db,int $id,?int $expectedVersion=null): array
{
    $user=loadAuthUser($db,$id);
    if(!$user||!$user['active']||($expectedVersion!==null&&(int)$user['auth_version']!==$expectedVersion))throw new InvalidArgumentException('La cuenta cambió. Vuelve a iniciar sesión.',403);
    authStartSession();$_SESSION=[];session_regenerate_id(true);
    $_SESSION['auth_user_id']=$id;$_SESSION['auth_version']=(int)$user['auth_version'];
    $_SESSION['auth_started']=time();$_SESSION['auth_seen']=time();$_SESSION['auth_csrf']=bin2hex(random_bytes(32));
    $GLOBALS['auth_request_user']=$user;return $user;
}

function authCurrentUser(): ?array
{
    authStartSession();
    if(array_key_exists('auth_request_user',$GLOBALS))return $GLOBALS['auth_request_user'];
    $id=accessId($_SESSION['auth_user_id']??null);
    if(!$id)return $GLOBALS['auth_request_user']=null;
    if(time()-(int)($_SESSION['auth_seen']??0)>1800||time()-(int)($_SESSION['auth_started']??0)>28800) {authForgetSession();return $GLOBALS['auth_request_user']=null;}
    $user=loadAuthUser(createDatabaseConnection(),$id);
    if(!$user||!$user['active']||(int)$user['auth_version']!==(int)($_SESSION['auth_version']??0)) {authForgetSession();return $GLOBALS['auth_request_user']=null;}
    $_SESSION['auth_seen']=time();return $GLOBALS['auth_request_user']=$user;
}

function authSafeNext(mixed $next): string
{
    $fallback=authBasePath().'/index.php';
    if(!is_string($next)||strlen($next)>2000||!str_starts_with($next,authBasePath().'/')
        ||preg_match('/[\x00-\x20\\\\]/',rawurldecode($next))||parse_url($next,PHP_URL_HOST)!==null||parse_url($next,PHP_URL_SCHEME)!==null)return $fallback;
    $path=parse_url($next,PHP_URL_PATH);
    if(in_array($path,array_map(static fn($p)=>authBasePath().'/'.$p,['login.php','activar.php','logout.php','cambiar-contrasena.php']),true))return $fallback;
    return $next;
}

function authStop(int $status,string $message,bool $json=false,?string $redirect=null): never
{
    authPrivacyHeaders();http_response_code($status);
    if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$message,'redirect'=>$redirect],JSON_UNESCAPED_UNICODE);}
    else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acceso | Nexo</title><link rel="stylesheet" href="'.authBasePath().'/css/app.css"><main class="auth-page"><section class="contact-sheet auth-card"><h1>Acceso al sistema</h1><p>'.authEscape($message).'</p><a class="contact-button" href="'.authEscape($redirect??authBasePath().'/index.php').'">Continuar</a></section></main></html>';
    }
    exit;
}

function authRequireLogin(bool $json=false,bool $allowPasswordChange=false): array
{
    authPrivacyHeaders();
    try {$user=authCurrentUser();}
    catch(Throwable $exception){error_log('Sesión: '.$exception->getMessage());authStop(503,'No se pudo comprobar la sesión. Verifica la instalación de Ajustes.',$json);}
    if(!$user) {
        $url=authBasePath().'/login.php?'.http_build_query(['next'=>authSafeNext($_SERVER['REQUEST_URI']??null)]);
        if($json)authStop(401,'Inicia sesión para continuar.',$json,$url);
        header('Location: '.$url,true,303);exit;
    }
    if($user['must_change_password']&&!$allowPasswordChange) {
        $url=authBasePath().'/cambiar-contrasena.php';
        if($json)authStop(403,'Debes cambiar tu contraseña temporal antes de continuar.',$json,$url);
        header('Location: '.$url,true,303);exit;
    }
    return $user;
}

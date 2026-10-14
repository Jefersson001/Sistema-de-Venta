<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/auth.php';
function authAssert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function authReject(callable $action): void {try{$action();}catch(InvalidArgumentException){return;}throw new RuntimeException('Se aceptó una operación de autenticación inválida.');}
$server=startAuthTestServer();$db=$server['db'];$cookies=[];
$cookie=function()use(&$cookies){$file=tempnam(sys_get_temp_dir(),'nexo-auth-cookie-');$cookies[]=$file;return $file;};
try {
    $anon=$cookie();$adminCookie=$cookie();$staffCookie=$cookie();$staffOldCookie=$cookie();
    $prefix='Prueba autenticación '.bin2hex(random_bytes(8));$login='admin.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));
    foreach(['/index.php','/modules/ajustes/usuarios.php','/modules/ventas/index.php','/modules/contabilidad/tesoreria.php','/modules/inventario/operaciones.php'] as $path) {
        [$code,,$location]=authTestRequest($server,$path,$anon);authAssert($code===303&&str_contains($location,'login.php'),'Una entrada permite acceso sin sesión.');
    }
    foreach(['/modules/dashboard/resumen.php','/modules/dashboard/ingresos-egresos.php','/modules/ventas/producto.php?id=1'] as $path) {
        [$code,$body]=authTestRequest($server,$path,$anon);$json=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        authAssert($code===401&&isset($json['error'])&&!isset($json['dashboard'])&&!isset($json['product']),'Una consulta automática expone datos sin sesión.');
    }
    foreach(['/php/config/database.php','/html/dashboard.php','/test/authentication.php','/.git/config'] as $path){[$code]=authTestRequest($server,$path,$anon);authAssert($code===403,'Un archivo privado es accesible.');}
    authReject(fn()=>createFirstAdministrator($db,['name'=>$prefix,'login'=>$login,'password'=>$password,'password_confirmation'=>$password],str_repeat('a',64)));
    $first=prepareFirstAdministrator($db,1000);authReject(fn()=>createFirstAdministrator($db,['name'=>$prefix,'login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$first),2801));
    $token=prepareFirstAdministrator($db);[$code,,$location]=authTestRequest($server,'/activar.php?token='.$token,$adminCookie);
    authAssert($code===303&&!str_contains($location,$token),'La activación no retira la clave de la dirección.');
    [, $form]=authTestRequest($server,'/activar.php',$adminCookie);$csrf=authTestToken($form);
    [$code]=authTestRequest($server,'/activar.php',$adminCookie,['csrf'=>'wrong','name'=>$prefix,'login'=>$login,'password'=>$password,'password_confirmation'=>$password]);authAssert($code===403,'La activación acepta CSRF inválido.');
    [$code,,$location]=authTestRequest($server,'/activar.php',$adminCookie,['csrf'=>$csrf,'name'=>$prefix,'login'=>$login,'email'=>'admin@example.com','password'=>$password,'password_confirmation'=>$password]);
    authAssert($code===303&&str_ends_with($location,'index.php'),'No se crea el primer administrador.');
    $admin=(int)$db->query('SELECT id FROM auth_users')->fetchColumn();authAssert(authUserIsAdministrator($db,$admin),'La primera cuenta no recibe administración.');
    authReject(fn()=>prepareFirstAdministrator($db));
    [$code]=authTestRequest($server,'/activar.php?token='.$token,$anon);authAssert($code===403,'El enlace de activación puede reutilizarse.');
    [, $home]=authTestRequest($server,'/index.php',$adminCookie);authAssert(str_contains($home,$prefix)&&str_contains($home,'Cerrar sesión'),'No se muestra la identidad en la barra.');
    $adminRecord=loadAuthUser($db,$admin);$adminInput=['name'=>$prefix,'login'=>$login,'email'=>'admin@example.com','active'=>'0','groups'=>[]];
    authReject(fn()=>saveAuthUser($db,$adminInput,$admin,1,$admin));authAssert(loadAuthUser($db,$admin)===$adminRecord,'Se puede perder el último administrador.');
    authAssert(authSafeNext('https://example.com/')==='/project/index.php'&&authSafeNext('/project/index.php%0d%0aLocation:evil')==='/project/index.php','La redirección permite un destino externo o una cabecera inyectada.');
    $sales=(int)$db->query("SELECT id FROM access_groups WHERE system_key='sales'")->fetchColumn();
    $staffLogin='venta.'.bin2hex(random_bytes(5));$temporary=bin2hex(random_bytes(14));$personal=bin2hex(random_bytes(14));
    [, $form]=authTestRequest($server,'/modules/ajustes/usuarios.php?new=1',$adminCookie);$settingsCsrf=authTestToken($form);$key=authTestToken($form,'creation_key');
    $staffInput=['csrf'=>$settingsCsrf,'creation_key'=>$key,'action'=>'save_user','name'=>$prefix.' <script>','login'=>$staffLogin,'email'=>'venta@example.com','active'=>'1','groups'=>[$sales],
        'password'=>$temporary,'password_confirmation'=>$temporary];
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?new=1',$adminCookie,array_replace($staffInput,['csrf'=>'wrong']));authAssert($code===403,'Usuarios acepta CSRF inválido.');
    [$code,$body,$location]=authTestRequest($server,'/modules/ajustes/usuarios.php?new=1',$adminCookie,$staffInput);
    authAssert($code===303&&preg_match('/id=(\d+)/',$location,$match),'No se crea un usuario desde el formulario.');$staff=(int)$match[1];
    [$code,,$duplicate]=authTestRequest($server,'/modules/ajustes/usuarios.php?new=1',$adminCookie,$staffInput);authAssert($code===303&&$duplicate===$location,'Un reenvío duplica la cuenta.');
    $hash=$db->query('SELECT password_hash FROM auth_users WHERE id='.$staff)->fetchColumn();authAssert($hash!==$temporary&&password_verify($temporary,$hash),'La contraseña no se guarda como hash.');
    if(in_array('--export-views',$argv,true)) {
        [, $newUserView]=authTestRequest($server,'/modules/ajustes/usuarios.php?new=1',$adminCookie);
        $newUserView=str_replace(['href="../../css/','src="../../js/'],['href="http://localhost/project/css/','src="http://localhost/project/js/'],$newUserView);
        file_put_contents(sys_get_temp_dir().'/nexo-users-phase3-preview.html',$newUserView);
    }
    [, $userView]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$staff,$adminCookie);authAssert(str_contains($userView,'&lt;script&gt;')&&!str_contains($userView,$temporary)&&!str_contains($userView,$hash),'La ficha expone la contraseña o presenta texto inseguro.');
    [, $loginForm]=authTestRequest($server,'/login.php',$staffCookie);$loginCsrf=authTestToken($loginForm);
    [$code]=authTestRequest($server,'/login.php',$staffCookie,['csrf'=>'wrong','login'=>$staffLogin,'password'=>$temporary]);authAssert($code===403,'Login acepta CSRF inválido.');
    for($i=0;$i<10;$i++)authTestRequest($server,'/login.php',$staffCookie,['csrf'=>$loginCsrf,'login'=>$staffLogin,'password'=>'clave incorrecta']);
    [$code]=authTestRequest($server,'/login.php',$staffCookie,['csrf'=>$loginCsrf,'login'=>$staffLogin,'password'=>$temporary]);authAssert($code===429,'No se limitan intentos por usuario.');
    $db->exec('DELETE FROM auth_login_limits');
    [$code,,$location]=authTestRequest($server,'/login.php',$staffCookie,['csrf'=>$loginCsrf,'login'=>strtoupper($staffLogin),'password'=>$temporary,'next'=>'https://example.com']);
    authAssert($code===303&&str_ends_with($location,'cambiar-contrasena.php'),'La contraseña temporal no obliga a cambiarla.');
    [$code,,$location]=authTestRequest($server,'/index.php',$staffCookie);authAssert($code===303&&str_ends_with($location,'cambiar-contrasena.php'),'Se puede evitar el cambio obligatorio.');
    copy($staffCookie,$staffOldCookie);
    [, $passwordForm]=authTestRequest($server,'/cambiar-contrasena.php',$staffCookie);$passwordCsrf=authTestToken($passwordForm);
    [$code,,$location]=authTestRequest($server,'/cambiar-contrasena.php',$staffCookie,['csrf'=>$passwordCsrf,'current_password'=>$temporary,'password'=>$personal,'password_confirmation'=>$personal]);
    authAssert($code===303&&str_ends_with($location,'perfil.php'),'No se cambia la contraseña temporal.');
    [$code]=authTestRequest($server,'/perfil.php',$staffCookie);authAssert($code===200,'No se mantiene la sesión propia tras cambiar contraseña.');
    [$code]=authTestRequest($server,'/perfil.php',$staffOldCookie);authAssert($code===303,'La sesión anterior sobrevive al cambio de contraseña.');
    $beforeGroups=loadAuthUser($db,$staff);
    saveAuthUser($db,array_replace($staffInput,['groups'=>[]]),$staff,(int)$beforeGroups['version'],$admin);
    [$code]=authTestRequest($server,'/perfil.php',$staffCookie);authAssert($code===303,'Cambiar los grupos no invalida la sesión.');
    saveAuthUser($db,$staffInput,$staff,(int)loadAuthUser($db,$staff)['version'],$admin);
    [, $staffLoginForm]=authTestRequest($server,'/login.php',$staffCookie);
    [$code]=authTestRequest($server,'/login.php',$staffCookie,['csrf'=>authTestToken($staffLoginForm),'login'=>$staffLogin,'password'=>$personal]);authAssert($code===303,'No se recupera acceso con los grupos actualizados.');
    [$code,$staffHome]=authTestRequest($server,'/index.php',$staffCookie);authAssert($code===200&&str_contains($staffHome,'/modules/ventas/index.php')&&!str_contains($staffHome,'/modules/ajustes/index.php'),'Inicio no respeta los permisos del usuario.');
    foreach(['/modules/ajustes/usuarios.php','/modules/ajustes/grupos.php','/modules/contabilidad/index.php?type=supplier','/modules/dashboard/ingresos-egresos.php'] as $path){[$code]=authTestRequest($server,$path,$staffCookie);authAssert($code===403,'Un usuario normal puede abrir una operación no autorizada.');}
    $before=loadAuthUser($db,$staff);authReject(fn()=>saveAuthUser($db,$staffInput,$staff,(int)$before['version'],$staff));
    [, $view]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$staff,$adminCookie);$settingsCsrf=authTestToken($view);
    $edit=['csrf'=>$settingsCsrf,'action'=>'save_user','version'=>(string)$before['version'],'name'=>$before['name'],'login'=>$staffLogin,'email'=>'venta@example.com','active'=>'0','groups'=>[$sales]];
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$staff,$adminCookie,$edit);authAssert($code===303,'No se desactiva la cuenta.');
    [$code]=authTestRequest($server,'/perfil.php',$staffCookie);authAssert($code===303,'Una cuenta desactivada conserva su sesión.');
    $after=loadAuthUser($db,$staff);$edit['active']='1';$edit['version']=(string)$after['version'];
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$staff,$adminCookie,$edit);authAssert($code===303,'No se reactiva la cuenta.');
    [, $staffLoginForm]=authTestRequest($server,'/login.php',$staffCookie);
    [$code]=authTestRequest($server,'/login.php',$staffCookie,['csrf'=>authTestToken($staffLoginForm),'login'=>$staffLogin,'password'=>$personal]);authAssert($code===303,'Una cuenta reactivada no puede iniciar sesión.');
    $newTemporary=bin2hex(random_bytes(14));$after=loadAuthUser($db,$staff);
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$staff,$adminCookie,['csrf'=>$settingsCsrf,'action'=>'reset_password','version'=>(string)$after['version'],'password'=>$newTemporary,'password_confirmation'=>$newTemporary]);
    authAssert($code===303&&loadAuthUser($db,$staff)['must_change_password'],'Restablecer no marca la contraseña temporal.');
    [$code]=authTestRequest($server,'/perfil.php',$staffCookie);authAssert($code===303,'La sesión anterior sobrevive al restablecimiento de contraseña.');
    authReject(fn()=>changeAuthPassword($db,$staff,(int)loadAuthUser($db,$staff)['auth_version'],$personal,$temporary,$temporary));
    [, $home]=authTestRequest($server,'/index.php',$adminCookie);$logoutCsrf=authTestToken($home);
    [$code]=authTestRequest($server,'/logout.php',$adminCookie);authAssert($code===405,'Cerrar sesión permite GET.');
    [$code]=authTestRequest($server,'/logout.php',$adminCookie,['csrf'=>'wrong']);authAssert($code===403,'Cerrar sesión acepta CSRF inválido.');
    [$code]=authTestRequest($server,'/logout.php',$adminCookie,['csrf'=>$logoutCsrf]);authAssert($code===303,'No se cierra la sesión.');
    [$code]=authTestRequest($server,'/index.php',$adminCookie);authAssert($code===303,'La cuenta sigue conectada después de salir.');
    [, $loginForm]=authTestRequest($server,'/login.php',$adminCookie);$anonymousCookie=file_get_contents($adminCookie);
    [$code,,$location,$headers]=authTestRequest($server,'/login.php',$adminCookie,['csrf'=>authTestToken($loginForm),'login'=>$login,'password'=>$password,'next'=>'https://example.com/']);
    authAssert($code===303&&str_ends_with($location,'index.php'),'Login permite redirigir fuera del sistema.');
    authAssert($anonymousCookie!==file_get_contents($adminCookie)&&str_contains(strtolower(implode('',$headers)),'httponly')&&str_contains(strtolower(implode('',$headers)),'samesite=lax'),'La sesión no rota o su cookie no tiene protección.');
    preg_match('/NEXOSESSID\s+([a-zA-Z0-9,-]+)/',file_get_contents($adminCookie),$sessionId);authAssert(!empty($sessionId[1]),'No se conserva la cookie de la sesión.');
    $sessionFile=$server['sessions'].'/sess_'.$sessionId[1];$sessionContents=file_get_contents($sessionFile);
    $sessionContents=preg_replace('/auth_seen\|i:\d+;/','auth_seen|i:'.(time()-1801).';',$sessionContents);file_put_contents($sessionFile,$sessionContents);
    [$code]=authTestRequest($server,'/perfil.php',$adminCookie);authAssert($code===303,'Una sesión inactiva no vence.');
    [, $loginForm]=authTestRequest($server,'/login.php',$adminCookie);
    [$code]=authTestRequest($server,'/login.php',$adminCookie,['csrf'=>authTestToken($loginForm),'login'=>$login,'password'=>$password]);
    authAssert($code===303,'No se puede iniciar otra sesión después del vencimiento.');
    preg_match('/NEXOSESSID\s+([a-zA-Z0-9,-]+)/',file_get_contents($adminCookie),$sessionId);
    $sessionFile=$server['sessions'].'/sess_'.$sessionId[1];$sessionContents=file_get_contents($sessionFile);
    $sessionContents=preg_replace('/auth_started\|i:\d+;/','auth_started|i:'.(time()-28801).';',$sessionContents);
    file_put_contents($sessionFile,$sessionContents);
    [$code]=authTestRequest($server,'/perfil.php',$adminCookie);authAssert($code===303,'Una sesión activa supera el máximo de ocho horas.');
    $state=$db->query('SELECT * FROM auth_state WHERE id=1')->fetch();installAuthentication($db);
    authAssert($db->query('SELECT * FROM auth_state WHERE id=1')->fetch()===$state,'Reinstalar reabre la activación o cambia las claves de acceso.');
    echo "PASS: activación de un uso, login, CSRF, límites, hash, usuarios, contraseña temporal, sesiones, último administrador, páginas y JSON protegidos.\n";
}finally {
    foreach($cookies as $file)if(is_file($file))unlink($file);
    stopAuthTestServer($server);
}

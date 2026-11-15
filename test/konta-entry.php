<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function entryCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$server=startAuthTestServer();$cookies=[];
try{
    $db=$server['db'];$login='konta.qa.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(16));
    $setup=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>'Administrador de pruebas','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'konta-admin-');$cookies[]=$cookie;
    [$code,$html]=authTestRequest($server,'/login.php',$cookie);entryCheck($code===200&&str_contains($html,'Inicia sesión en Konta'),'No abre el nuevo login.');
    entryCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>'invalid','login'=>$login,'password'=>$password])[0]===403,'El login perdió su protección CSRF.');
    [, $html]=authTestRequest($server,'/login.php',$cookie);entryCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'No permite iniciar sesión.');
    [$code,$html]=authTestRequest($server,'/index.php',$cookie);entryCheck($code===200&&str_contains($html,'Panel de Control | Konta')&&str_contains($html,'konta-module-card'),'No abre el panel Konta.');
    entryCheck(str_contains($html,'Ventas Directas')&&str_contains($html,'data-navigation-search-data'),'Se perdieron módulos o búsqueda.');
    entryCheck(!str_contains($html,'Nueva venta rápida'),'El administrador recibe venta rápida sin rol vendedor.');
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->exec('SET @nexo_company_id=1, @nexo_actor_id='.$admin);
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Konta vendedor QA',$types['salesperson']]);$seller=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,salesperson_id) VALUES(?,?,?)')->execute(['Konta cliente propio',$types['client'],$seller]);
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Konta cliente ajeno',$types['client']]);
    $db->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Konta consulta propia QA','Prueba de alcance del contador']);$group=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'contactos.contacts.read','own')")->execute([$group]);
    $limitedLogin='konta.own.'.bin2hex(random_bytes(5));$db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password,contact_id) VALUES(?,?,?,0,?)')->execute(['Consulta propia',$limitedLogin,password_hash($password,PASSWORD_DEFAULT),$seller]);$limited=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$limited,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id,contact_id) VALUES(?,1,?)')->execute([$limited,$seller]);
    $ownCookie=tempnam(sys_get_temp_dir(),'konta-own-');$cookies[]=$ownCookie;[, $html]=authTestRequest($server,'/login.php',$ownCookie);authTestRequest($server,'/login.php',$ownCookie,['csrf'=>authTestToken($html),'login'=>$limitedLogin,'password'=>$password]);
    [$code,$html]=authTestRequest($server,'/index.php',$ownCookie);entryCheck($code===200&&str_contains($html,'1 cliente'),'El contador no respeta el contacto propio.');
    entryCheck(!str_contains($html,'data-module="contabilidad"')&&!str_contains($html,'Revisar cierres')&&!str_contains($html,'Revisar solicitudes'),'Se muestran módulos o acciones no autorizados.');
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;
    $p=proc_open(['node',__DIR__.'/konta-entry-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);entryCheck(proc_close($p)===0,'Falló la revisión de navegador.');
    echo "PASS: autenticación real, CSRF, inicio Konta, módulos y acciones por permisos, contador de clientes propios y navegación móvil/escritorio.\n";
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($server);}

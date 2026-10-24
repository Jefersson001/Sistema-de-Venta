<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function settingsAccessCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
echo "RUN isolated settings/access navigation\n";$s=startAuthTestServer();$cookies=[];$fixtures=null;
try{
    $db=$s['db'];$password=bin2hex(random_bytes(12));$login='settings.'.bin2hex(random_bytes(5));$setup=prepareFirstAdministrator($db);
    createFirstAdministrator($db,['name'=>'Administrador de Ajustes','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'nexo-settings-admin-');$cookies[]=$cookie;$r=authTestRequest($s,'/login.php',$cookie);$r=authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password]);settingsAccessCheck($r[0]===303,'No inicia sesión el administrador.');
    $views=[];
    foreach(['general'=>'/modules/ajustes/index.php','restaurant'=>'/modules/ajustes/index.php?section=restaurante','group'=>'/modules/ajustes/grupos.php?new=1'] as $name=>$route){
        $r=authTestRequest($s,$route,$cookie);settingsAccessCheck($r[0]===200,'No abre '.$name);$views[$name]=$r[1];
    }
    settingsAccessCheck(str_contains($views['general'],'Usuarios y accesos')&&str_contains($views['general'],'section=restaurante'),'Faltan secciones actualizadas.');
    settingsAccessCheck(str_contains($views['restaurant'],'Permitir vuelto')&&str_contains($views['group'],'Productos y variantes'),'Faltan las opciones nuevas de cobro o variantes.');
    echo "RUN permission-filtered settings and protected direct sections\n";
    $db->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Consulta de Ajustes '.bin2hex(random_bytes(5)),'Sin accesos operativos']);$group=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'ajustes.general.read','all')")->execute([$group]);
    $readerLogin='settings.reader.'.bin2hex(random_bytes(5));
    $db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Consulta de Ajustes',$readerLogin,password_hash($password,PASSWORD_DEFAULT)]);$reader=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$reader,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$reader]);
    $limited=tempnam(sys_get_temp_dir(),'nexo-settings-reader-');$cookies[]=$limited;$r=authTestRequest($s,'/login.php',$limited);$r=authTestRequest($s,'/login.php',$limited,['csrf'=>authTestToken($r[1]),'login'=>$readerLogin,'password'=>$password]);settingsAccessCheck($r[0]===303,'No inicia sesión el lector.');
    $r=authTestRequest($s,'/modules/ajustes/index.php',$limited);settingsAccessCheck($r[0]===200&&!str_contains($r[1],'section=restaurante')&&!str_contains($r[1],'section=accesos'),'Se ofrecen secciones sin opciones autorizadas.');
    settingsAccessCheck(authTestRequest($s,'/modules/ajustes/index.php?section=restaurante',$limited)[0]===403,'La sección directa no respeta sus accesos.');
    if(isset($argv[1])){
        $fixtures=sys_get_temp_dir().'/nexo-settings-fixtures-'.bin2hex(random_bytes(5));mkdir($fixtures,0700);
        $css=preg_replace('/^@import[^\r\n]+[\r\n]*/m','',file_get_contents(dirname(__DIR__).'/css/app.css')).file_get_contents(dirname(__DIR__).'/css/modules.css');
        $js=file_get_contents(dirname(__DIR__).'/js/app.js')."\n".file_get_contents(dirname(__DIR__).'/js/modules.js')."\n".file_get_contents(dirname(__DIR__).'/js/navigation.js')."\n".file_get_contents(dirname(__DIR__).'/js/access-groups.js');
        foreach($views as $name=>$html){
            $html=preg_replace('~<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>|<link\b[^>]*rel="stylesheet"[^>]*>~i','',$html);
            $html=str_replace('</head>','<style>'.$css.'</style></head>',$html);$html=str_replace('</body>','<script>'.$js.'</script></body>',$html);file_put_contents($fixtures.'/'.$name.'.html',$html);
        }
        $p=proc_open(['node',__DIR__.'/settings-access-ui.js',$fixtures,$argv[1]],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);settingsAccessCheck(proc_close($p)===0,'Falló la revisión de Ajustes en navegador.');
    }
    echo "PASS settings updates: new sections, shared variant permissions, restaurant options and permission-filtered navigation.\n";
}finally{
    foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);
    if($fixtures){foreach(glob($fixtures.'/*.html') as $file)unlink($file);rmdir($fixtures);}stopAuthTestServer($s);
}

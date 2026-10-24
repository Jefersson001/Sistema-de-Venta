<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/restaurant-schema.php';
require_once dirname(__DIR__).'/php/restaurant-controller.php';
require_once dirname(__DIR__).'/php/company-connection.php';
require_once dirname(__DIR__).'/php/company-repository.php';
function restaurantCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function restaurantReject(callable $work,string $message): void
{try{$work();}catch(RuntimeException|LogicException){return;}throw new RuntimeException($message);}
function restaurantTestDb(array $server,int $actor,int $company=1): CompanyConnection
{
    $c=require dirname(__DIR__).'/php/config/database.php';$tables=$server['db']->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    return new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$server['database'].';charset=utf8mb4',$c['username'],$c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[$company],$company,$actor,$tables);
}
function restaurantRace(array $server,int $actor,int $table,int $version,array $keys): array
{
    $workers=[];$env=getenv();$env['NEXO_DATABASE_NAME']=$server['database'];
    try {
        foreach($keys as $key){
            $process=proc_open([PHP_BINARY,__DIR__.'/restaurant-worker.php',(string)$actor,(string)$table,(string)$version,$key],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
            if(!is_resource($process))throw new RuntimeException('No se inició el trabajador.');
            $workers[]=[$process,$pipes];restaurantCheck(trim((string)fgets($pipes[1]))==='READY','El trabajador no inició.');
        }
        foreach($workers as [$process,$pipes]){fwrite($pipes[0],"GO\n");fclose($pipes[0]);}
        $results=[];
        foreach($workers as [$process,$pipes]){
            $results[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            restaurantCheck(proc_close($process)===0,'Falló el trabajador: '.$errors);
        }
        $workers=[];return $results;
    }finally{foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
}
echo "RUN restaurant isolated migration\n";
$server=startAuthTestServer();$cookies=[];$fixtures=null;
try {
    echo "RUN temporary administrator\n";
    $raw=$server['db'];$login='rest.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));
    $token=prepareFirstAdministrator($raw);$actor=createFirstAdministrator($raw,['name'=>'Encargado de restaurante','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    echo "RUN restaurant schema installation\n";
    installRestaurant($raw,static fn($step)=>print('SCHEMA '.$step.PHP_EOL));
    $grants=$raw->query('SELECT group_id,permission_code,record_scope FROM access_group_permissions ORDER BY group_id,permission_code')->fetchAll();
    installRestaurant($raw,static fn($step)=>print('REINSTALL '.$step.PHP_EOL));restaurantCheck($grants===$raw->query('SELECT group_id,permission_code,record_scope FROM access_group_permissions ORDER BY group_id,permission_code')->fetchAll(),'La migración repetida cambió permisos.');
    echo "RUN areas, tables and account lifecycle\n";
    $db=restaurantTestDb($server,$actor);
    $prefix='Restaurante test '.bin2hex(random_bytes(3));
    $area=restaurantSaveArea($db,['name'=>$prefix,'sort_order'=>1,'active'=>1]);
    $newTable=static fn(string $name,int $x)=>restaurantSaveTable($db,['area_id'=>$area,'name'=>$name,'capacity'=>4,'position_x'=>$x,'position_y'=>1,'active'=>1]);
    $one=$newTable('Mesa uno',1);$two=$newTable('Mesa dos',2);$three=$newTable('Mesa tres',3);$four=$newTable('Mesa cuatro',4);
    restaurantReject(fn()=>$newTable('Mesa duplicada',1),'Se permitió una posición duplicada.');
    restaurantReject(fn()=>uninstallEmptyRestaurant($raw),'Se permitió borrar historial.');
    $open=['table_id'=>$one,'table_version'=>1,'waiter_id'=>$actor,'guests'=>2,'notes'=>'Alergias consultadas','request_key'=>bin2hex(random_bytes(32))];
    $account=restaurantOpenAccount($db,$open);
    restaurantCheck(restaurantOpenAccount($db,$open)===$account,'Reintento creó otra cuenta.');
    restaurantReject(fn()=>restaurantOpenAccount($db,array_replace($open,['notes'=>'Otro contenido'])),'La clave aceptó otro contenido.');
    restaurantReject(fn()=>restaurantOpenAccount($db,array_replace($open,['request_key'=>bin2hex(random_bytes(32))])),'Se abrió una mesa ocupada.');
    restaurantReject(fn()=>restaurantSaveArea($db,['name'=>$prefix,'sort_order'=>1,'active'=>0,'version'=>1],$area),'Se desactivó un área ocupada.');
    restaurantReject(fn()=>restaurantSaveTable($db,['area_id'=>$area,'name'=>'Cambiada','capacity'=>4,'position_x'=>1,'position_y'=>1,'active'=>0,'version'=>2],$one),'Se modificó una mesa ocupada.');
    $command=static fn(string $action,int $version,int $table)=>['action'=>$action,'version'=>$version,'table_id'=>$table,'reason'=>'Prueba de atención','request_key'=>bin2hex(random_bytes(32))];
    $join=$command('attach_table',1,$two);restaurantAccountAction($db,$account,$join);restaurantAccountAction($db,$account,$join);
    restaurantCheck(count(restaurantRows($db,'SELECT table_id FROM restaurant_active_tables WHERE account_id=?',[$account]))===2,'No unió dos mesas sin duplicar.');
    restaurantReject(fn()=>restaurantAccountAction($db,$account,$command('move_account',1,$three)),'Se aceptó versión obsoleta.');
    restaurantAccountAction($db,$account,$command('detach_table',2,$two));
    restaurantReject(fn()=>restaurantAccountAction($db,$account,$command('detach_table',3,$one)),'Se separó la última mesa.');
    restaurantAccountAction($db,$account,$command('move_account',3,$three));
    restaurantCheck(restaurantRecord($db,'restaurant_tables',$one)['service_state']==='cleaning','El traslado no marcó limpieza.');
    restaurantAccountAction($db,$account,$command('close_empty_account',4,0));
    restaurantCheck(restaurantRecord($db,'restaurant_accounts',$account)['status']==='closed','No se cerró la cuenta vacía.');
    restaurantReject(fn()=>restaurantOpenAccount($db,array_replace($open,['table_version'=>3,'request_key'=>bin2hex(random_bytes(32))])),'Se abrió una mesa sin limpiar.');
    restaurantChangeService($db,$one,['version'=>3,'service_state'=>'free','reason'=>'Limpieza completada']);
    restaurantCheck(restaurantRecord($db,'restaurant_tables',$one)['service_state']==='free','No se completó limpieza.');
    echo "RUN concurrent openings and lost-response retries\n";
    $race=restaurantRace($server,$actor,$four,1,[bin2hex(random_bytes(32)),bin2hex(random_bytes(32))]);
    restaurantCheck(count(array_filter($race,static fn($v)=>str_starts_with($v,'OPENED ')))===1&&count(array_filter($race,static fn($v)=>str_starts_with($v,'REJECTED ')))===1,'Dos usuarios abrieron cuentas duplicadas: '.implode(',',$race));
    $five=$newTable('Mesa cinco',5);$same=bin2hex(random_bytes(32));$race=restaurantRace($server,$actor,$five,1,[$same,$same]);
    restaurantCheck($race[0]===$race[1]&&str_starts_with($race[0],'OPENED '),'El reintento concurrente no devolvió la cuenta original.');
    echo "RUN company isolation\n";
    $otherCompany=createBusinessCompany($raw,['name'=>'Restaurante empresa aislada'],$actor);$other=restaurantTestDb($server,$actor,$otherCompany);
    $GLOBALS['company_context']=['active'=>$otherCompany];$otherArea=restaurantSaveArea($other,['name'=>'Área ajena','active'=>1]);
    restaurantReject(fn()=>restaurantSaveTable($other,['area_id'=>$area,'name'=>'Ajena','capacity'=>2,'active'=>1]),'Se admitió área de otra empresa.');
    restaurantReject(fn()=>restaurantRecord($db,'restaurant_areas',$otherArea),'Se filtró otra empresa.');
    unset($GLOBALS['company_context']);
    echo "RUN authenticated routes, permissions and CSRF\n";
    $cookie=tempnam(sys_get_temp_dir(),'nexo-rest-cookie-');$cookies[]=$cookie;
    $r=authTestRequest($server,'/login.php',$cookie);$r=authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password]);restaurantCheck($r[0]===303,'Falló el login.');
    $routes=['map'=>'/modules/restaurante/index.php','configuration'=>'/modules/restaurante/configuracion.php?section=restaurant_tables','new'=>'/modules/restaurante/cuenta.php?new=1&table_id='.$one,'account'=>'/modules/restaurante/cuenta.php?id='.$account,'accounts'=>'/modules/restaurante/cuentas.php','table'=>'/modules/restaurante/mesa.php?id='.$one];
    $rendered=[];
    foreach($routes as $name=>$route){echo 'HTTP '.$name.PHP_EOL;$r=authTestRequest($server,$route,$cookie);restaurantCheck($r[0]===200,'No abre '.$route.' '.$r[0]);$rendered[$name]=$r[1];}
    $r=authTestRequest($server,$routes['new'],$cookie,['csrf'=>'invalid','action'=>'save_account']);restaurantCheck($r[0]===403,'No se rechazó CSRF.');
    $r=authTestRequest($server,$routes['new'],$cookie,['csrf'=>authTestToken($rendered['new']),'company_scope'=>'invalid','action'=>'save_account']);restaurantCheck($r[0]===409,'No se rechazó contexto obsoleto.');
    $r=authTestRequest($server,$routes['new'],$cookie,['csrf'=>authTestToken($rendered['new']),'action'=>'save_account','table_id'=>$one,'table_version'=>4,'waiter_id'=>$actor,'guests'=>2,'notes'=>'HTTP','request_key'=>bin2hex(random_bytes(32))]);
    restaurantCheck($r[0]===303,'No abrió cuenta por HTTP: '.$r[0].' '.strip_tags($r[1]));
    $r=authTestRequest($server,'/modules/restaurante/configuracion.php?section=restaurant_areas&new=1',$cookie,['csrf'=>authTestToken($rendered['new']),'action'=>'save_restaurant_area','name'=>'Área HTTP','sort_order'=>2,'active'=>1]);restaurantCheck($r[0]===303,'No creó área por HTTP.');
    $raw->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Mesero de prueba','Acceso propio']);$group=(int)$raw->lastInsertId();
    foreach(['restaurante.tables.read'=>'all','restaurante.accounts.read'=>'own','restaurante.accounts.create'=>'own','restaurante.accounts.edit'=>'own'] as $code=>$scope)$raw->prepare('INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)')->execute([$group,$code,$scope]);
    $waiterLogin='waiter.'.bin2hex(random_bytes(5));
    $raw->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Mesero limitado',$waiterLogin,password_hash($password,PASSWORD_DEFAULT)]);$waiter=(int)$raw->lastInsertId();
    $raw->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$waiter,$group]);$raw->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$waiter]);
    $limited=tempnam(sys_get_temp_dir(),'nexo-rest-waiter-');$cookies[]=$limited;
    $r=authTestRequest($server,'/login.php',$limited);$r=authTestRequest($server,'/login.php',$limited,['csrf'=>authTestToken($r[1]),'login'=>$waiterLogin,'password'=>$password]);restaurantCheck($r[0]===303,'Falló login de mesero.');
    $r=authTestRequest($server,$routes['account'],$limited);restaurantCheck($r[0]===403,'El mesero vio otra cuenta.');
    $r=authTestRequest($server,$routes['configuration'],$limited);restaurantCheck($r[0]===403,'El mesero accedió a configuración.');
    $r=authTestRequest($server,$routes['map'],$limited);restaurantCheck($r[0]===200&&!str_contains($r[1],'Alergias consultadas'),'El mapa filtró notas ajenas.');
    $six=$newTable('Mesa seis',6);
    $path='/modules/restaurante/cuenta.php?new=1&table_id='.$six;
    $r=authTestRequest($server,$path,$limited);restaurantCheck($r[0]===200,'Mesero sin contacto no puede abrir cuenta propia.');
    $csrf=authTestToken($r[1]);$r=authTestRequest($server,$path,$limited,['csrf'=>$csrf,'action'=>'save_account','table_id'=>$six,'table_version'=>1,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);restaurantCheck($r[0]===403,'Mesero asignó una cuenta ajena.');
    $r=authTestRequest($server,$path,$limited,['csrf'=>$csrf,'action'=>'save_account','table_id'=>$six,'table_version'=>1,'waiter_id'=>$waiter,'guests'=>1,'notes'=>'Cuenta del mesero','request_key'=>bin2hex(random_bytes(32))]);restaurantCheck($r[0]===303,'Mesero no abrió su cuenta.');
    $own=(int)$raw->query('SELECT MAX(id) FROM restaurant_accounts')->fetchColumn();$r=authTestRequest($server,'/modules/restaurante/cuenta.php?id='.$own,$limited);
    restaurantCheck($r[0]===200&&!str_contains($r[1],'value="close_empty_account"'),'El permiso propio o la presentación de cierre falló.');
    $r=authTestRequest($server,'/modules/restaurante/cuenta.php?id='.$own,$limited,['csrf'=>$csrf,'action'=>'close_empty_account','version'=>1,'reason'=>'Sin permiso','request_key'=>bin2hex(random_bytes(32))]);restaurantCheck($r[0]===403,'El mesero cerró sin permiso.');
    restaurantCheck((int)$raw->query('SELECT COUNT(*) FROM restaurant_audit_events WHERE created_by IS NULL')->fetchColumn()===0,'Falta autoría.');
    if(isset($argv[1])){
        $db->prepare('INSERT INTO inventory_categories(name,restaurant_enabled) VALUES(?,1)')->execute(['Carta de prueba '.bin2hex(random_bytes(5))]);$previewCategory=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,product_type,currency_code,category_id) VALUES('Hamburguesa especial',1,8,'service','USD',?)")->execute([$previewCategory]);$previewProduct=(int)$db->lastInsertId();
        restaurantCartCommand($db,$own,['action'=>'pos_add','product_id'=>$previewProduct,'version'=>1,'request_key'=>bin2hex(random_bytes(32))]);
        $routes['pos']='/modules/restaurante/cuenta.php?id='.$own;
        $freeFixture=restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa disponible','capacity'=>4,'position_x'=>1,'position_y'=>2,'active'=>1]);
        $routes['new']='/modules/restaurante/cuenta.php?new=1&table_id='.$freeFixture;
        $fixtures=sys_get_temp_dir().'/nexo-restaurant-fixtures-'.bin2hex(random_bytes(6));mkdir($fixtures,0700);
        $css=preg_replace('/^@import[^\r\n]+[\r\n]*/m','',file_get_contents(dirname(__DIR__).'/css/app.css')).file_get_contents(dirname(__DIR__).'/css/modules.css').file_get_contents(dirname(__DIR__).'/css/restaurant.css');
        $js=file_get_contents(dirname(__DIR__).'/js/app.js')."\n".file_get_contents(dirname(__DIR__).'/js/modules.js')."\n".file_get_contents(dirname(__DIR__).'/js/navigation.js')."\n".file_get_contents(dirname(__DIR__).'/js/restaurant.js');
        foreach($routes as $name=>$route){echo 'RENDER '.$name.PHP_EOL;$r=authTestRequest($server,$route,$cookie);restaurantCheck($r[0]===200,'No se pudo preparar la vista '.$name);$html=preg_replace('~<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>|<link\b[^>]*rel="stylesheet"[^>]*>~i','',$r[1]);$html=str_replace('</head>','<style>'.$css.'</style></head>',$html);$html=str_replace('</body>','<script>'.$js.'</script></body>',$html);file_put_contents($fixtures.'/'.$name.'.html',$html);}
        $p=proc_open(['node',__DIR__.'/restaurant-ui.js',$fixtures,$argv[1]],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);restaurantCheck(proc_close($p)===0,'Falló el navegador.');
    }
    echo "PASS restaurant: migration, accounts, tables, concurrency, retries, isolation, permissions and HTTP.\n";
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");throw $e;}
finally{
    foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);
    if($fixtures){foreach(glob($fixtures.'/*.html') as $file)unlink($file);rmdir($fixtures);}
    unset($GLOBALS['company_context']);stopAuthTestServer($server);
}

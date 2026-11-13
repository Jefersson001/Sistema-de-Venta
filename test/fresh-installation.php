<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/php/fresh-installation.php';
require_once dirname(__DIR__).'/php/migration-runner.php';
require_once __DIR__.'/auth-server.php';
function freshExpect(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db=createDatabaseConnection(true);
$source=(string)$db->query('SELECT DATABASE()')->fetchColumn();
$name='nexo_fresh_test_'.bin2hex(random_bytes(8));
if (!preg_match('/^nexo_fresh_test_[a-f0-9]{16}$/D',$name)) throw new RuntimeException('Base temporal inválida.');
$backups=[];
$process=null;$sessionDirectory=null;$cookie=null;$serverLog=null;
$progress=static function(string $message) use (&$backups): void {
    if (preg_match('/^Copia de la tabla parcial: (.*?) \(conservar/',$message,$m)) $backups[]=$m[1];
    echo $message.PHP_EOL;
};
try {
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $db->exec('USE `'.$name.'`');
    $db->exec('CREATE TABLE unrelated(id INT PRIMARY KEY)');
    $rejected=false;
    try { installFreshApplication($db,$progress); } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'contiene tablas'); }
    freshExpect($rejected,'Una base existente no fue rechazada.');
    freshExpect((bool)$db->query("SHOW TABLES LIKE 'unrelated'")->fetch(),'Se borró una tabla ajena.');
    $db->exec('DROP TABLE unrelated');
    $db->exec('CREATE TABLE access_groups(id INT PRIMARY KEY,name VARCHAR(120))');
    $db->exec("INSERT INTO access_groups VALUES(1,'Importación parcial QA')");
    $interrupted=false;
    try {
        installFreshApplication($db,static function(string $message) use ($progress): void {
            $progress($message);
            if ($message==='Instalando: multi_company') throw new RuntimeException('Interrupción simulada QA');
        },true);
    } catch (RuntimeException $e) {
        if ($e->getMessage()!=='Interrupción simulada QA') throw $e;
        $interrupted=true;
    }
    freshExpect($interrupted,'No se alcanzó la prueba de reanudación.');
    freshExpect(count($backups)===1 && is_file($backups[0]),'No se conservó la tabla parcial.');
    $snapshot=json_decode(file_get_contents($backups[0]),true,512,JSON_THROW_ON_ERROR);
    freshExpect($snapshot['rows'][0]['name']==='Importación parcial QA','El respaldo parcial perdió datos.');
    installFreshApplication($db,$progress);
    migrationAssertApplicationReady($db);
    freshExpect(runVersionedMigrations($db,$progress)===count(migrationFiles()),'No se registraron todas las migraciones de la instalación inicial.');
    freshExpect(runVersionedMigrations($db,$progress)===0,'Se repitió la migración inicial.');
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['auth_state','company_schema_state','contact_addresses','payment_proofs','inventory_lots','restaurant_accounts','business_record_actions'] as $table) {
        freshExpect(in_array($table,$tables,true),'Falta la tabla '.$table);
    }
    foreach (['auth_users','contacts','inventory_products','sales_quotations','purchase_orders','accounting_invoices','accounting_payments','restaurant_accounts','accounting_currency_rates'] as $table) {
        freshExpect((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'Se crearon datos ficticios en '.$table);
    }
    freshExpect((int)$db->query('SELECT version FROM company_schema_state WHERE id=1')->fetchColumn()===1,'Multiempresa incompleta.');
    freshExpect((bool)$db->query("SELECT 1 FROM accounting_payment_terms WHERE name='Contado'")->fetchColumn(),'Falta el plazo Contado.');
    $token=prepareFirstAdministrator($db);
    freshExpect(authBootstrapValid($db,hash('sha256',$token)),'El enlace de activación no es válido.');
    $password=bin2hex(random_bytes(12));
    // Serve the real application at the domain root, as in Dokploy, on an isolated port/session directory.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException('No se pudo reservar el puerto de prueba.');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $sessionDirectory=sys_get_temp_dir().'/vent-as-fresh-sessions-'.bin2hex(random_bytes(8));mkdir($sessionDirectory,0700);
    $cookie=tempnam(sys_get_temp_dir(),'fresh-cookie-');$serverLog=tempnam(sys_get_temp_dir(),'fresh-server-');
    $env=getenv();$env['NEXO_DATABASE_NAME']=$name;$env['NEXO_BASE_PATH']='/';
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$sessionDirectory,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('No se inició el servidor de prueba.');
    fclose($pipes[0]);$server=['url'=>'http://127.0.0.1:'.$port];
    for ($i=0;$i<100;$i++) {
        $curl=curl_init($server['url'].'/login.php');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>300]);curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if ($code===200) break;usleep(100000);
    }
    freshExpect($code===200,'El login no respondió: '.file_get_contents($serverLog));
    [$status,,$location]=authTestRequest($server,'/activar.php?token='.$token,$cookie);
    freshExpect($status===303 && str_ends_with($location,'/activar.php'),'No se abrió el enlace de activación.');
    [, $form]=authTestRequest($server,'/activar.php',$cookie);
    [$status,$body]=authTestRequest($server,'/activar.php',$cookie,['csrf'=>authTestToken($form),'name'=>'Fresh QA','login'=>'fresh.qa','password'=>$password,'password_confirmation'=>$password]);
    freshExpect($status===303,'No se pudo crear el administrador por HTTP: '.mb_substr(strip_tags($body),-700));
    $id=(int)$db->query("SELECT id FROM auth_users WHERE login='fresh.qa'")->fetchColumn();
    $user=loadAuthUser($db,$id);
    freshExpect($user['administrator'] && !$user['must_change_password'],'La primera cuenta no es administradora.');
    freshExpect(authAttemptLogin($db,'fresh.qa',$password,'127.0.0.1')['user']!==null,'No se puede iniciar sesión.');
    foreach (['/','/index.php','/modules/ajustes/index.php','/modules/ajustes/usuarios.php','/modules/ajustes/grupos.php',
        '/modules/contactos/index.php','/modules/inventario/index.php','/modules/ventas/index.php',
        '/modules/compras/index.php','/modules/contabilidad/index.php','/modules/dashboard/index.php','/modules/restaurante/index.php',
        '/modules/venta_directa/inventario.php','/modules/venta_directa/solicitudes.php'] as $path) {
        [$status,$body]=authTestRequest($server,$path,$cookie);
        freshExpect($status===200 && str_contains($body,'</html>'),'Falló la vista '.$path.' HTTP '.$status.' '.mb_substr(strip_tags($body),-400));
    }
    [, $home]=authTestRequest($server,'/index.php',$cookie);
    freshExpect(!str_contains($home,'/project/'),'La instalación raíz conserva rutas locales.');
    $db->exec("UPDATE business_company SET name='Empresa personalizada QA' WHERE id=1");
    installFreshApplication($db,$progress);
    freshExpect((int)$db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()===1,'Se duplicó el administrador.');
    freshExpect($db->query('SELECT name FROM business_company WHERE id=1')->fetchColumn()==='Empresa personalizada QA','Repetir sobrescribió la configuración.');
    echo 'PASS: base vacía, rechazo de tablas ajenas, respaldo parcial, reanudación, activación HTTP, login, 14 vistas en raíz y repetición sin sobrescribir. Tablas: '.count($tables).PHP_EOL;
} finally {
    if (is_resource($process)) { proc_terminate($process,9);proc_close($process); }
    foreach ([$cookie,$serverLog] as $file) if ($file && is_file($file)) unlink($file);
    if ($sessionDirectory && is_dir($sessionDirectory)
        && dirname(realpath($sessionDirectory))===realpath(sys_get_temp_dir())
        && preg_match('/^vent-as-fresh-sessions-[a-f0-9]{16}$/D',basename($sessionDirectory))) {
        foreach (glob($sessionDirectory.'/sess_*') as $file) if (is_file($file)) unlink($file);
        rmdir($sessionDirectory);
    }
    $db->exec('USE information_schema');
    $db->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    foreach ($backups as $file) if (is_file($file)) unlink($file);
}

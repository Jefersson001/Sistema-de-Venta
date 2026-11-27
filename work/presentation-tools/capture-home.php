<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/test/auth-server.php';
$server=startAuthTestServer();
try {
    $db=$server['db'];$token=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(16));$login='konta.demo.'.bin2hex(random_bytes(5));
    $actor=createFirstAdministrator($db,['name'=>'Equipo Konta','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$actor);
    $db->exec("UPDATE business_company SET name='Empresa Demo' WHERE id=1");
    $env=getenv();$env['NEXO_DEMO_URL']=$server['url'];$env['NEXO_DEMO_LOGIN']=$login;$env['NEXO_DEMO_PASSWORD']=$password;
    $p=proc_open(['node',__DIR__.'/capture-home.cjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__,2),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('No se pudo capturar la demostración.');
} finally {stopAuthTestServer($server);}

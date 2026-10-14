<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
$allowed=['business-settings','access-groups','commerce-workflows','commerce-partials','accounting-payments','treasury','dashboard','cash-flow',
    'sales-commissions','document-statuses','currency-catalog','contacts-http','contact-storage','inventory-products','inventory-categories',
    'inventory-operations','inventory-storage','sales-quotations','product-taxes','product-purchase-indicator'];
$tests=array_slice($argv,1);if(!$tests)$tests=['business-settings','access-groups','commerce-workflows','accounting-payments','treasury','dashboard'];
foreach($tests as &$name){$name=preg_replace('/\.php$/','',$name);if(!in_array($name,$allowed,true))throw new InvalidArgumentException('Prueba no permitida. Usa el nombre de la prueba sin directorio.');}unset($name);
$server=startAuthTestServer();
try {
    $login='test.admin.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));$token=prepareFirstAdministrator($server['db']);
    createFirstAdministrator($server['db'],['name'=>'Administrador de pruebas','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $env=getenv();$env['NEXO_DATABASE_NAME']=$server['database'];$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;
    foreach($tests as $name) {
        $outputFile=tempnam(__DIR__,'.auth-test-output-');$errorFile=tempnam(__DIR__,'.auth-test-error-');
        $process=proc_open([PHP_BINARY,__DIR__.'/'.$name.'.php'],[0=>['pipe','r'],1=>['file',$outputFile,'a'],2=>['file',$errorFile,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($process))throw new RuntimeException('No se inició '.$name);fclose($pipes[0]);echo 'RUN '.$name."\n";
        try {
            $offset=0;$deadline=microtime(true)+120;
            do {
                $status=proc_get_status($process);$output=file_get_contents($outputFile);
                if(strlen($output)>$offset){echo substr($output,$offset);$offset=strlen($output);}
                if(!$status['running'])break;
                if(microtime(true)>$deadline){proc_terminate($process,9);throw new RuntimeException('Se agotó el tiempo de '.$name);}
                usleep(100000);
            }while(true);
            $closed=proc_close($process);$exit=$status['exitcode']>=0?$status['exitcode']:$closed;
            if($exit!==0){fwrite(STDERR,file_get_contents($errorFile));throw new RuntimeException('Falló '.$name);}
        }finally {if(is_resource($process)){proc_terminate($process,9);proc_close($process);}unlink($outputFile);unlink($errorFile);}
    }
    echo "PASS: regresión con sesión administradora y datos aislados; sin credenciales ni usuarios creados en el negocio.\n";
}finally{stopAuthTestServer($server);}

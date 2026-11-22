<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/document-share-errors.php';
$log=tempnam(sys_get_temp_dir(),'share-errors-');$oldLog=ini_get('error_log');ini_set('error_log',$log);
try{
    $secret='private-token-credential-sql-document';
    foreach(['encryption'=>'NEXO_MAIL_ENCRYPTION_KEY','pdf'=>'PDF','storage'=>'base de datos','recovery'=>'misma clave'] as $stage=>$expected){
        $message=documentShareFailure(new DocumentSharePreparationException($stage,new RuntimeException($secret)));
        if(!str_contains($message,$expected)||!preg_match('/Referencia: [a-f0-9]{12}\./',$message)||str_contains($message,$secret))throw new RuntimeException('Mensaje incorrecto para '.$stage);
    }
    $sql=new PDOException($secret);$sql->errorInfo=['HY000',1153,$secret];documentShareFailure(new DocumentSharePreparationException('storage',$sql));documentShareFailure(new RuntimeException($secret));
    $logged=file_get_contents($log);
    if(str_contains($logged,$secret)||!str_contains($logged,'sqlstate=HY000 driver_code=1153')||substr_count($logged,'[WhatsApp ')!==6)throw new RuntimeException('Registro inseguro o incompleto.');
    $env=getenv();$env['NEXO_MAIL_ENCRYPTION_KEY']=$secret;$env['NEXO_PUBLIC_URL']='https://example.test';
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/php/check-document-sharing.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    if($code!==1||str_contains($output.$stderr,$secret)||!str_contains($output,'REVISAR: NEXO_MAIL_ENCRYPTION_KEY'))throw new RuntimeException('Diagnóstico de clave inválida incorrecto.');
    echo "PASS: errores diferenciados, referencia de diagnóstico, SQLSTATE sin SQL ni secretos y comprobación CLI sin exponer claves.\n";
}finally{ini_set('error_log',$oldLog);unlink($log);}

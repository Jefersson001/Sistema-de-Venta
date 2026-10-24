<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/receipt-snapshot.php';
$credentials=null;$error=null;$failed=false;
try{
    $config=require __DIR__.'/config/database.php';$db=createDatabaseConnection(true);
    $directory=__DIR__.'/backups';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    $backup=$directory.'/before-review-fixes-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
    $credentials=tempnam(sys_get_temp_dir(),'nexo-review-backup-');$error=tempnam(sys_get_temp_dir(),'nexo-review-error-');
    $quote=static fn($value)=>'"'.str_replace(['\\','"',"\n","\r"],['\\\\','\\"','\\n','\\r'],(string)$value).'"';
    if(file_put_contents($credentials,"[client]\nhost=".$quote($config['host'])."\nport=".(int)$config['port']."\nuser=".$quote($config['username'])."\npassword=".$quote($config['password'])."\n")===false)throw new RuntimeException('No se pudo preparar el respaldo.');
    chmod($credentials,0600);
    $process=proc_open([dirname(PHP_BINARY,2).'/mysql/bin/mysqldump.exe','--defaults-extra-file='.$credentials,'--single-transaction','--routines','--triggers',$config['database']],
        [0=>['pipe','r'],1=>['file',$backup,'w'],2=>['file',$error,'w']],$pipes,__DIR__,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($process))throw new RuntimeException('No se pudo iniciar el respaldo.');fclose($pipes[0]);
    if(proc_close($process)!==0||!is_file($backup)||filesize($backup)<100)throw new RuntimeException('El respaldo falló; la migración no se inició.');
    $count=installReceiptSnapshots($db);
    echo 'Datos de comprobantes conservados: '.$count.'. Respaldo: '.$backup.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);$failed=true;}
finally{foreach([$credentials,$error] as $file)if($file&&is_file($file))unlink($file);}
if($failed)exit(1);

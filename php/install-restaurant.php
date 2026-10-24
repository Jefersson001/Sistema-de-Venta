<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/restaurant-schema.php';
try {
    $db=createDatabaseConnection(true);$config=require __DIR__.'/config/database.php';
    $directory=__DIR__.'/backups';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('No se pudo crear el directorio de respaldo.');
    $backup=$directory.'/before-restaurant-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
    $binary=dirname(PHP_BINARY,2).'/mysql/bin/mysqldump.exe';
    $args=[$binary,'--host='.$config['host'],'--port='.$config['port'],'--user='.$config['username'],
        '--single-transaction','--routines','--triggers','--hex-blob','--result-file='.$backup,$config['database']];
    if($config['password']!=='')array_splice($args,4,0,['--password='.$config['password']]);
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($process))throw new RuntimeException('No se pudo iniciar el respaldo.');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($process)!==0||!is_file($backup)||filesize($backup)<100)throw new RuntimeException('El respaldo falló; no se inició la migración. '.$error);
    echo 'Respaldo: '.$backup.PHP_EOL;
    if(in_array('--rollback-empty',$argv,true)) {uninstallEmptyRestaurant($db);echo "Instalación vacía retirada. Los permisos se conservan para no modificar grupos.\n";}
    else {installRestaurant($db);echo "Restaurante instalado: áreas, mesas y cuentas. No se crearon datos de ejemplo.\n";}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/connection.php';
require_once __DIR__.'/two-decimals-schema.php';

try {
    $config = require __DIR__.'/config/database.php';
    $directory = __DIR__.'/backups';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    $backup = $directory.'/before-two-decimals-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    $dump = dirname(dirname(PHP_BINARY)).'/mysql/bin/mysqldump.exe';
    if (!is_file($dump)) throw new RuntimeException('No se encontró mysqldump. No se modificó la base de datos.');
    $command = [$dump,'--host='.$config['host'],'--port='.$config['port'],'--user='.$config['username'],
        '--single-transaction','--routines','--triggers','--hex-blob','--result-file='.$backup,$config['database']];
    $environment = getenv(); $environment['MYSQL_PWD'] = $config['password'];
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el respaldo.');
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]); $status=proc_close($process);
    if ($status!==0 || !is_file($backup) || filesize($backup)===0) throw new RuntimeException('Falló el respaldo. No se modificó la base de datos. '.$error);
    echo 'Respaldo: '.$backup.PHP_EOL;
    $changes = installTwoDecimals(createDatabaseConnection(true));
    foreach ($changes as $change) echo $change['table'].'.'.$change['field'].': 2 decimales; '.$change['rounded_rows'].' valores redondeados.'.PHP_EOL;
    echo count($changes).' columnas migradas.'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }

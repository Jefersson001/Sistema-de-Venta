<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/company-schema.php';
try {
    $db=createDatabaseConnection(true);
    $snapshots=[];
    $fingerprint=static function(PDO $db,string $table,array $columns,array $order): string {
        $hash=hash_init('sha256');
        $sql='SELECT '.implode(',',array_map('companyQuoteIdentifier',$columns)).' FROM '.companyQuoteIdentifier($table);
        if($order)$sql.=' ORDER BY '.implode(',',array_map('companyQuoteIdentifier',$order));
        $rows=$db->query($sql);while($row=$rows->fetch(PDO::FETCH_NUM))hash_update($hash,serialize($row));
        return hash_final($hash);
    };
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if(str_starts_with($table,'company_schema_')||$table==='company_user_access')continue;
        $columns=$db->query('SHOW COLUMNS FROM '.companyQuoteIdentifier($table))->fetchAll(PDO::FETCH_COLUMN);
        $order=array_column($db->query('SHOW INDEX FROM '.companyQuoteIdentifier($table)." WHERE Key_name='PRIMARY'")->fetchAll(),'Column_name');
        $snapshots[$table]=[$columns,$order,$fingerprint($db,$table,$columns,$order)];
    }
    // Keep the original schema, data and triggers before the first DDL change.
    $config=require __DIR__.'/config/database.php';
    $directory=__DIR__.'/backups';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('No se pudo crear el directorio de respaldo.');
    $backup=$directory.'/before-multi-company-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
    $binary=dirname(PHP_BINARY,2).'/mysql/bin/mysqldump.exe';
    $args=[$binary,'--host='.$config['host'],'--port='.$config['port'],'--user='.$config['username'],
        '--single-transaction','--routines','--triggers','--hex-blob','--result-file='.$backup,$config['database']];
    if($config['password']!=='')array_splice($args,4,0,['--password='.$config['password']]);
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($process))throw new RuntimeException('No se pudo iniciar el respaldo.');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($process)!==0||!is_file($backup)||filesize($backup)<100)throw new RuntimeException('El respaldo falló; no se inició la migración. '.$error);
    echo 'Respaldo: '.$backup."\n";
    installMultiCompany($db);
    foreach($snapshots as $table=>[$columns,$order,$hash])if(!hash_equals($hash,$fingerprint($db,$table,$columns,$order)))
        throw new RuntimeException('La verificación detectó cambios en '.$table.'. Conserva el respaldo y revisa antes de continuar.');
    echo 'Verificación: valores originales conservados en '.count($snapshots)." tablas.\n";
    echo "Multiempresa instalada. Los registros existentes pertenecen a la empresa 1.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

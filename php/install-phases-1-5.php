<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/phase15-schema.php';
try{
    $backup=phase15Backup();echo 'Respaldo: '.$backup.PHP_EOL;
    if(!in_array('--backup-only',$argv,true)){installPhase15Schema(createDatabaseConnection(true));echo "Municipios y direcciones instalados.\n";}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

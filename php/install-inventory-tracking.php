<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/inventory-tracking-schema.php';
try{
    echo 'Respaldo: '.phase15Backup('inventory-tracking').PHP_EOL;
    installInventoryTracking(createDatabaseConnection(true));
    echo "Seguimiento por lotes, series y vencimientos instalado.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

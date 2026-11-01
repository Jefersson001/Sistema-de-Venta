<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/product-details-schema.php';
try{
    echo 'Respaldo: '.phase15Backup('product-details').PHP_EOL;
    installProductDetails(createDatabaseConnection(true));
    echo "Datos básicos de productos instalados.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

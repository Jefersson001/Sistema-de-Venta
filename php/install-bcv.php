<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/exchange-rate-schema.php';
try{
    $db=createDatabaseConnection(true);installExchangeRateMetadata($db);
    echo "Metadatos de tasas instalados. Los documentos existentes conservan sus valores.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

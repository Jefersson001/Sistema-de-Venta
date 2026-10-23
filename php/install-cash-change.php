<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/cash-change-schema.php';
try{installCashChange(createDatabaseConnection(true));echo "Vuelto instalado; importes y movimientos existentes conservados.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

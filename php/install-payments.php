<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/payments-schema.php';
try{installAccountingPayments(createDatabaseConnection());echo "Pagos, cajas y bancos, métodos y monedas instalados; pagos anteriores conservados.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

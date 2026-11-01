<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/payment-proofs-schema.php';
try{installPaymentProofs(createDatabaseConnection(true));echo "Comprobantes de pagos instalados.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

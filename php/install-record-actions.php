<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// This installer takes a complete backup and stops on backup failure.
require __DIR__.'/install-review-fixes.php';
require_once __DIR__.'/record-actions-schema.php';
try{installRecordActions(createDatabaseConnection(true));echo "Acciones comunes y permisos instalados.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

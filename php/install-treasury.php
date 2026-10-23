<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();require_once __DIR__.'/treasury-schema.php';
try{installTreasury(createDatabaseConnection());echo "Saldos, conceptos, movimientos y transferencias instalados; pagos confirmados conservados.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();
require_once __DIR__.'/commission-schema.php';
try {
    installSalesCommissions(createDatabaseConnection());
    echo "Comisiones por producto, referencias históricas y pagos relacionados instalados.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n"); exit(1);
}

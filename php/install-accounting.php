<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();
try {
    $migration = file_get_contents(__DIR__ . '/migrations/007_payment_terms.sql');
    if ($migration === false) { throw new RuntimeException('No se pudo leer la migración de términos de pago.'); }
    createDatabaseConnection()->exec($migration);
    echo "Términos de pago instalados.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n"); exit(1);
}

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();

try {
    $migration = file_get_contents(__DIR__ . '/migrations/006_business_taxes.sql');
    if ($migration === false) {
        throw new RuntimeException('No se pudo leer la migración de impuestos.');
    }
    createDatabaseConnection()->exec($migration);
    echo "Catálogos de impuestos de Compras y Ventas instalados.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudieron instalar los impuestos: ' . $exception->getMessage() . "\n");
    exit(1);
}

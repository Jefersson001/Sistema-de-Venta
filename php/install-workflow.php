<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();
require_once __DIR__ . '/workflow-schema.php';
try { installCommerceWorkflow(createDatabaseConnection()); echo "Relaciones de líneas, parciales, reservas, devoluciones y datos económicos instalados.\n"; }
catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }

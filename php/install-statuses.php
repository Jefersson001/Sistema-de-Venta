<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/status-schema.php';
try { installDocumentStatuses(createDatabaseConnection()); echo "Estados de facturas, pagos, cotizaciones y operaciones instalados.\n"; }
catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }

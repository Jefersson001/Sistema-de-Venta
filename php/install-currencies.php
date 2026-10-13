<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/currency-schema.php';
try { installCurrencyCatalog(createDatabaseConnection()); echo "Catálogo de monedas y tasas instalado; configuración y documentos conservados.\n"; }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }

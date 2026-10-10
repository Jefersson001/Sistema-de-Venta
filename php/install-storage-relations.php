<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/storage-schema.php';
try {
    $db = createDatabaseConnection();
    installContactStorageRelations($db);
    if ($db->query("SHOW TABLES LIKE 'sales_quotations'")->fetchColumn()) { installQuotationStorageRelations($db); }
    echo "Relaciones Vendedor, Cliente y Almacén instaladas.\n";
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }

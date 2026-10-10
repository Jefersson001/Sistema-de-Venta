<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/storage-schema.php';
require_once __DIR__ . '/sales-schema.php';
require_once __DIR__ . '/sales-delivery-schema.php';
try {
    $db = createDatabaseConnection();
    installContactStorageRelations($db);
    foreach (['007_payment_terms.sql', '008_sales_quotations.sql'] as $file) {
        $migration = file_get_contents(__DIR__ . '/migrations/' . $file);
        if ($migration === false) { throw new RuntimeException('No se pudo leer la migración de Ventas.'); }
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') { $db->exec($statement); }
        }
    }
    installQuotationStorageRelations($db);
    installSalesOrderType($db);
    installSalesDeliveryRelation($db);
    echo "Cotizaciones y términos de pago instalados.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n"); exit(1);
}

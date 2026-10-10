<?php
declare(strict_types=1);

function installSalesOrderType(PDO $db): void
{
    if (! $db->query("SHOW COLUMNS FROM sales_quotations LIKE 'order_type'")->fetch()) {
        $migration = file_get_contents(__DIR__ . '/migrations/011_sales_order_type.sql');
        if ($migration === false) { throw new RuntimeException('No se pudo leer la migración del tipo de orden.'); }
        $db->exec($migration);
    }
}

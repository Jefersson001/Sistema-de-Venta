<?php
declare(strict_types=1);
require_once __DIR__ . '/external-locations-schema.php';

function installContactStorageRelations(PDO $db): void
{
    $migration = file_get_contents(__DIR__ . '/migrations/010_inventory_warehouses.sql');
    if ($migration === false) { throw new RuntimeException('No se pudo leer la migración de almacenes.'); }
    foreach (explode(';', $migration) as $statement) { if (trim($statement) !== '') { $db->exec($statement); } }
    installExternalLocations($db);
    if (! $db->query("SHOW COLUMNS FROM contacts LIKE 'warehouse_id'")->fetch()) {
        $db->exec('ALTER TABLE contacts ADD warehouse_id INT UNSIGNED NULL, ADD CONSTRAINT contact_warehouse FOREIGN KEY (warehouse_id) REFERENCES inventory_warehouses(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM contacts LIKE 'location_id'")->fetch()) {
        $db->exec('ALTER TABLE contacts ADD location_id INT UNSIGNED NULL, ADD CONSTRAINT contact_inventory_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id)');
    }
}

function installQuotationStorageRelations(PDO $db): void
{
    if (! $db->query("SHOW COLUMNS FROM sales_quotations LIKE 'warehouse_id'")->fetch()) {
        $db->exec('ALTER TABLE sales_quotations ADD warehouse_id INT UNSIGNED NULL, ADD CONSTRAINT quotation_warehouse FOREIGN KEY (warehouse_id) REFERENCES inventory_warehouses(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM sales_quotations LIKE 'location_id'")->fetch()) {
        $db->exec('ALTER TABLE sales_quotations ADD location_id INT UNSIGNED NULL, ADD CONSTRAINT quotation_inventory_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM sales_quotations LIKE 'storage_label'")->fetch()) {
        $db->exec('ALTER TABLE sales_quotations ADD storage_label TEXT NULL');
    }
}

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/connection.php';

try {
    $db = createDatabaseConnection();
    foreach (['004_inventory_categories.sql', '005_inventory_products.sql', '006_business_taxes.sql', '010_inventory_warehouses.sql', '012_inventory_operations.sql', '013_inventory_stock.sql'] as $file) {
        $migration = file_get_contents(__DIR__ . '/migrations/' . $file);
        if ($migration === false) {
            throw new RuntimeException('No se pudo leer la migración de Inventario.');
        }
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') { $db->exec($statement); }
        }
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_products LIKE 'sale_price'")->fetch()) {
        $db->exec('ALTER TABLE inventory_products ADD sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_products LIKE 'category_id'")->fetch()) {
        $db->exec('ALTER TABLE inventory_products ADD category_id INT UNSIGNED NULL,
            ADD CONSTRAINT inventory_product_category FOREIGN KEY (category_id) REFERENCES inventory_categories(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_products LIKE 'purchase_tax_id'")->fetch()) {
        $db->exec('ALTER TABLE inventory_products ADD purchase_tax_id INT UNSIGNED NULL,
            ADD CONSTRAINT inventory_product_purchase_tax FOREIGN KEY (purchase_tax_id) REFERENCES business_taxes(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_products LIKE 'sale_tax_id'")->fetch()) {
        $db->exec('ALTER TABLE inventory_products ADD sale_tax_id INT UNSIGNED NULL,
            ADD CONSTRAINT inventory_product_sale_tax FOREIGN KEY (sale_tax_id) REFERENCES business_taxes(id)');
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_operations LIKE 'status'")->fetch()) {
        $db->exec("ALTER TABLE inventory_operations ADD status ENUM('draft', 'done') NOT NULL DEFAULT 'draft'");
    }
    echo "Productos, operaciones y existencias de Inventario instalados.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo instalar Inventario: ' . $exception->getMessage() . "\n");
    exit(1);
}

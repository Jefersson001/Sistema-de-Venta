<?php
declare(strict_types=1);
require_once __DIR__ . '/status-schema.php';
require_once __DIR__ . '/workflow-schema.php';

function installCommerceDocuments(PDO $db): void
{
    $columns = [
        'version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'order_type' => "ENUM('receipt', 'invoice') NOT NULL DEFAULT 'receipt'",
        'payment_term_id' => 'INT UNSIGNED NULL', 'invoice_address' => 'TEXT NULL',
        'destination_location_id' => 'INT UNSIGNED NULL',
        'base_amount' => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00',
        'total_amount' => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00',
    ];
    foreach ($columns as $name => $definition) {
        if (! $db->query("SHOW COLUMNS FROM purchase_orders LIKE '$name'")->fetch()) {
            $db->exec('ALTER TABLE purchase_orders ADD ' . $name . ' ' . $definition);
            if ($name === 'payment_term_id') { $db->exec('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_payment_term FOREIGN KEY (payment_term_id) REFERENCES accounting_payment_terms(id)'); }
            if ($name === 'destination_location_id') { $db->exec('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_destination FOREIGN KEY (destination_location_id) REFERENCES inventory_locations(id)'); }
        }
    }
    $db->exec("ALTER TABLE purchase_orders MODIFY status ENUM('draft', 'sent', 'purchase') NOT NULL DEFAULT 'draft'");
    foreach (['position' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'product_name' => 'VARCHAR(160) NULL',
        'tax_id' => 'INT UNSIGNED NULL', 'tax_name' => 'VARCHAR(120) NULL',
        'base_amount' => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00', 'total_amount' => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00'] as $name => $definition) {
        if (! $db->query("SHOW COLUMNS FROM purchase_order_lines LIKE '$name'")->fetch()) {
            $db->exec('ALTER TABLE purchase_order_lines ADD ' . $name . ' ' . $definition);
            if ($name === 'tax_id') { $db->exec('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_line_tax FOREIGN KEY (tax_id) REFERENCES business_taxes(id)'); }
        }
    }
    if (! $db->query("SHOW COLUMNS FROM inventory_operations LIKE 'purchase_order_id'")->fetch()) {
        $db->exec('ALTER TABLE inventory_operations ADD purchase_order_id INT UNSIGNED NULL,
            ADD UNIQUE KEY receipt_purchase_order (purchase_order_id),
            ADD CONSTRAINT receipt_purchase_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)');
    }
    $migration = file_get_contents(__DIR__ . '/migrations/015_accounting_invoices.sql');
    if ($migration === false) { throw new RuntimeException('No se pudo leer la migración de facturas.'); }
    foreach (explode(';', $migration) as $statement) { if (trim($statement) !== '') { $db->exec($statement); } }
    installDocumentStatuses($db);
    installCommerceWorkflow($db);
}

<?php
declare(strict_types=1);

function workflowColumn(PDO $db, string $table, string $name, string $definition): bool
{
    if ($db->query("SHOW COLUMNS FROM $table LIKE '$name'")->fetch()) return false;
    $db->exec("ALTER TABLE $table ADD $name $definition"); return true;
}

function installCommerceWorkflow(PDO $db): void
{
    workflowColumn($db, 'business_taxes', 'rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
    workflowColumn($db, 'accounting_payment_terms', 'days', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    workflowColumn($db, 'inventory_products', 'product_type', "ENUM('goods','service') NOT NULL DEFAULT 'goods'");
    workflowColumn($db, 'inventory_products', 'invoice_policy', "ENUM('ordered','delivered') NOT NULL DEFAULT 'delivered'");
    workflowColumn($db, 'inventory_products', 'cost_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    $db->exec("CREATE TABLE IF NOT EXISTS product_supplier_prices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
        price DECIMAL(12,2) NOT NULL, UNIQUE KEY supplier_product (product_id, supplier_id),
        FOREIGN KEY (product_id) REFERENCES inventory_products(id), FOREIGN KEY (supplier_id) REFERENCES contacts(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['sales_quotations', 'purchase_orders', 'accounting_invoices'] as $table) workflowColumn($db, $table, 'tax_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
    foreach (['sales_quotation_lines', 'purchase_order_lines', 'accounting_invoice_lines'] as $table) {
        workflowColumn($db, $table, 'tax_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
        workflowColumn($db, $table, 'tax_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
        workflowColumn($db, $table, 'unit_cost', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        if ($table !== 'accounting_invoice_lines') {
            workflowColumn($db, $table, 'product_type', "ENUM('goods','service') NOT NULL DEFAULT 'goods'");
            if (workflowColumn($db, $table, 'invoice_policy', "ENUM('ordered','delivered') NOT NULL DEFAULT 'delivered'")) {
                $order = $table === 'sales_quotation_lines' ? 'sales_quotations' : 'purchase_orders';
                $fk = $table === 'sales_quotation_lines' ? 'quotation_id' : 'order_id';
                $db->exec("UPDATE $table l JOIN $order o ON o.id=l.$fk SET l.invoice_policy='ordered' WHERE o.order_type='invoice'");
            }
        }
    }
    foreach (['inventory_operation_lines', 'accounting_invoice_lines'] as $table) {
        foreach (['sale_line_id' => 'sales_quotation_lines', 'purchase_line_id' => 'purchase_order_lines'] as $column => $parent) {
            workflowColumn($db, $table, $column, "INT UNSIGNED NULL REFERENCES $parent(id)");
        }
    }
    workflowColumn($db, 'inventory_operations', 'backorder_of_id', 'INT UNSIGNED NULL REFERENCES inventory_operations(id)');
    workflowColumn($db, 'inventory_operations', 'return_of_id', 'INT UNSIGNED NULL REFERENCES inventory_operations(id)');
    workflowColumn($db, 'inventory_operation_lines', 'return_line_id', 'INT UNSIGNED NULL REFERENCES inventory_operation_lines(id)');
    workflowColumn($db, 'accounting_invoices', 'document_type', "ENUM('invoice','credit') NOT NULL DEFAULT 'invoice'");
    workflowColumn($db, 'accounting_invoices', 'reversal_of_id', 'INT UNSIGNED NULL REFERENCES accounting_invoices(id)');
    workflowColumn($db, 'accounting_invoices', 'credit_reason', 'VARCHAR(255) NULL');
    workflowColumn($db, 'accounting_invoice_lines', 'original_line_id', 'INT UNSIGNED NULL REFERENCES accounting_invoice_lines(id)');
    foreach (['inventory_operations' => ['quotation_id', 'purchase_order_id'], 'accounting_invoices' => ['sale_id', 'purchase_id']] as $table => $columns) {
        foreach ($columns as $column) {
            $indexes = $db->query("SHOW INDEX FROM $table")->fetchAll();
            $index = 'workflow_' . $column;
            if (! array_filter($indexes, static fn($row) => $row['Key_name'] === $index)) $db->exec("ALTER TABLE $table ADD INDEX $index ($column)");
            foreach ($indexes as $row) if ($row['Column_name'] === $column && (int)$row['Non_unique'] === 0 && $row['Key_name'] !== 'PRIMARY') $db->exec("ALTER TABLE $table DROP INDEX `{$row['Key_name']}`");
        }
    }
    $db->exec("ALTER TABLE inventory_operations MODIFY status ENUM('draft','waiting','ready','done','cancel') NOT NULL DEFAULT 'draft'");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_reservations (
        operation_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, location_id INT UNSIGNED NOT NULL,
        quantity DECIMAL(17,2) NOT NULL, PRIMARY KEY(operation_id,product_id,location_id),
        FOREIGN KEY(operation_id) REFERENCES inventory_operations(id), FOREIGN KEY(product_id) REFERENCES inventory_products(id), FOREIGN KEY(location_id) REFERENCES inventory_locations(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(!$db->query("SHOW INDEX FROM inventory_reservations WHERE Key_name='reserved_product_location'")->fetch())$db->exec('ALTER TABLE inventory_reservations ADD INDEX reserved_product_location(product_id,location_id,operation_id)');
    $db->exec("UPDATE inventory_operations o JOIN inventory_locations l ON l.id=o.source_location_id SET o.status='waiting',o.version=o.version+1 WHERE o.status='ready' AND l.location_type='internal' AND NOT EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.operation_id=o.id)");
    // Only unambiguous legacy lines are linked. Historical movements and amounts stay intact.
    foreach (['sale' => ['sales_quotation_lines','sales_quotations','quotation_id','quotation_id','sale_id'], 'purchase' => ['purchase_order_lines','purchase_orders','order_id','purchase_order_id','purchase_id']] as $kind => [$lines,$orders,$lineFk,$opFk,$invoiceFk]) {
        $column = $kind . '_line_id';
        $db->exec("UPDATE inventory_operation_lines l JOIN inventory_operations o ON o.id=l.operation_id JOIN $lines s ON s.$lineFk=o.$opFk AND s.position=l.position AND s.product_id=l.product_id SET l.$column=s.id WHERE l.$column IS NULL AND o.return_of_id IS NULL");
        $db->exec("UPDATE accounting_invoice_lines l JOIN accounting_invoices i ON i.id=l.invoice_id JOIN $lines s ON s.$lineFk=i.$invoiceFk AND s.position=l.position AND s.product_id=l.product_id SET l.$column=s.id WHERE l.$column IS NULL AND i.document_type='invoice'");
    }
    foreach (['inventory_operation_lines'=>['sale_line_id'=>'sales_quotation_lines','purchase_line_id'=>'purchase_order_lines','return_line_id'=>'inventory_operation_lines'], 'accounting_invoice_lines'=>['sale_line_id'=>'sales_quotation_lines','purchase_line_id'=>'purchase_order_lines','original_line_id'=>'accounting_invoice_lines'], 'inventory_operations'=>['backorder_of_id'=>'inventory_operations','return_of_id'=>'inventory_operations'], 'accounting_invoices'=>['reversal_of_id'=>'accounting_invoices']] as $table=>$relations) {
        foreach($relations as $column=>$parent) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');$q->execute([$table,$column]);
            if(!$q->fetchColumn())$db->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_{$column} FOREIGN KEY ($column) REFERENCES $parent(id)");
        }
    }
}

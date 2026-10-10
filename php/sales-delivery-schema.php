<?php
declare(strict_types=1);

function installSalesDeliveryRelation(PDO $db): void
{
    if (! $db->query("SHOW TABLES LIKE 'sales_quotations'")->fetch()
        || ! $db->query("SHOW TABLES LIKE 'inventory_operations'")->fetch()) { return; }
    if (! $db->query("SHOW COLUMNS FROM inventory_operations LIKE 'quotation_id'")->fetch()) {
        $db->exec('ALTER TABLE inventory_operations ADD quotation_id INT UNSIGNED NULL,
            ADD UNIQUE KEY delivery_quotation (quotation_id),
            ADD CONSTRAINT delivery_sales_order FOREIGN KEY (quotation_id) REFERENCES sales_quotations(id)');
    }
}

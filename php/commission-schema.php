<?php
declare(strict_types=1);
require_once __DIR__.'/workflow-schema.php';

function installSalesCommissions(PDO $db): void
{
    installProductCommissions($db);
    // No reconstruir comisiones históricas usando precios actuales.
    foreach (['sales_quotation_lines','accounting_invoice_lines'] as $table) {
        workflowColumn($db, $table, 'commission_reference_price', 'DECIMAL(12,2) NULL');
        workflowColumn($db, $table, 'commission_unit_amount', 'DECIMAL(12,2) NULL');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS sales_commission_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT UNSIGNED NOT NULL,
        treasury_document_id INT UNSIGNED NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        UNIQUE KEY commission_document_invoice (treasury_document_id,invoice_id),
        CONSTRAINT commission_invoice FOREIGN KEY (invoice_id) REFERENCES accounting_invoices(id),
        CONSTRAINT commission_treasury FOREIGN KEY (treasury_document_id) REFERENCES accounting_treasury_documents(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO accounting_cash_concepts(name,direction,system_key)
        VALUES ('Pago de comisiones','outbound','sales_commission')");
}

function installProductCommissions(PDO $db): void
{
    workflowColumn($db,'inventory_products','commission_type',"ENUM('none','fixed','percent') NOT NULL DEFAULT 'none'");
    workflowColumn($db,'inventory_products','commission_value','DECIMAL(14,4) NOT NULL DEFAULT 0.0000');
}

<?php
declare(strict_types=1);

function installDocumentStatuses(PDO $db): void
{
    $db->exec("ALTER TABLE sales_quotations MODIFY status ENUM('draft', 'sent', 'sale', 'cancel') NOT NULL DEFAULT 'draft'");
    $db->exec("ALTER TABLE inventory_operations MODIFY status ENUM('draft', 'waiting', 'ready', 'done', 'cancel') NOT NULL DEFAULT 'draft'");
    $db->exec("ALTER TABLE accounting_invoices MODIFY status ENUM('draft', 'posted', 'cancel') NOT NULL DEFAULT 'draft'");
    foreach (['payment_state' => "ENUM('not_paid', 'in_payment', 'partial', 'paid', 'reversed') NOT NULL DEFAULT 'not_paid'", 'sent_at' => 'DATETIME NULL'] as $name => $definition) {
        if (! $db->query("SHOW COLUMNS FROM accounting_invoices LIKE '$name'")->fetch()) $db->exec('ALTER TABLE accounting_invoices ADD ' . $name . ' ' . $definition);
    }
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_invoice_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL,
        amount DECIMAL(18,2) NOT NULL, payment_date DATE NOT NULL, reference VARCHAR(160) NOT NULL,
        status ENUM('pending', 'settled', 'cancel') NOT NULL DEFAULT 'pending',
        FOREIGN KEY (invoice_id) REFERENCES accounting_invoices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_invoice_reversals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL UNIQUE,
        name VARCHAR(32) NULL UNIQUE, reversal_date DATE NOT NULL, reason VARCHAR(255) NOT NULL,
        amount DECIMAL(18,2) NOT NULL, FOREIGN KEY (invoice_id) REFERENCES accounting_invoices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("UPDATE accounting_invoices SET payment_state = 'paid' WHERE status = 'posted' AND total_amount = 0 AND payment_state = 'not_paid'");
}

<?php
declare(strict_types=1);
require_once __DIR__.'/workflow-schema.php';
require_once __DIR__.'/cash-change-schema.php';

function installTreasury(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_cash_concepts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL UNIQUE,
        direction ENUM('inbound','outbound','both') NOT NULL DEFAULT 'both',active TINYINT(1) NOT NULL DEFAULT 1,
        system_key VARCHAR(32) NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $insert=$db->prepare('INSERT IGNORE INTO accounting_cash_concepts(name,direction,system_key) VALUES (?,?,?)');
    foreach(['customer_payment'=>['Cobro de cliente','inbound'],'supplier_payment'=>['Pago a proveedor','outbound'],
        'customer_refund'=>['Devolución a cliente','outbound'],'supplier_refund'=>['Devolución de proveedor','inbound']] as $key=>[$name,$direction])$insert->execute([$name,$direction,$key]);
    workflowColumn($db,'accounting_payments','concept_id','INT UNSIGNED NULL');
    if(!$db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_payments' AND CONSTRAINT_NAME='payment_cash_concept'")->fetchColumn()) {
        $db->exec('ALTER TABLE accounting_payments ADD CONSTRAINT payment_cash_concept FOREIGN KEY(concept_id) REFERENCES accounting_cash_concepts(id)');
    }
    $db->exec("ALTER TABLE accounting_payments MODIFY status ENUM('draft','pending','settled','cancel','reversed') NOT NULL DEFAULT 'draft'");
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_treasury_documents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(32) NULL UNIQUE,
        kind ENUM('opening','transfer','income','expense','reversal','payment_reversal') NOT NULL,
        status ENUM('draft','done','cancel') NOT NULL DEFAULT 'draft',version INT UNSIGNED NOT NULL DEFAULT 1,
        account_id INT UNSIGNED NOT NULL,destination_account_id INT UNSIGNED NULL,
        currency_code CHAR(3) NOT NULL,destination_currency_code CHAR(3) NULL,
        amount DECIMAL(18,2) NOT NULL,destination_amount DECIMAL(18,2) NULL,
        primary_code CHAR(3) NOT NULL,alternate_code CHAR(3) NOT NULL,exchange_rate DECIMAL(14,2) NULL,
        movement_date DATE NOT NULL,concept_id INT UNSIGNED NULL,contact_id INT UNSIGNED NULL,method_id INT UNSIGNED NULL,
        reference VARCHAR(255) NOT NULL DEFAULT '',reversal_of_id INT UNSIGNED NULL UNIQUE,
        payment_id INT UNSIGNED NULL UNIQUE,creation_key CHAR(64) NULL UNIQUE,
        FOREIGN KEY(account_id) REFERENCES accounting_payment_accounts(id),
        FOREIGN KEY(destination_account_id) REFERENCES accounting_payment_accounts(id),
        FOREIGN KEY(concept_id) REFERENCES accounting_cash_concepts(id),FOREIGN KEY(contact_id) REFERENCES contacts(id),
        FOREIGN KEY(method_id) REFERENCES accounting_payment_methods(id),
        FOREIGN KEY(reversal_of_id) REFERENCES accounting_treasury_documents(id),FOREIGN KEY(payment_id) REFERENCES accounting_payments(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_treasury_entries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id INT UNSIGNED NOT NULL,currency_code CHAR(3) NOT NULL,
        movement_date DATE NOT NULL,amount DECIMAL(20,2) NOT NULL,
        category ENUM('opening','payment','movement','transfer','reversal') NOT NULL,
        payment_id INT UNSIGNED NULL UNIQUE,document_id INT UNSIGNED NULL,
        concept_id INT UNSIGNED NULL,contact_id INT UNSIGNED NULL,method_id INT UNSIGNED NULL,
        UNIQUE KEY treasury_document_account(document_id,account_id),INDEX treasury_account_date(account_id,movement_date),
        INDEX treasury_date(movement_date),FOREIGN KEY(account_id) REFERENCES accounting_payment_accounts(id),
        FOREIGN KEY(payment_id) REFERENCES accounting_payments(id),FOREIGN KEY(document_id) REFERENCES accounting_treasury_documents(id),
        FOREIGN KEY(concept_id) REFERENCES accounting_cash_concepts(id),FOREIGN KEY(contact_id) REFERENCES contacts(id),
        FOREIGN KEY(method_id) REFERENCES accounting_payment_methods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("UPDATE accounting_payments p JOIN accounting_cash_concepts c ON c.system_key=
        CASE WHEN p.partner_type='customer' THEN IF(p.direction='inbound','customer_payment','customer_refund')
        ELSE IF(p.direction='outbound','supplier_payment','supplier_refund') END SET p.concept_id=c.id WHERE p.concept_id IS NULL");
    // Repeatable migration: preserve original dates, currencies and confirmed payment amounts.
    $db->exec("INSERT INTO accounting_treasury_entries(account_id,currency_code,movement_date,amount,category,payment_id,concept_id,contact_id,method_id)
        SELECT p.account_id,p.currency_code,p.payment_date,IF(p.direction='inbound',p.amount,-p.amount),'payment',p.id,p.concept_id,p.contact_id,p.method_id
        FROM accounting_payments p WHERE p.status='settled' AND NOT EXISTS(SELECT 1 FROM accounting_treasury_entries e WHERE e.payment_id=p.id)");
    installCashChange($db);
}

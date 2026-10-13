<?php
declare(strict_types=1);
require_once __DIR__.'/workflow-schema.php';
require_once __DIR__.'/currency-schema.php';
require_once __DIR__.'/treasury-schema.php';

function installDefaultPaymentMethods(PDO $db): void
{
    $defaults = ['manual'=>'Manual', 'cash'=>'Efectivo', 'bank_transfer'=>'Transferencia bancaria',
        'mobile_payment'=>'Pago móvil', 'card'=>'Tarjeta', 'zelle'=>'Zelle', 'usdt'=>'USDT', 'paypal'=>'PayPal', 'biopago'=>'Bio Pago'];
    $normalize = static fn(string $name): string => mb_strtolower(rtrim(trim($name), ". \t\n\r\0\x0B"), 'UTF-8');
    $names = []; $keys = [];
    foreach ($db->query('SELECT name,system_key FROM accounting_payment_methods')->fetchAll() as $method) {
        $names[$normalize($method['name'])] = true;
        if ($method['system_key'] !== null) $keys[$method['system_key']] = true;
    }
    $insert = $db->prepare('INSERT IGNORE INTO accounting_payment_methods(name,system_key) VALUES (?,?)');
    foreach ($defaults as $key => $name) {
        if (isset($keys[$key]) || isset($names[$normalize($name)])) continue;
        $insert->execute([$name, $key]);
        $names[$normalize($name)] = true; $keys[$key] = true;
    }
}

function installAccountingPayments(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_currency_settings (id TINYINT UNSIGNED PRIMARY KEY, primary_code CHAR(3) NOT NULL, alternate_code CHAR(3) NOT NULL, exchange_rate DECIMAL(20,8) NULL, version INT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO accounting_currency_settings(id,primary_code,alternate_code) VALUES(1,'USD','VES')");
    foreach(['sales_quotations','purchase_orders','accounting_invoices','inventory_products'] as $table)workflowColumn($db,$table,'currency_code',"CHAR(3) NOT NULL DEFAULT 'USD'");
    foreach(['sales_quotations','purchase_orders','accounting_invoices'] as $table){workflowColumn($db,$table,'currency_primary_code',"CHAR(3) NOT NULL DEFAULT 'USD'");workflowColumn($db,$table,'currency_alternate_code',"CHAR(3) NOT NULL DEFAULT 'VES'");workflowColumn($db,$table,'exchange_rate','DECIMAL(20,8) NULL');}
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_payment_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL UNIQUE, account_type ENUM('cash','bank') NOT NULL, currency_code CHAR(3) NULL, system_key VARCHAR(32) NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_payment_methods (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL UNIQUE,system_key VARCHAR(32) NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO accounting_payment_accounts(name,account_type,system_key) VALUES('Caja','cash','default_cash')");
    installDefaultPaymentMethods($db);
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(32) NULL UNIQUE,
        partner_type ENUM('customer','supplier') NOT NULL,direction ENUM('inbound','outbound') NOT NULL,contact_id INT UNSIGNED NOT NULL,
        amount DECIMAL(18,2) NOT NULL,currency_code CHAR(3) NOT NULL,primary_code CHAR(3) NOT NULL,alternate_code CHAR(3) NOT NULL,exchange_rate DECIMAL(20,8) NULL,
        payment_date DATE NOT NULL,reference VARCHAR(160) NOT NULL,account_id INT UNSIGNED NOT NULL,method_id INT UNSIGNED NOT NULL,
        status ENUM('draft','pending','settled','cancel') NOT NULL DEFAULT 'draft',version INT UNSIGNED NOT NULL DEFAULT 1,creation_key CHAR(64) NULL UNIQUE,
        FOREIGN KEY(contact_id) REFERENCES contacts(id),FOREIGN KEY(account_id) REFERENCES accounting_payment_accounts(id),FOREIGN KEY(method_id) REFERENCES accounting_payment_methods(id),INDEX payment_partner(contact_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    workflowColumn($db,'accounting_invoice_payments','payment_id','INT UNSIGNED NULL');
    workflowColumn($db,'accounting_invoice_payments','payment_amount','DECIMAL(18,2) NULL');
    $q=$db->query("SELECT p.*,i.contact_id,i.invoice_type,i.document_type,i.currency_code FROM accounting_invoice_payments p JOIN accounting_invoices i ON i.id=p.invoice_id WHERE p.payment_id IS NULL ORDER BY p.id");
    $legacy=$q->fetchAll();$settings=$db->query('SELECT * FROM accounting_currency_settings WHERE id=1')->fetch();
    $account=(int)$db->query("SELECT id FROM accounting_payment_accounts WHERE system_key='default_cash'")->fetchColumn();$method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->beginTransaction();
    try{
        foreach($legacy as $row){
            $lock=$db->prepare('SELECT payment_id FROM accounting_invoice_payments WHERE id=? FOR UPDATE');$lock->execute([$row['id']]);if($lock->fetchColumn())continue;
            $inbound=($row['invoice_type']==='customer')===($row['document_type']==='invoice');
            $db->prepare('INSERT INTO accounting_payments(partner_type,direction,contact_id,amount,currency_code,primary_code,alternate_code,exchange_rate,payment_date,reference,account_id,method_id,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$row['invoice_type'],$inbound?'inbound':'outbound',$row['contact_id'],$row['amount'],$row['currency_code'],$settings['primary_code'],$settings['alternate_code'],$settings['exchange_rate'],$row['payment_date'],$row['reference'],$account,$method,$row['status']]);
            $id=(int)$db->lastInsertId();$db->prepare('UPDATE accounting_payments SET name=? WHERE id=?')->execute(['PAG'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);
            $db->prepare('UPDATE accounting_invoice_payments SET payment_id=?,payment_amount=amount WHERE id=?')->execute([$id,$row['id']]);
        }$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
    if(!$db->query("SHOW INDEX FROM accounting_invoice_payments WHERE Key_name='payment_invoice_once'")->fetch())$db->exec('ALTER TABLE accounting_invoice_payments ADD UNIQUE KEY payment_invoice_once(payment_id,invoice_id), ADD CONSTRAINT allocation_payment FOREIGN KEY(payment_id) REFERENCES accounting_payments(id)');
    installCurrencyCatalog($db);
    installTreasury($db);
}

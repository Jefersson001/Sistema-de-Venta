<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow-repository.php';
require_once __DIR__ . '/exchange-rate-schema.php';

function installCurrencyCatalog(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_currencies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code CHAR(3) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        symbol VARCHAR(12) NOT NULL, unit_label VARCHAR(80) NOT NULL DEFAULT '',
        subunit_label VARCHAR(80) NOT NULL DEFAULT '', symbol_position ENUM('before','after') NOT NULL DEFAULT 'after',
        version INT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS accounting_currency_rates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, currency_id INT UNSIGNED NOT NULL,
        base_currency_id INT UNSIGNED NOT NULL, rate_date DATE NOT NULL, rate DECIMAL(14,2) NOT NULL,
        FOREIGN KEY(currency_id) REFERENCES accounting_currencies(id),
        FOREIGN KEY(base_currency_id) REFERENCES accounting_currencies(id),
        UNIQUE KEY currency_base_date(currency_id,base_currency_id,rate_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $settings = $db->query('SELECT * FROM accounting_currency_settings WHERE id=1')->fetch();
    $codes = [$settings['primary_code'], $settings['alternate_code']];
    $columns = ['inventory_products'=>['currency_code'],'sales_quotations'=>['currency_code','currency_primary_code','currency_alternate_code'],
        'purchase_orders'=>['currency_code','currency_primary_code','currency_alternate_code'],'accounting_invoices'=>['currency_code','currency_primary_code','currency_alternate_code'],
        'accounting_payments'=>['currency_code','primary_code','alternate_code'],'accounting_payment_accounts'=>['currency_code'],
        'accounting_treasury_documents'=>['currency_code','destination_currency_code','primary_code','alternate_code'],'accounting_treasury_entries'=>['currency_code']];
    foreach ($columns as $table=>$fields) {
        if(str_starts_with($table,'accounting_treasury_')){
            $exists=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute([$table]);if(!$exists->fetchColumn())continue;
        }
        foreach ($fields as $field) $codes = array_merge($codes, $db->query('SELECT DISTINCT '.$field.' FROM '.$table.' WHERE '.$field.' IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    }
    $defaults = ['USD'=>['Dólar estadounidense','$','Dólar','Centavo','before'], 'VES'=>['Bolívar','Bs.','Bolívar','Céntimo','after']];
    $insert = $db->prepare('INSERT IGNORE INTO accounting_currencies(code,name,symbol,unit_label,subunit_label,symbol_position) VALUES(?,?,?,?,?,?)');
    foreach (array_unique($codes) as $code) $insert->execute(array_merge([$code], $defaults[$code] ?? [$code,$code,'','','after']));
    if ($settings['exchange_rate']) {
        $find = $db->prepare('SELECT id FROM accounting_currencies WHERE code=?');
        $find->execute([$settings['primary_code']]); $base = (int)$find->fetchColumn();
        $find->execute([$settings['alternate_code']]); $currency = (int)$find->fetchColumn();
        $db->prepare('INSERT INTO accounting_currency_rates(currency_id,base_currency_id,rate_date,rate) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM accounting_currency_rates WHERE currency_id=? AND base_currency_id=?)')->execute([$currency,$base,commerceToday(),$settings['exchange_rate'],$currency,$base]);
    }
    installExchangeRateMetadata($db);
}

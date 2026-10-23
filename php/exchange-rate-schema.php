<?php
declare(strict_types=1);

/** Add provenance without changing any previously saved rates or documents. CLI installers only. */
function installExchangeRateMetadata(PDO $db): void
{
    foreach(['accounting_currency_rates'=>['source'=>"VARCHAR(20) NOT NULL DEFAULT 'manual'",'fetched_at'=>'DATETIME NULL'],
        'accounting_invoices'=>['exchange_rate_date'=>'DATE NULL','exchange_rate_source'=>'VARCHAR(20) NULL']] as $table=>$columns){
        if(!$db->query("SHOW TABLES LIKE '$table'")->fetchColumn())continue;
        $existing=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        foreach($columns as $column=>$definition)if(!in_array($column,$existing,true))$db->exec("ALTER TABLE $table ADD $column $definition");
    }
}

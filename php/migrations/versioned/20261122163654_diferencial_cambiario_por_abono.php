<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/payment-exchange.php';
    foreach(['fx_currency_code'=>'CHAR(3) NULL','fx_invoice_rate'=>'DECIMAL(24,10) NULL','fx_payment_rate'=>'DECIMAL(24,10) NULL',
        'fx_historical_amount'=>'DECIMAL(18,2) NULL','fx_settlement_amount'=>'DECIMAL(18,2) NULL','fx_difference'=>'DECIMAL(18,2) NULL'] as $column=>$definition){
        if(!$db->query("SHOW COLUMNS FROM accounting_invoice_payments LIKE '$column'")->fetch())$db->exec('ALTER TABLE accounting_invoice_payments ADD COLUMN '.$column.' '.$definition);
    }
    foreach(['exchange_rate_date'=>'DATE NULL','exchange_rate_source'=>'VARCHAR(20) NULL'] as $column=>$definition){
        if(!$db->query("SHOW COLUMNS FROM accounting_payments LIKE '$column'")->fetch())$db->exec('ALTER TABLE accounting_payments ADD COLUMN '.$column.' '.$definition);
    }
    // No changes to legacy amounts, balances, dates or payment status. Fill only derivable snapshots.
    $context=$db->query('SELECT @nexo_company_id AS company_id,@nexo_actor_id AS actor_id')->fetch();
    try{
        $companies=$db->query('SELECT DISTINCT company_id FROM accounting_invoice_payments WHERE fx_currency_code IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach($companies as $company){$db->exec('SET @nexo_company_id='.(int)$company.',@nexo_actor_id=NULL');
            $q=$db->prepare('SELECT a.id,a.amount,a.payment_amount,i.currency_code,i.currency_primary_code,i.currency_alternate_code,i.exchange_rate,p.currency_code AS payment_currency,p.primary_code,p.alternate_code,p.exchange_rate AS payment_rate FROM accounting_invoice_payments a JOIN accounting_invoices i ON i.id=a.invoice_id AND i.company_id=a.company_id JOIN accounting_payments p ON p.id=a.payment_id AND p.company_id=a.company_id WHERE a.company_id=? AND a.fx_currency_code IS NULL');$q->execute([$company]);
            foreach($q->fetchAll() as $row){if($row['payment_amount']===null)continue;$snapshot=paymentExchangeSnapshot($row,['currency_code'=>$row['payment_currency'],'primary_code'=>$row['primary_code'],'alternate_code'=>$row['alternate_code'],'exchange_rate'=>$row['payment_rate']],$row['amount'],$row['payment_amount']);if($snapshot['fx_currency_code']!==null)paymentStoreExchangeSnapshot($db,(int)$row['id'],$snapshot);}
        }
    }finally{
        $restore=$db->prepare('SET @nexo_company_id=?,@nexo_actor_id=?');$restore->execute([$context['company_id'],$context['actor_id']]);
    }
};

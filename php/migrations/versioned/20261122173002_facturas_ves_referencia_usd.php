<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/payment-exchange.php';
    foreach(['accounting_invoices','accounting_invoice_payments'] as $table){
        if(!$db->query("SHOW COLUMNS FROM $table LIKE 'usd_reference_amount'")->fetch())$db->exec("ALTER TABLE $table ADD COLUMN usd_reference_amount DECIMAL(18,2) NULL");
    }
    $context=$db->query('SELECT @nexo_company_id AS company_id,@nexo_actor_id AS actor_id')->fetch();
    try{
        $companies=$db->query("SELECT DISTINCT company_id FROM accounting_invoices WHERE currency_code='VES'")->fetchAll(PDO::FETCH_COLUMN);
        foreach($companies as $company){
            $db->exec('SET @nexo_company_id='.(int)$company.',@nexo_actor_id=NULL');
            $q=$db->prepare("SELECT * FROM accounting_invoices WHERE company_id=? AND currency_code='VES' ORDER BY id");$q->execute([$company]);
            foreach($q->fetchAll() as $invoice){
                if($invoice['usd_reference_amount']===null){invoiceFreezeUsdDebt($db,$invoice);$r=$db->prepare('SELECT * FROM accounting_invoices WHERE id=? AND company_id=?');$r->execute([$invoice['id'],$company]);$invoice=$r->fetch();}
                if(!invoiceHasUsdDebt($invoice))continue;
                $a=$db->prepare("SELECT a.*,p.currency_code AS payment_currency,p.primary_code,p.alternate_code,p.exchange_rate AS payment_rate FROM accounting_invoice_payments a LEFT JOIN accounting_payments p ON p.id=a.payment_id AND p.company_id=a.company_id WHERE a.invoice_id=? AND a.company_id=? ORDER BY FIELD(a.status,'settled','pending','cancel'),a.id");$a->execute([$invoice['id'],$company]);
                $cumulative='0.00';$assigned='0.00';
                foreach($a->fetchAll() as $row){
                    $native=$row['status']==='cancel'?$row['amount']:bcadd($cumulative,$row['amount'],2);
                    $reference=paymentProportion($native,$invoice['total_amount'],$invoice['usd_reference_amount']);
                    $part=$row['status']==='cancel'?$reference:bcsub($reference,$assigned,2);
                    if($row['status']!=='cancel'){$cumulative=$native;$assigned=$reference;}
                    if($row['usd_reference_amount']!==null)continue;
                    $db->beginTransaction();try{
                        $db->prepare('UPDATE accounting_invoice_payments SET usd_reference_amount=? WHERE id=? AND company_id=?')->execute([$part,$row['id'],$company]);
                        if($row['payment_currency']!==null&&$row['payment_amount']!==null)paymentStoreExchangeSnapshot($db,(int)$row['id'],paymentExchangeSnapshot($invoice,['currency_code'=>$row['payment_currency'],'primary_code'=>$row['primary_code'],'alternate_code'=>$row['alternate_code'],'exchange_rate'=>$row['payment_rate']],$row['amount'],$row['payment_amount']));
                        $db->commit();
                    }catch(Throwable $e){$db->rollBack();throw $e;}
                }
            }
        }
    }finally{$db->prepare('SET @nexo_company_id=?,@nexo_actor_id=?')->execute([$context['company_id'],$context['actor_id']]);}
};

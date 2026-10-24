<?php
declare(strict_types=1);

function restaurantReceiptSnapshot(PDO $db,array $customer,int $companyId,string $source='issued'): array
{
    $q=$db->prepare('SELECT name,legal_name,tax_id,address,phone FROM business_company WHERE id=?');$q->execute([$companyId]);$company=$q->fetch();
    if(!$company)throw new RuntimeException('No se pudo conservar la identidad de la empresa del comprobante.');
    return ['source'=>$source,'captured_at'=>(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s'),
        'customer'=>['name'=>$customer['name'],'tax_id'=>$customer['tax_id']??''],'company'=>$company];
}

/** Freeze the best available identity for old receipts; never invent their original identity. */
function installReceiptSnapshots(PDO $db): int
{
    if(!$db->query("SHOW TABLES LIKE 'restaurant_account_carts'")->fetch())return 0;
    if(!$db->query("SHOW COLUMNS FROM restaurant_account_carts LIKE 'receipt_snapshot'")->fetch())$db->exec('ALTER TABLE restaurant_account_carts ADD receipt_snapshot LONGTEXT NULL');
    $own=!$db->inTransaction();if($own)$db->beginTransaction();
    $context=$db->query('SELECT @nexo_company_id AS company,@nexo_actor_id AS actor')->fetch();
    try{
        $rows=$db->query('SELECT r.account_id,r.company_id,c.name,c.tax_id FROM restaurant_account_carts r JOIN accounting_invoices i ON i.id=r.invoice_id JOIN contacts c ON c.id=i.contact_id WHERE r.receipt_snapshot IS NULL ORDER BY r.account_id')->fetchAll();$count=0;
        foreach($rows as $row){
            $db->exec('SET @nexo_company_id='.(int)$row['company_id'].', @nexo_actor_id=NULL');
            $snapshot=restaurantReceiptSnapshot($db,$row,(int)$row['company_id'],'legacy_upgrade');
            $q=$db->prepare('UPDATE restaurant_account_carts SET receipt_snapshot=? WHERE account_id=? AND receipt_snapshot IS NULL');
            $q->execute([json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['account_id']]);$count+=$q->rowCount();
        }
        if($own)$db->commit();return $count;
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->exec('SET @nexo_company_id='.($context['company']===null?'NULL':(int)$context['company']).', @nexo_actor_id='.($context['actor']===null?'NULL':(int)$context['actor']));}
}

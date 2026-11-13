<?php
declare(strict_types=1);
require_once __DIR__.'/currency-repository.php';
require_once __DIR__.'/company-connection.php';
require_once __DIR__.'/workflow-repository.php';

/** Public BCV quotes only. Never copy another company's manual exchange rates. */
function bcvStoredPublications(PDO $db): array
{
    if($db instanceof CompanyConnection)throw new LogicException('La inicialización BCV requiere la conexión administrativa.');
    $q=$db->prepare("SELECT r.rate_date,r.rate,r.fetched_at FROM accounting_currency_rates r
        JOIN accounting_currencies c ON c.id=r.currency_id AND c.company_id=r.company_id
        JOIN accounting_currencies b ON b.id=r.base_currency_id AND b.company_id=r.company_id
        WHERE r.source='bcv' AND b.code='USD' AND c.code='VES' AND r.rate>0
        AND (r.rate_date>? OR r.rate_date=(SELECT MAX(p.rate_date) FROM accounting_currency_rates p
            JOIN accounting_currencies pc ON pc.id=p.currency_id AND pc.company_id=p.company_id
            JOIN accounting_currencies pb ON pb.id=p.base_currency_id AND pb.company_id=p.company_id
            WHERE p.source='bcv' AND pb.code='USD' AND pc.code='VES' AND p.rate>0 AND p.rate_date<=?))
        ORDER BY r.rate_date,r.fetched_at DESC,r.id DESC");
    $q->execute([commerceToday(),commerceToday()]);$publications=[];
    foreach($q->fetchAll() as $row)if(!isset($publications[$row['rate_date']]))$publications[$row['rate_date']]=$row;
    return array_values($publications);
}

/** Seed a company without a current USD/VES rate, within its own audit context. */
function bcvSeedCompanyRates(PDO $db,int $company,?array $publications=null): int
{
    if($db instanceof CompanyConnection||$company<=0)throw new LogicException('Contexto administrativo BCV inválido.');
    $publications??=bcvStoredPublications($db);
    if(!$publications)return 0;
    $context=$db->query('SELECT @nexo_company_id AS company_id,@nexo_actor_id AS actor_id')->fetch();
    $ownTransaction=!$db->inTransaction();
    if($ownTransaction)$db->beginTransaction();
    try {
        $db->exec('SET @nexo_company_id='.$company.', @nexo_actor_id=NULL');
        $q=$db->prepare("SELECT code,id FROM accounting_currencies WHERE company_id=? AND code IN('USD','VES') FOR UPDATE");
        $q->execute([$company]);$ids=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        if(count($ids)!==2||currencyRateSnapshot($db,'USD','VES',commerceToday(),$company)!==null){
            if($ownTransaction)$db->commit();return 0;
        }
        $exists=$db->prepare('SELECT 1 FROM accounting_currency_rates WHERE company_id=? AND rate_date=? AND ((currency_id=? AND base_currency_id=?) OR (currency_id=? AND base_currency_id=?))');
        $insert=$db->prepare("INSERT INTO accounting_currency_rates(company_id,currency_id,base_currency_id,rate_date,rate,source,fetched_at) VALUES(?,?,?,?,?,'bcv',?)");
        $count=0;
        foreach($publications as $publication){
            $exists->execute([$company,$publication['rate_date'],$ids['VES'],$ids['USD'],$ids['USD'],$ids['VES']]);
            if($exists->fetchColumn())continue;
            $insert->execute([$company,$ids['VES'],$ids['USD'],$publication['rate_date'],$publication['rate'],$publication['fetched_at']]);$count++;
        }
        $q=$db->prepare('SELECT primary_code,alternate_code FROM accounting_currency_settings WHERE company_id=? AND id=1');
        $q->execute([$company]);$settings=$q->fetch();
        if($settings){
            $rate=currencyRateSnapshot($db,$settings['primary_code'],$settings['alternate_code'],commerceToday(),$company)['rate']??null;
            $db->prepare('UPDATE accounting_currency_settings SET exchange_rate=?,version=version+1 WHERE company_id=? AND id=1 AND NOT(exchange_rate<=>?)')->execute([$rate,$company,$rate]);
        }
        if($ownTransaction)$db->commit();return $count;
    } catch(Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
    finally {
        $db->exec('SET @nexo_company_id='.($context['company_id']===null?'NULL':(int)$context['company_id']).', @nexo_actor_id='.($context['actor_id']===null?'NULL':(int)$context['actor_id']));
    }
}

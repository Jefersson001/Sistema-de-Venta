<?php
declare(strict_types=1);
require_once __DIR__.'/bcv-provider.php';
require_once __DIR__.'/connection.php';
require_once __DIR__.'/company-connection.php';
require_once __DIR__.'/currency-repository.php';
require_once __DIR__.'/workflow-repository.php';

function bcvStatePath(): string
{
    $config=require __DIR__.'/config/database.php';return __DIR__.'/runtime/bcv-'.substr(hash('sha256',$config['host'].':'.$config['database']),0,16).'.json';
}
function bcvReadState(): array
{
    $file=bcvStatePath();if(!is_file($file))return [];
    return json_decode(file_get_contents($file)?:'{}',true)?:[];
}
function bcvStoreState(array $state): void
{
    $file=bcvStatePath();$temp=$file.'.tmp-'.bin2hex(random_bytes(4));
    try{if(file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false||!rename($temp,$file))throw new RuntimeException('No se pudo guardar el estado de consulta del BCV.');}
    finally{if(is_file($temp))unlink($temp);}
}
/** A trusted CLI worker imports the public publication into every company's own catalog. */
function bcvApplyPublication(PDO $raw,array $publication): int
{
    $date=$publication['effective_date'];$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $rate=accountingExchangeRate(decimalRound($publication['rate']));
    if(!$parsed||$parsed->format('Y-m-d')!==$date||!$rate||($publication['source']??'')!=='bcv')throw new InvalidArgumentException('Publicación BCV inválida.');
    $count=0;
    foreach($raw->query('SELECT id FROM business_company ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $company){
        // System jobs have no human author; use NULL so the audit FK stays valid.
        $raw->exec('SET @nexo_company_id='.(int)$company.', @nexo_actor_id=NULL');
        $raw->beginTransaction();
        try{
            $q=$raw->prepare("SELECT code,id FROM accounting_currencies WHERE company_id=? AND code IN('USD','VES') FOR UPDATE");$q->execute([$company]);$ids=$q->fetchAll(PDO::FETCH_KEY_PAIR);
            if(count($ids)!==2){$raw->rollBack();continue;}
            $q=$raw->prepare("INSERT INTO accounting_currency_rates(company_id,currency_id,base_currency_id,rate_date,rate,source,fetched_at) VALUES(?,?,?,?,?,'bcv',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE rate=VALUES(rate),source='bcv',fetched_at=CURRENT_TIMESTAMP");
            $q->execute([$company,$ids['VES'],$ids['USD'],$date,$rate]);
            $q=$raw->prepare('SELECT * FROM accounting_currency_settings WHERE company_id=? AND id=1');$q->execute([$company]);$settings=$q->fetch();
            if($settings){$current=currencyRateSnapshot($raw,$settings['primary_code'],$settings['alternate_code'],commerceToday(),(int)$company)['rate']??null;$raw->prepare('UPDATE accounting_currency_settings SET exchange_rate=?,version=version+1 WHERE company_id=? AND id=1 AND NOT(exchange_rate<=>?)')->execute([$current,$company,$current]);}
            $raw->commit();$count++;
        }catch(Throwable $e){if($raw->inTransaction())$raw->rollBack();throw $e;}
    }
    return $count;
}

function bcvSync(bool $force=false,?callable $fetch=null): array
{
    $lock=fopen(bcvStatePath().'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['skipped'=>true];
    try{
        $state=bcvReadState();if(!$force&&time()-($state['attempt_timestamp']??0)<900)return $state;
        $state['attempt_timestamp']=time();$state['attempted_at']=gmdate(DATE_ATOM);
        try{
            $publication=($fetch??'bcvFetchPublication')();$today=commerceToday();
            if($publication['effective_date']>$today&&$publication['effective_date']>(new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d'))throw new RuntimeException('La Fecha Valor del BCV está fuera del rango esperado.');
            $state['companies']=bcvApplyPublication(createDatabaseConnection(true),$publication);
            $state['publication']=$publication;$state['success_at']=gmdate(DATE_ATOM);$state['error']=null;
        }catch(Throwable $e){$state['error']=$e->getMessage();bcvStoreState($state);throw $e;}
        bcvStoreState($state);return $state;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

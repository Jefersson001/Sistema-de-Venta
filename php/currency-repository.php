<?php
declare(strict_types=1);
require_once __DIR__.'/decimal.php';
function accountingCurrencies(PDO $db, ?string $date = null): array
{
    $settings = $db->query('SELECT * FROM accounting_currency_settings WHERE id=1')->fetch();
    $date ??= (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d');
    $settings['exchange_rate'] = currencyRateForPair($db, $settings['primary_code'], $settings['alternate_code'], $date);
    return $settings;
}

function currencyRateForPair(PDO $db, string $from, string $to, string $date): ?string
{
    return currencyRateSnapshot($db,$from,$to,$date)['rate']??null;
}

function currencyRateSnapshot(PDO $db,string $from,string $to,string $date,?int $companyId=null): ?array
{
    if($from===$to)return ['rate'=>'1.00','rate_date'=>$date,'source'=>'same_currency'];
    $companyFilter=$companyId!==null?' AND r.company_id='.(int)$companyId:($db instanceof CompanyConnection?' AND r.company_id='.$db->configurationCompanyId():'');
    $q = $db->prepare('SELECT r.rate,r.rate_date,r.source,b.code AS base_code FROM accounting_currency_rates r JOIN accounting_currencies c ON c.id=r.currency_id JOIN accounting_currencies b ON b.id=r.base_currency_id WHERE r.rate_date<=? AND ((b.code=? AND c.code=?) OR (b.code=? AND c.code=?))'.$companyFilter.' ORDER BY r.rate_date DESC,(b.code=?) DESC,r.id DESC LIMIT 1');
    $q->execute([$date,$from,$to,$to,$from,$from]); $rate = $q->fetch();
    if(!$rate)return null;
    $rate['rate']=$rate['base_code']===$from?$rate['rate']:decimalRound(bcdiv('1',$rate['rate'],10));
    if(bccomp($rate['rate'],'0',2)<=0)throw new InvalidArgumentException('La tasa inversa es menor a 0.01. Registra una tasa directa para las monedas seleccionadas.');
    return $rate;
}
function accountingCurrencyCodes(array $settings): array { return array_values(array_unique([$settings['primary_code'],$settings['alternate_code']])); }
function accountingConvert(string $amount,string $from,string $to,array $settings): string
{
    if($from===$to||bccomp($amount,'0',2)===0)return bcadd($amount,'0',2);
    if(!in_array($from,accountingCurrencyCodes($settings),true)||!in_array($to,accountingCurrencyCodes($settings),true)||!$settings['exchange_rate']||bccomp($settings['exchange_rate'],'0',2)<=0)throw new InvalidArgumentException('Configura una tasa válida para convertir entre las monedas seleccionadas.');
    $value=$from===$settings['primary_code']?bcmul($amount,$settings['exchange_rate'],10):bcdiv($amount,$settings['exchange_rate'],10);
    return bcdiv(bcadd($value,'0.005',10),'1',2);
}
function accountingExchangeRate(mixed $value): ?string
{
    if($value===''||$value===null)return null;
    if(!is_string($value))throw new InvalidArgumentException('Indica una tasa de cambio válida.');$value=str_replace(',','.',trim($value));
    if(!preg_match('/^[0-9]{1,12}(\.[0-9]{1,2})?$/',$value)||bccomp($value,'0',2)<=0)throw new InvalidArgumentException('La tasa debe ser positiva, con hasta dos decimales.');
    return bcadd($value,'0',2);
}

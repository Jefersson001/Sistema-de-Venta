<?php
declare(strict_types=1);
function accountingCurrencies(PDO $db): array { return $db->query('SELECT * FROM accounting_currency_settings WHERE id=1')->fetch(); }
function accountingCurrencyCodes(array $settings): array { return array_values(array_unique([$settings['primary_code'],$settings['alternate_code']])); }
function accountingConvert(string $amount,string $from,string $to,array $settings): string
{
    if($from===$to||bccomp($amount,'0',2)===0)return bcadd($amount,'0',2);
    if(!in_array($from,accountingCurrencyCodes($settings),true)||!in_array($to,accountingCurrencyCodes($settings),true)||!$settings['exchange_rate']||bccomp($settings['exchange_rate'],'0',8)<=0)throw new InvalidArgumentException('Configura una tasa válida para convertir entre las monedas seleccionadas.');
    $value=$from===$settings['primary_code']?bcmul($amount,$settings['exchange_rate'],10):bcdiv($amount,$settings['exchange_rate'],10);
    return bcdiv(bcadd($value,'0.005',10),'1',2);
}
function accountingExchangeRate(mixed $value): ?string
{
    if($value===''||$value===null)return null;
    if(!is_string($value))throw new InvalidArgumentException('Indica una tasa de cambio válida.');$value=str_replace(',','.',trim($value));
    if(!preg_match('/^[0-9]{1,12}(\.[0-9]{1,8})?$/',$value)||bccomp($value,'0',8)<=0)throw new InvalidArgumentException('La tasa debe ser positiva, con hasta ocho decimales.');
    return bcadd($value,'0',8);
}

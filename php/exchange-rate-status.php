<?php
declare(strict_types=1);
require_once __DIR__.'/bcv-sync.php';

function dailyExchangeRateStatus(PDO $db): array
{
    $settings=accountingCurrencies($db);$today=commerceToday();
    $snapshot=currencyRateSnapshot($db,$settings['primary_code'],$settings['alternate_code'],$today);
    $state=bcvReadState();$source=$snapshot?(($snapshot['source']??'')==='bcv'?'BCV':'Manual'):'Sin tasa';
    $label=$snapshot?('Tasa '.($snapshot['rate_date']===$today?'del día':'vigente').' · 1 '.$settings['primary_code'].' = '.rtrim(rtrim($snapshot['rate'],'0'),'.').' '.$settings['alternate_code']):'Tasa del día: no disponible';
    $title=$snapshot?($source.' · Fecha valor: '.$snapshot['rate_date'].' · Empresa principal'):'No hay una tasa vigente guardada para las monedas de esta empresa.';
    if(!empty($state['publication'])&&$state['publication']['effective_date']>$today)$title.=' · Próxima publicación BCV: '.$state['publication']['effective_date'];
    if(!empty($state['error']))$title.=' · La última consulta al BCV falló; se conserva la tasa guardada.';
    return ['label'=>$label,'title'=>$title,'available'=>$snapshot!==null,'source'=>$source,'rate'=>$snapshot['rate']??null,'rate_date'=>$snapshot['rate_date']??null,'company_id'=>companyActiveId(),'scope'=>companyScopeToken(),'sync_failed'=>!empty($state['error'])];
}

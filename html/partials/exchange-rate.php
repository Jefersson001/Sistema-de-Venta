<?php
if(isset($GLOBALS['company_context'])&&$databaseStatus['connected']){
    require_once dirname(__DIR__,2).'/php/exchange-rate-status.php';
    try{$dailyRate=dailyExchangeRateStatus(createDatabaseConnection());}
    catch(Throwable $e){error_log('Tasa del día: '.$e->getMessage());$dailyRate=['label'=>'Tasa del día: no disponible','title'=>'No se pudo consultar la tasa guardada.','available'=>false,'sync_failed'=>false];}
    $rateParts=explode(' · ',$dailyRate['label'],2);
    $rateValue=$dailyRate['available']?($rateParts[1]??$dailyRate['label']):'Sin tasa disponible';
    $rateDate=$dailyRate['rate_date']??'';
    if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$rateDate,$dateParts))$rateDate=$dateParts[3].'/'.$dateParts[2].'/'.$dateParts[1];
    $rateDateLabel=$dailyRate['available']?($rateParts[0].($rateDate?' · '.$rateDate:'')):'Registra una tasa en Monedas';
?>
<span class="exchange-rate-card <?= $dailyRate['available']?'connection-online':'connection-offline' ?>" role="group" aria-label="Tasa de cambio de la empresa principal" data-daily-rate data-rate-endpoint="<?= authEscape(authBasePath().'/tasa.php') ?>" title="<?= authEscape($dailyRate['title']) ?>">
    <svg class="exchange-rate-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/></svg>
    <span class="exchange-rate-copy"><span class="exchange-rate-caption">Tasa de cambio <span class="exchange-rate-source" data-daily-rate-source <?= !$dailyRate['available']?'hidden':'' ?>><?= authEscape($dailyRate['source']??'') ?></span></span>
    <strong data-daily-rate-label><?= authEscape($rateValue) ?></strong>
    <small data-daily-rate-date><?= authEscape($rateDateLabel.(!empty($dailyRate['sync_failed'])?' · Consulta pendiente':'')) ?></small></span>
</span>
<script src="<?= authEscape(authBasePath().'/js/exchange-rate.js?v='.(string)filemtime(dirname(__DIR__,2).'/js/exchange-rate.js')) ?>" defer></script>
<?php } ?>

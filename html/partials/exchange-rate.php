<?php
if(isset($GLOBALS['company_context'])&&$databaseStatus['connected']){
    require_once dirname(__DIR__,2).'/php/exchange-rate-status.php';
    try{$dailyRate=dailyExchangeRateStatus(createDatabaseConnection());}
    catch(Throwable $e){error_log('Tasa del día: '.$e->getMessage());$dailyRate=['label'=>'Tasa del día: no disponible','title'=>'No se pudo consultar la tasa guardada.','available'=>false,'sync_failed'=>false];}
?>
<span class="connection-pill daily-exchange-rate <?= $dailyRate['available']?'connection-online':'connection-offline' ?>" data-daily-rate data-rate-endpoint="<?= authEscape(authBasePath().'/tasa.php') ?>" title="<?= authEscape($dailyRate['title']) ?>">
    <span data-daily-rate-label><?= authEscape($dailyRate['label']) ?></span>
    <small data-daily-rate-date><?= authEscape(($dailyRate['rate_date']??'').' · '.($dailyRate['source']??'Sin tasa').(!empty($dailyRate['sync_failed'])?' · Consulta pendiente':'')) ?></small>
</span>
<script src="<?= authEscape(authBasePath().'/js/exchange-rate.js?v='.(string)filemtime(dirname(__DIR__,2).'/js/exchange-rate.js')) ?>" defer></script>
<?php } ?>

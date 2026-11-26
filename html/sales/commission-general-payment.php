<?php $record=$page['record']; ?>
<form class="contact-filters" action="pagos-comisiones.php"><input type="hidden" name="new" value="1">
    <label>Vendedor<select name="seller_id" data-auto-submit><option value="">Selecciona un vendedor</option><?php foreach($page['sellers'] as $seller): ?><option value="<?= (int)$seller['id'] ?>" <?= $page['sellerId']===(int)$seller['id']?'selected':'' ?>><?= $e($seller['name']) ?></option><?php endforeach; ?></select></label>
    <?php if($page['balances']): ?><label>Moneda de la comisión<select name="commission_currency" data-auto-submit><?php foreach($page['balances'] as $code=>$balance): ?><option value="<?= $e($code) ?>" <?= $page['commissionCurrency']===$code?'selected':'' ?>><?= $e($code) ?></option><?php endforeach; ?></select></label><?php endif; ?>
</form>
<p class="section-subtitle">Saldo acumulado de todas las fechas. Puedes pagar sin esperar el cobro al cliente, hasta el importe neto generado menos lo ya pagado. El pago se distribuye entre las facturas más antiguas.</p>
<?php if($record): $balance=$page['balances'][$page['commissionCurrency']]; ?>
<div class="contact-sheet"><dl class="quotation-totals commission-payment-summary" aria-label="Saldo acumulado del vendedor">
    <?php foreach(['generated'=>'Generado neto acumulado','paid'=>'Pagado acumulado','pending'=>'Disponible para pagar','recoverable'=>'Saldo por compensar'] as $key=>$label): ?><div class="commission-payment-summary-<?= $key ?>"><dt><?= $label ?></dt><dd><?= $e($balance[$key].' '.$record['currency_code']) ?></dd></div><?php endforeach; ?>
</dl>
<?php if(bccomp($record['commission_pending'],'0',2)>0): $input=$page['formInput']; ?>
<form method="post" action="<?= $e($page['paymentAction']) ?>" enctype="multipart/form-data" <?php require __DIR__.'/commission-payment-rate-attributes.php'; ?>><?php require __DIR__.'/commission-payment-fields.php'; ?><div class="contact-form-actions"><button class="contact-button contact-button-primary">Confirmar pago al vendedor</button><a class="contact-button" href="pagos-comisiones.php">Cancelar</a></div></form>
<script type="application/json" data-currency-settings><?= json_encode($page['settings'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script src="../../js/currency.js" defer></script><script type="application/json" data-commission-settings><?= json_encode(['invoice_currency'=>$record['currency_code']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script src="../../js/commissions.js?v=<?= filemtime(dirname(__DIR__,2).'/js/commissions.js') ?>" defer></script>
<?php else: ?><p class="notice">Este vendedor no tiene saldo disponible en esta moneda. Las nuevas comisiones aumentarán su saldo y compensarán cualquier exceso anterior.</p><?php endif; ?></div>
<?php elseif($page['sellerId']): ?><p class="notice">Este vendedor todavía no tiene comisiones generadas en facturas registradas.</p><?php endif; ?>

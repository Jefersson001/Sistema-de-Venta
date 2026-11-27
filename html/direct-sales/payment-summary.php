<?php if(bccomp($debt['pending'],'0',2)>0): ?><p class="direct-payment-summary">
<span>En revisión: <strong><?= $e($debt['pending'].' '.$collectionCurrency) ?></strong></span>
<span>Por cubrir: <strong><?= $e((bccomp($toCover,'0',2)>0?$toCover:'0.00').' '.$collectionCurrency) ?></strong></span>
</p><?php endif; ?>
<?php if(isset($_GET['payment_registered'])): ?><p class="notice" role="status">Cobro registrado. En revisión del jefe; aún no está confirmado.</p><?php endif; ?>
<?php if($debt['indexed']): ?><p class="section-subtitle">Factura original: <?= $e($sale['total_amount'].' VES') ?> · Referencia: <?= $e($sale['usd_reference_amount'].' USD') ?>.</p><?php endif; ?>
<?php if($pay['payments']): ?><details class="direct-payment-history"><summary>Cobros registrados (<?= count($pay['payments']) ?>)</summary>
<?php foreach($pay['payments'] as $payment): ?><div class="direct-history-row"><span><?= $e($payment['payment_date'].' · '.$payment['reference']) ?><small><?= $e(['pending'=>'En revisión','settled'=>'Confirmado','cancel'=>'Cancelado'][$payment['status']]??$payment['status']) ?></small></span><strong><?= $e(($payment['usd_reference_amount']??$payment['amount']).' '.$collectionCurrency) ?><small><?= $e($payment['payment_amount'].' '.$payment['payment_currency']) ?></small><?php if($payment['fx_difference']!==null): ?><small>Diferencial: <?= $e($payment['fx_difference'].' '.$payment['fx_currency_code']) ?></small><?php endif; ?></strong></div><?php endforeach; ?>
</details><?php endif; ?>

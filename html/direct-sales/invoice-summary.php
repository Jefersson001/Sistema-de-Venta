<?php $directUnpaid=$detail['payment_state']==='reversed'?'0.00':$balance;$directInFavor=bccomp($directUnpaid,'0',2)<0; ?>
<dl class="workspace-balance-summary direct-invoice-summary" aria-label="Resumen de la factura">
<div><dt>Total de la factura</dt><dd><?= $e($detail['total_amount'].' '.$detail['currency_code']) ?></dd></div>
<div class="workspace-balance-paid"><dt>Pagado<?= $debt['indexed']?' · referencia USD':'' ?></dt><dd><?= $e($debt['settled'].' '.$collectionCurrency) ?></dd></div>
<div class="workspace-balance-due"><dt><?= $directInFavor?'Saldo a favor':'Saldo sin pagar' ?><?= $debt['indexed']?' · referencia USD':'' ?></dt><dd><?= $e(($directInFavor?bcsub('0',$directUnpaid,2):$directUnpaid).' '.$collectionCurrency) ?></dd></div>
</dl>

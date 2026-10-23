<?php if(array_filter(array_intersect_key($currency,array_flip(['cash_received','cash_change','cash_received_reversed','cash_change_recovered'])),static fn($value)=>bccomp($value,'0',2)!==0)): ?>
<dl class="cash-flow-account-totals">
<?php foreach(['cash_received'=>'Efectivo recibido','cash_change'=>'Vuelto entregado','cash_received_reversed'=>'Efectivo recibido revertido','cash_change_recovered'=>'Vuelto recuperado por reversión'] as $key=>$label): if(bccomp($currency[$key],'0',2)===0)continue; ?>
<div><dt><?= $label ?></dt><dd><?= htmlspecialchars($currency[$key],ENT_QUOTES,'UTF-8') ?></dd></div>
<?php endforeach; ?>
</dl><p class="section-subtitle">Los ingresos reflejan el cobro neto. El vuelto se identifica por separado.</p>
<?php endif; ?>

<?php $fxTotals=paymentExchangeTotals($fxAllocations);if($fxTotals): ?>
<dl class="quotation-totals payment-exchange-totals">
    <?php foreach($fxTotals as $fxCode=>$fxTotal): ?><div><dt>Diferencial confirmado (<?= accountingEscape($fxCode) ?>)</dt><dd><?= accountingEscape($fxTotal.' '.$fxCode) ?></dd></div><?php endforeach; ?>
</dl>
<p class="section-subtitle">Diferencia de los abonos confirmados frente a la tasa original. No aumenta el saldo del documento ni constituye un excedente del pago.</p>
<?php endif; ?>

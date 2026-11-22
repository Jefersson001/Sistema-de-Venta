<?php if (!empty($fxAllocation['fx_currency_code'])): $fxInvoiceCurrency=isset($fxAllocation['usd_reference_amount'])?'USD':$fxInvoiceCurrency; ?>
<details class="payment-exchange-detail">
    <summary>Diferencial: <?= accountingEscape($fxAllocation['fx_difference'].' '.$fxAllocation['fx_currency_code']) ?></summary>
    <dl>
        <?php if(isset($fxAllocation['usd_reference_amount'])): ?><div><dt>Referencia cubierta</dt><dd><?= accountingEscape($fxAllocation['usd_reference_amount'].' USD') ?></dd></div><?php endif; ?>
        <div><dt>Tasa original · 1 <?= accountingEscape($fxInvoiceCurrency) ?></dt><dd><?= accountingEscape(rtrim(rtrim($fxAllocation['fx_invoice_rate'],'0'),'.').' '.$fxAllocation['fx_currency_code']) ?></dd></div>
        <div><dt>Tasa del pago · 1 <?= accountingEscape($fxInvoiceCurrency) ?></dt><dd><?= accountingEscape(rtrim(rtrim($fxAllocation['fx_payment_rate'],'0'),'.').' '.$fxAllocation['fx_currency_code']) ?></dd></div>
        <div><dt>Valor histórico del abono</dt><dd><?= accountingEscape($fxAllocation['fx_historical_amount'].' '.$fxAllocation['fx_currency_code']) ?></dd></div>
        <div><dt>Importe aplicado del pago</dt><dd><?= accountingEscape($fxAllocation['fx_settlement_amount'].' '.$fxAllocation['fx_currency_code']) ?></dd></div>
    </dl>
    <small><?= $fxAllocation['status']==='settled'?'Confirmado':($fxAllocation['status']==='pending'?'Pendiente de confirmar':'Retirado: excluido de los totales') ?>. Comparación con la tasa original; no modifica el importe de la factura.</small>
</details>
<?php endif; ?>

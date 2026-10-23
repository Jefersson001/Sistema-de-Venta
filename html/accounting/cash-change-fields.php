<?php
$cashReceived=$changeRecord['received_amount']??$changeRecord['amount']??'0.00';
$cashChange=$changeRecord['change_amount']??'0.00';
?>
<div class="contact-form-grid">
<label data-cash-change-field hidden>Efectivo recibido *<input name="received_amount" inputmode="decimal" maxlength="19" value="<?= accountingEscape($cashReceived) ?>" disabled><small>Importe entregado por el cliente, en la moneda del pago.</small></label>
<label data-cash-change-field hidden>Vuelto entregado<input name="change_amount" inputmode="decimal" maxlength="19" value="<?= accountingEscape($cashChange) ?>" disabled><small>Sale de la misma caja y en la misma moneda. Puedes dejarlo en cero.</small></label>
<p class="section-subtitle contact-full" data-cash-change-field hidden>El pago neto es el recibido menos el vuelto. Si dejas el vuelto en cero, cualquier excedente de la factura queda disponible a favor del contacto.</p>
<p class="notice notice-error contact-full" data-cash-change-error role="alert" hidden></p>
</div>

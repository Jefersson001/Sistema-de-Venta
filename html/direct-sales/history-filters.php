<form method="get" class="direct-history-filters">
<input type="hidden" name="view" value="history">
<label>Estado de factura<select name="status">
<option value="all" <?= $page['status_filter']==='all'?'selected':'' ?>>Todos los estados</option>
<?php foreach(['Vencimiento'=>['overdue'=>'Vencidas'],'Estado de factura'=>invoiceStatuses()+['sent'=>'Enviado'],'Estado de pago'=>invoicePaymentStates()] as $group=>$options): ?>
<optgroup label="<?= $e($group) ?>"><?php foreach($options as $value=>$label): ?><option value="<?= $e($value) ?>" <?= $page['status_filter']===$value?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></optgroup>
<?php endforeach; ?>
</select></label>
<noscript><button class="contact-button contact-button-primary" type="submit">Filtrar</button></noscript>
<?php if($page['status_filter']!=='all'): ?><a class="contact-button" href="index.php?view=history">Limpiar</a><?php endif; ?>
</form>

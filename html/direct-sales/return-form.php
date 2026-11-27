<?php if(!$page['can_return']): ?>
<p class="notice">Esta venta no tiene productos disponibles para devolver o su factura ya no está registrada.</p>
<a class="contact-button" href="index.php?id=<?= (int)$page['sale']['id'] ?>">Volver a la venta</a>
<?php else: ?>
<form method="post" class="direct-simple-form" data-direct-return-form>
<input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="action" value="direct_return"><input type="hidden" name="sale_id" value="<?= (int)$page['sale']['id'] ?>"><input type="hidden" name="request_key" value="<?= $e($page['input']['request_key']??$page['key']) ?>">
<label>Tipo de devolución<select name="return_mode"><option value="total" <?= $page['return_mode']==='total'?'selected':'' ?>>Devolver toda la venta pendiente</option><option value="partial" <?= $page['return_mode']==='partial'?'selected':'' ?>>Devolución parcial</option></select></label>
<p>En la devolución total se incluyen automáticamente todos los productos pendientes. En la parcial, indica las cantidades recibidas del cliente.</p>
<?php foreach($page['lines'] as $line): if(bccomp($line['returnable'],'0',2)<=0)continue; ?>
<label><?= $e($line['product_name']) ?><small>Facturado: <?= $e($line['quantity']) ?> · Disponible para devolver: <?= $e($line['returnable']) ?></small>
<input type="number" inputmode="<?= $line['tracking_mode']==='serial'?'numeric':'decimal' ?>" min="0" max="<?= $e($line['returnable']) ?>" step="<?= $line['tracking_mode']==='serial'?'1':'0.01' ?>" name="quantities[<?= (int)$line['id'] ?>]" value="<?= $e($page['return_mode']==='total'?$line['returnable']:($page['input']['quantities'][$line['id']]??'0')) ?>" data-return-quantity data-return-remaining="<?= $e($line['returnable']) ?>" <?= $page['return_mode']==='total'?'readonly':'' ?>>
</label>
<?php endforeach; ?>
<label>Motivo *<textarea name="reason" maxlength="255" required><?= $e($page['input']['reason']??'') ?></textarea></label>
<p>El supervisor debe confirmar la recepción física. Al confirmar, los productos regresan al inventario y se registra la nota de crédito. Si se devuelve todo, la factura queda revertida. Los pagos recibidos se conservan; no se reembolsa dinero automáticamente.</p>
<div class="direct-actions"><button class="contact-button contact-button-primary" type="submit">Solicitar devolución</button><a class="contact-button" href="index.php?id=<?= (int)$page['sale']['id'] ?>">Volver a la venta</a></div>
</form>
<?php endif; ?>

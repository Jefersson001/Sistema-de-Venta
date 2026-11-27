<div class="notice" role="status">
<strong><?= $page['sale']['payment_state']==='reversed'?'Factura revertida: saldo de venta anulado.':'Devolución parcial: factura ajustada.' ?></strong>
<p>Los productos de esta devolución fueron recibidos en la ubicación de la entrega original. La nota de crédito se registró automáticamente.</p>
<?php if(bccomp($page['refund_amount'],'0',2)>0): ?><p>Importe de esta nota destinado a reembolso: <?= $e($page['refund_amount'].' '.$page['sale']['currency_code']) ?>. Consulta los reembolsos y su estado en la nota de crédito.</p><?php endif; ?>
<a class="contact-button" href="index.php?id=<?= (int)$page['sale']['id'] ?>">Ver venta y estado de factura</a>
</div>

<?php if (!empty($proofs)): ?>
<section class="payment-proof-saved" aria-label="Comprobantes del pago"><h3>Comprobantes del pago</h3>
<?php foreach ($proofs as $proof): ?>
    <div class="payment-proof-row"><span><?= accountingEscape($proof['filename']) ?> <small><?= accountingEscape(number_format($proof['byte_size']/1024,1,',','.')) ?> KB</small></span><a class="contact-button" href="<?= accountingEscape($proofBase.'&proof='.(int)$proof['id']) ?>" target="_blank" rel="noopener">Abrir</a><a class="contact-button" href="<?= accountingEscape($proofBase.'&proof='.(int)$proof['id'].'&download=1') ?>">Descargar</a></div>
<?php endforeach; ?></section>
<?php endif; ?>

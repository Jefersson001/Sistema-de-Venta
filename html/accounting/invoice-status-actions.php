<?php $details = $page['paymentDetails']; $activeAmount = bcadd($details['settled'], $details['pending'], 2); ?>
<div class="quotation-state-actions">
    <?php if ($record['status'] !== 'cancel' && ! $details['reversal'] && bccomp($activeAmount, '0', 2) === 0): ?><form method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int) $record['id'] ?>"><input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>"><input type="hidden" name="action" value="cancel_invoice"><button class="contact-button" type="submit">Cancelar factura</button></form><?php endif; ?>
    <?php if ($record['status'] === 'posted' && ! $details['reversal']): ?>
        <?php if (! $record['sent_at']): ?><form method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int) $record['id'] ?>"><input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>"><input type="hidden" name="action" value="mark_invoice_sent"><button class="contact-button" type="submit" title="Registra el estado de envío; no envía correos">Marcar como enviada</button></form><?php endif; ?>
        <button class="contact-button" type="button" data-invoice-payments-open>Pagos y reversión</button>
    <?php endif; ?>
</div>

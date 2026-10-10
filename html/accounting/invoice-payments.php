<div id="invoice-payments" class="invoice-payments-summary">
    <dl class="quotation-totals">
        <div><dt>Pagado</dt><dd><?= accountingEscape($details['settled'].' '.$record['currency_code']) ?></dd></div>
        <?php if (bccomp($details['pending'], '0', 2) > 0): ?><div><dt>En proceso de pago</dt><dd><?= accountingEscape($details['pending'].' '.$record['currency_code']) ?></dd></div><?php endif; ?>
        <?php if (bccomp($details['credited'], '0', 2) > 0): ?><div><dt>Notas de crédito</dt><dd><?= accountingEscape($details['credited'].' '.$record['currency_code']) ?></dd></div><?php endif; ?>
        <div><dt>Saldo sin pagar</dt><dd><?= accountingEscape(($record['payment_state'] === 'reversed' ? '0.00' : bcsub($details['effective_amount'], $details['settled'], 2)).' '.$record['currency_code']) ?></dd></div>
    </dl>
    <?php if ($page['availablePayments'] && $canRegisterPayment): ?>
        <section class="invoice-outstanding" aria-labelledby="invoice-outstanding-title">
            <h2 id="invoice-outstanding-title">Pagos pendientes de aplicar</h2>
            <?php foreach ($page['availablePayments'] as $payment): ?>
                <form class="invoice-outstanding-row" method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>">
                    <input type="hidden" name="version" value="<?= (int)$record['version'] ?>">
                    <input type="hidden" name="action" value="apply_existing_payment">
                    <input type="hidden" name="existing_payment_id" value="<?= (int)$payment['id'] ?>">
                    <input type="hidden" name="payment_version" value="<?= (int)$payment['version'] ?>">
                    <input type="hidden" name="amount" value="<?= accountingEscape($payment['maximum']) ?>">
                    <div><a class="contact-name" href="pagos.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$payment['id'] ?>"><?= accountingEscape($payment['name']) ?></a><small><?= accountingEscape($payment['payment_date']) ?><?= $payment['reference'] ? ' · '.accountingEscape($payment['reference']) : '' ?></small></div>
                    <div class="invoice-outstanding-amount"><strong><?= accountingEscape($payment['available'].' '.$payment['currency_code']) ?></strong><small>Se aplicarán <?= accountingEscape($payment['maximum'].' '.$payment['currency_code']) ?></small></div>
                    <button class="contact-button" type="submit" aria-label="Agregar <?= accountingEscape($payment['name']) ?> a esta factura">Agregar</button>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ($details['payments']): ?>
        <details class="invoice-payment-history"><summary>Historial de pagos</summary>
            <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Referencia / Pago</th><th scope="col">Importe</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
            <?php foreach ($details['payments'] as $payment): ?><tr><td><?= accountingEscape($payment['payment_date']) ?></td><td><?= accountingEscape($payment['reference']) ?><?php if ($payment['payment_id']): ?> · <a class="contact-name" href="pagos.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$payment['payment_id'] ?>"><?= accountingEscape($payment['payment_name']) ?></a><?php endif; ?></td><td><?= accountingEscape($payment['amount'].' '.$record['currency_code']) ?><small><?= accountingEscape($payment['payment_amount'].' '.$payment['payment_currency']) ?></small></td><td><?= match ($payment['status']) { 'pending' => 'En proceso de pago', 'settled' => 'Confirmado', default => 'Cancelado' } ?></td><td>
                <?php if ($record['status'] === 'posted' && $record['payment_state'] !== 'reversed' && $payment['status'] !== 'cancel'): ?><form method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>"><input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"><?php if ($payment['status'] === 'pending'): ?><button class="contact-button" type="submit" name="action" value="settle_payment">Confirmar pago</button><?php endif; ?><button class="contact-button" type="submit" name="action" value="cancel_payment">Quitar de factura</button></form><?php endif; ?>
            </td></tr><?php endforeach; ?>
            </tbody></table></div>
        </details>
    <?php endif; ?>
</div>

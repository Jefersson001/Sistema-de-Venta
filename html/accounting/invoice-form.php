<?php
$record = $page['record']; $posted = $record['status'] !== 'draft';
$credit = $record['document_type']==='credit';
$details = $page['paymentDetails'];
$available = bcsub($details['effective_amount'], bcadd($details['settled'], $details['pending'], 2), 2);
$canRegisterPayment = $record['status'] === 'posted' && $record['payment_state'] !== 'reversed' && bccomp($available, '0', 2) > 0;
$canCreateCredit = $record['status'] === 'posted' && !$credit && $record['payment_state'] !== 'reversed';
$statusLabels = invoiceFilterStates();
$activeStates = [$record['status']];
if ($record['status'] === 'posted') {
    $activeStates[] = $record['payment_state'];
    if ($record['sent_at']) $activeStates[] = 'sent';
}
?>
<section aria-labelledby="invoice-title">
    <div class="contact-toolbar invoice-toolbar">
        <div><a class="overview-link" href="index.php?type=<?= $page['type'] ?>">← Facturas</a><h1 id="invoice-title"><?= accountingEscape($record['name']) ?></h1></div>
        <div class="invoice-status-wrap" role="region" aria-label="Estados de la factura" tabindex="0">
            <ol class="quotation-status invoice-status" aria-label="Estado de la factura">
                <?php foreach (['draft', 'posted', 'sent', 'not_paid', 'in_payment', 'partial', 'paid', 'reversed', 'cancel'] as $key): ?>
                    <?php $active = in_array($key, $activeStates, true); ?>
                    <li class="<?= $active ? 'is-active' : '' ?>" <?= $record['status'] === $key ? 'aria-current="step"' : '' ?>><?= accountingEscape($statusLabels[$key]) ?><?php if ($active): ?><span class="visually-hidden"> (activo)</span><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php if($credit): ?><p class="notice">Nota de crédito · <?= accountingEscape($record['credit_reason']) ?> · <a href="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['reversal_of_id'] ?>">Factura original</a></p><?php endif; ?>
    <?php foreach($page['paymentDetails']['credits'] as $creditDocument): ?><p class="notice"><a href="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$creditDocument['id'] ?>">Nota de crédito <?= accountingEscape($creditDocument['name']) ?></a> · <?= invoiceStatuses()[$creditDocument['status']] ?></p><?php endforeach; ?>
    <div class="document-actions" role="group" aria-label="Acciones de la factura">
        <?php $documentView = 'invoice'; require dirname(__DIR__) . '/commerce/links.php'; ?>
        <?php require __DIR__ . '/invoice-status-actions.php'; ?>
    </div>
    <div class="contact-sheet quotation-sheet"><form id="invoice-details" method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int) $record['id'] ?>">
        <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>">
        <fieldset <?= $posted ? 'disabled' : '' ?> class="quotation-fields"><div class="contact-form-grid">
            <label><?= $page['type'] === 'customer' ? 'Cliente' : 'Proveedor' ?><input readonly value="<?= accountingEscape($record['contact_name']) ?>"></label><label>Fecha de factura *<input type="date" name="invoice_date" required value="<?= accountingEscape($record['invoice_date']) ?>"></label>
            <label>Moneda<input readonly value="<?= accountingEscape($record['currency_code']) ?>"></label><label>Fecha de vencimiento<input type="date" name="due_date" value="<?= accountingEscape($record['due_date'] ?? '') ?>"></label><label>Términos de pago<input readonly value="<?= accountingEscape($record['term_name'] ?? '') ?>"></label><label>Dirección de factura<textarea readonly rows="3"><?= accountingEscape($record['invoice_address']) ?></textarea></label>
        </div></fieldset></form><div class="contact-notebook" data-notebook><nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la factura"><a id="invoice-tab-lines" href="#invoice-lines">Líneas de factura</a><a id="invoice-tab-other" href="#invoice-other">Otra información</a></nav>
            <section class="notebook-page" id="invoice-lines" data-notebook-page aria-labelledby="invoice-tab-lines"><div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Impuesto</th><th scope="col">Base imponible</th><th scope="col">Total</th></tr></thead><tbody><?php foreach ($page['lines'] as $line): ?><tr><td><a class="contact-name" href="../inventario/index.php?id=<?= (int) $line['product_id'] ?>"><?= accountingEscape($line['product_name']) ?></a></td><td><?php if($credit && !$posted): ?><input form="invoice-details" name="credit_quantities[<?= (int)$line['id'] ?>]" inputmode="decimal" value="<?= accountingEscape($line['quantity']) ?>" aria-label="Cantidad a revertir"><?php else: ?><?= accountingEscape($line['quantity']) ?><?php endif; ?></td><td><?= accountingEscape($line['unit_price']) ?></td><td><?= accountingEscape($line['tax_name'] ?? '—') ?></td><td><?= accountingEscape($line['base_amount']) ?></td><td><?= accountingEscape($line['total_amount']) ?></td></tr><?php endforeach; ?></tbody></table></div>
                <p class="section-subtitle">Los porcentajes e importes se conservan desde la orden de origen.</p><dl class="quotation-totals"><div><dt>Base imponible</dt><dd><?= accountingEscape($record['base_amount']) ?></dd></div><div><dt>Total</dt><dd><?= accountingEscape($record['total_amount']) ?></dd></div></dl>
                <?php require __DIR__ . '/invoice-payments.php'; ?>
            </section><section class="notebook-page" id="invoice-other" data-notebook-page aria-labelledby="invoice-tab-other"><div class="contact-form-grid"><?php if ($page['type'] === 'customer'): ?><label>Vendedor relacionado<input readonly value="<?= accountingEscape($record['seller_name'] ?? '') ?>"></label><?php endif; ?><label>Documento de origen<input readonly value="<?= accountingEscape($page['links']['source']['name']) ?>"></label></div></section>
        </div><div class="contact-form-actions"><?php if (! $posted): ?><button class="contact-button" type="submit" form="invoice-details" name="action" value="save_invoice">Guardar factura</button><button class="contact-button contact-button-primary" type="submit" form="invoice-details" name="action" value="confirm_invoice">Registrar factura</button><?php endif; ?><a class="contact-button" href="index.php?type=<?= $page['type'] ?>">Volver al listado</a></div>
    </div>
    <?php require __DIR__ . '/invoice-payment-wizard.php'; ?>
</section>

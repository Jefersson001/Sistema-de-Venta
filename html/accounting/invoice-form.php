<?php
$record = $page['record']; $posted = $record['status'] !== 'draft';
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
    <?php $documentView = 'invoice'; require dirname(__DIR__) . '/commerce/links.php'; ?>
    <?php require __DIR__ . '/invoice-status-actions.php'; ?>
    <div class="contact-sheet quotation-sheet"><form id="invoice-details" method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int) $record['id'] ?>">
        <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>">
        <fieldset <?= $posted ? 'disabled' : '' ?> class="quotation-fields"><div class="contact-form-grid">
            <label><?= $page['type'] === 'customer' ? 'Cliente' : 'Proveedor' ?><input readonly value="<?= accountingEscape($record['contact_name']) ?>"></label><label>Fecha de factura *<input type="date" name="invoice_date" required value="<?= accountingEscape($record['invoice_date']) ?>"></label>
            <label>Fecha de vencimiento<input type="date" name="due_date" value="<?= accountingEscape($record['due_date'] ?? '') ?>"></label><label>Términos de pago<input readonly value="<?= accountingEscape($record['term_name'] ?? '') ?>"></label><label>Dirección de factura<textarea readonly rows="3"><?= accountingEscape($record['invoice_address']) ?></textarea></label>
        </div></fieldset></form><div class="contact-notebook" data-notebook><nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la factura"><a id="invoice-tab-lines" href="#invoice-lines">Líneas de factura</a><a id="invoice-tab-other" href="#invoice-other">Otra información</a><a id="invoice-tab-payments" href="#invoice-payments">Pagos y reversión</a></nav>
            <section class="notebook-page" id="invoice-lines" data-notebook-page aria-labelledby="invoice-tab-lines"><div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Impuesto</th><th scope="col">Base imponible</th><th scope="col">Total</th></tr></thead><tbody><?php foreach ($page['lines'] as $line): ?><tr><td><a class="contact-name" href="../inventario/index.php?id=<?= (int) $line['product_id'] ?>"><?= accountingEscape($line['product_name']) ?></a></td><td><?= accountingEscape($line['quantity']) ?></td><td><?= accountingEscape($line['unit_price']) ?></td><td><?= accountingEscape($line['tax_name'] ?? '—') ?></td><td><?= accountingEscape($line['base_amount']) ?></td><td><?= accountingEscape($line['total_amount']) ?></td></tr><?php endforeach; ?></tbody></table></div>
                <p class="section-subtitle">Los impuestos tienen solo nombre. Los importes todavía no incluyen impuestos.</p><dl class="quotation-totals"><div><dt>Base imponible</dt><dd><?= accountingEscape($record['base_amount']) ?></dd></div><div><dt>Total sin impuestos</dt><dd><?= accountingEscape($record['total_amount']) ?></dd></div></dl>
            </section><section class="notebook-page" id="invoice-other" data-notebook-page aria-labelledby="invoice-tab-other"><div class="contact-form-grid"><?php if ($page['type'] === 'customer'): ?><label>Vendedor relacionado<input readonly value="<?= accountingEscape($record['seller_name'] ?? '') ?>"></label><?php endif; ?><label>Documento de origen<input readonly value="<?= accountingEscape($page['links']['source']['name']) ?>"></label></div></section>
            <section class="notebook-page" id="invoice-payments" data-notebook-page aria-labelledby="invoice-tab-payments"><?php require __DIR__ . '/invoice-payments.php'; ?></section>
        </div><div class="contact-form-actions"><?php if (! $posted): ?><button class="contact-button" type="submit" form="invoice-details" name="action" value="save_invoice">Guardar factura</button><button class="contact-button contact-button-primary" type="submit" form="invoice-details" name="action" value="confirm_invoice">Registrar factura</button><?php endif; ?><a class="contact-button" href="index.php?type=<?= $page['type'] ?>">Volver al listado</a></div>
    </div>
</section>

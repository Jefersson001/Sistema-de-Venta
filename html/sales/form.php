<?php
$record = $page['record']; $editing = isset($record['id']); $confirmed = $record['status'] === 'sale'; $cancelled = $record['status'] === 'cancel'; $locked = $confirmed || $cancelled;
$selectedCustomer = salesRecord($page['catalogs']['customers'], salesId($record['customer_id'] ?? null));
$relatedSellerId = salesId($record['salesperson_id'] ?? null) ?? salesId($selectedCustomer['salesperson_id'] ?? null);
$selectedSeller = salesRecord($page['catalogs']['salespeople'], $relatedSellerId);
$storageDisplay = $locked ? ($record['storage_label'] ?? '') : ($selectedSeller['storage_label'] ?? '');
?>
<section aria-labelledby="quotation-title">
    <div class="contact-toolbar"><div><a class="overview-link" href="index.php">← Cotizaciones</a><h1 id="quotation-title"><?= $editing ? salesEscape($record['name']) : 'Nueva cotización' ?></h1></div>
        <ol class="quotation-status" aria-label="Estado de la cotización"><?php foreach (salesStatuses() as $key => $label): ?><li <?= $record['status'] === $key ? 'aria-current="step"' : '' ?>><?= $label ?></li><?php endforeach; ?></ol>
    </div>
    <?php if ($confirmed): ?>
        <?php $documentView = 'order'; require dirname(__DIR__) . '/commerce/links.php'; ?>
    <?php endif; ?>
    <?php if ($editing && ! $locked): ?>
        <form class="quotation-state-actions" method="post" action="index.php?id=<?= (int) $record['id'] ?>" data-quotation-status-form>
            <input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>">
            <?php if ($record['status'] === 'draft'): ?><button class="contact-button" type="submit" name="action" value="mark_sent">Marcar como enviada</button><?php endif; ?>
            <button class="contact-button contact-button-primary" type="submit" name="action" value="confirm_sale">Confirmar orden de venta</button>
            <button class="contact-button" type="submit" name="action" value="cancel_quotation">Cancelar</button>
            <span class="section-subtitle" data-quotation-dirty-help hidden>Guarda los cambios antes de cambiar el estado.</span>
        </form>
    <?php endif; ?>
    <?php if ($confirmed): ?><p class="notice" role="status">Orden de venta confirmada.</p><?php endif; ?>
    <form class="contact-sheet quotation-sheet" method="post" action="index.php?<?= $editing ? 'id=' . (int) $record['id'] : 'new=1' ?>" data-quotation-form>
        <input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_quotation"><input type="hidden" name="version" value="<?= (int) ($record['version'] ?? 0) ?>">
        <fieldset <?= $locked ? 'disabled' : '' ?> class="quotation-fields">
            <div class="contact-form-grid">
                <label>Correlativo<input name="name" value="<?= salesEscape($record['name']) ?>" readonly><small>Se asigna automáticamente al guardar.</small></label>
                <label>Moneda<select name="currency_code" data-order-currency><?php foreach(array_unique(array_merge(accountingCurrencyCodes($page['catalogs']['settings']),[$record['currency_code']??null])) as $code): ?><?php if($code): ?><option value="<?= salesEscape($code) ?>" <?= ($record['currency_code']??$page['catalogs']['settings']['primary_code'])===$code?'selected':'' ?>><?= salesEscape($code) ?></option><?php endif; ?><?php endforeach; ?></select></label>
            <label>Tasa de cambio<input name="exchange_rate" inputmode="decimal" data-order-exchange value="<?= salesEscape($record['exchange_rate']??$page['catalogs']['settings']['exchange_rate']??'') ?>"><small>1 <?= salesEscape($page['catalogs']['settings']['primary_code']) ?> = tasa en <?= salesEscape($page['catalogs']['settings']['alternate_code']) ?>.</small></label>
            <label>Tipo de orden<select name="order_type"><?php foreach (salesOrderTypes() as $value => $label): ?><option value="<?= salesEscape($value) ?>" <?= ($record['order_type'] ?? 'delivery') === $value ? 'selected' : '' ?>><?= salesEscape($label) ?></option><?php endforeach; ?></select></label>
                <label>Cliente *<select name="customer_id" required data-quotation-customer><option value="">Selecciona un cliente</option><?php foreach ($page['catalogs']['customers'] as $customer): ?><option value="<?= (int) $customer['id'] ?>" data-address="<?= salesEscape(salesAddress($customer)) ?>" data-salesperson="<?= (int) ($customer['salesperson_id'] ?? 0) ?>" <?= (int) ($record['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>><?= salesEscape($customer['name']) ?><?= ! $customer['active'] ? ' (archivado)' : '' ?></option><?php endforeach; ?></select></label>
                <label>Dirección de factura<textarea rows="3" readonly data-invoice-address><?= salesEscape($record['invoice_address'] ?? '') ?></textarea></label>
                <label>Dirección de entrega<textarea rows="3" readonly data-delivery-address><?= salesEscape($record['delivery_address'] ?? '') ?></textarea></label>
                <label>Fecha de la orden *<input name="order_date" type="date" required value="<?= salesEscape($record['order_date']) ?>"></label>
                <label>Términos de pago<select name="payment_term_id"><option value="">Sin término de pago</option><?php foreach ($page['catalogs']['terms'] as $term): ?><option value="<?= (int) $term['id'] ?>" <?= (int) ($record['payment_term_id'] ?? 0) === (int) $term['id'] ? 'selected' : '' ?>><?= salesEscape($term['name']) ?></option><?php endforeach; ?></select><small><a class="inline-link" href="../contabilidad/configuracion.php?section=payment_terms">Gestionar términos de pago</a></small></label>
            </div>
            <div class="contact-notebook" data-notebook>
                <nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la cotización"><a id="quotation-tab-lines" href="#quotation-page-lines">Líneas de orden</a><a id="quotation-tab-other" href="#quotation-page-other">Otra información</a></nav>
                <section class="notebook-page" id="quotation-page-lines" data-notebook-page aria-labelledby="quotation-tab-lines">
                    <div class="contact-table-wrap" role="region" aria-label="Líneas de orden" tabindex="0"><table class="contact-table quotation-lines"><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Impuesto</th><th scope="col">Base imponible</th><th scope="col">Total</th><?php if (! $locked): ?><th scope="col"><span class="visually-hidden">Acción</span></th><?php endif; ?></tr></thead><tbody data-quotation-lines>
                        <?php $displayLines = $record['lines'] ?: ($locked ? [] : [[]]); foreach ($displayLines as $lineKey => $line): ?><?php require __DIR__ . '/line.php'; ?><?php endforeach; ?>
                    </tbody></table></div>
                    <?php if (! $locked): ?>
                        <button class="contact-button contact-button-link" type="button" data-add-line hidden>+ Agregar línea</button>
                        <template data-quotation-line-template><?php $lineKey = '__INDEX__'; $line = []; require __DIR__ . '/line.php'; ?></template>
                    <?php endif; ?>
                    <p class="section-subtitle quotation-tax-note">Base imponible: cantidad × precio. El total incluye los impuestos de las líneas.</p>
                    <dl class="quotation-totals" aria-live="polite"><div><dt>Base imponible</dt><dd data-quotation-base><?= salesEscape($record['base_amount']) ?></dd></div><div><dt>Total</dt><dd data-quotation-total><?= salesEscape($record['total_amount']) ?></dd></div></dl>
                </section>
                <section class="notebook-page" id="quotation-page-other" data-notebook-page aria-labelledby="quotation-tab-other">
                    <div class="contact-form-grid">
                        <div><h2 class="notebook-page-title">Ventas</h2><label>Vendedor relacionado<select name="salesperson_id" data-quotation-salesperson><option value="">Sin vendedor asignado</option><?php foreach ($page['catalogs']['salespeople'] as $seller): ?><option value="<?= (int) $seller['id'] ?>" data-storage-label="<?= salesEscape($seller['storage_label']) ?>" <?= $relatedSellerId === (int) $seller['id'] ? 'selected' : '' ?>><?= salesEscape($seller['name']) ?><?= ! $seller['active'] ? ' (archivado)' : '' ?></option><?php endforeach; ?></select><small>Se trae del vendedor asignado en la ficha del cliente. Puedes asignar otro vendedor.</small></label></div>
                        <div><h2 class="notebook-page-title">Entrega</h2><label>Ubicación<input readonly data-quotation-storage data-preserve-storage="<?= $locked ? 'true' : 'false' ?>" value="<?= salesEscape($storageDisplay) ?>" placeholder="Sin almacén relacionado"><small>Se trae del almacén relacionado al vendedor.</small></label></div>
                    </div>
                </section>
            </div>
        </fieldset>
        <div class="contact-form-actions"><?php if (! $locked): ?><button class="contact-button contact-button-primary" type="submit">Guardar cotización</button><?php endif; ?><a class="contact-button" href="index.php">Volver al listado</a></div>
    </form>
</section>
<script type="application/json" data-currency-settings><?= json_encode($page['catalogs']['settings'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script src="../../js/currency.js" defer></script><script src="../../js/sales.js" defer></script>

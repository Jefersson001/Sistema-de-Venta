<?php
$stockLocation = $record[$page['type'] === 'receipt' ? 'destination_location_id' : 'source_location_id'] ?? null;
$available = '0.00';
foreach ($page['stock'] as $balance) {
    if ((int) $balance['product_id'] === (int) ($line['product_id'] ?? 0) && (int) $balance['location_id'] === (int) $stockLocation) { $available = $balance['quantity']; break; }
}
?>
<tr data-operation-line>
    <td><input type="hidden" name="lines[<?= $lineKey ?>][id]" value="<?= (int)($line['id'] ?? 0) ?>"><select <?= $linked ? 'disabled' : '' ?> name="lines[<?= $lineKey ?>][product_id]" aria-label="Producto" data-operation-product><option value="">Selecciona un producto</option><?php foreach ($page['products'] as $product): ?><option data-tracking-mode="<?= inventoryEscape($product['tracking_mode']??'none') ?>" value="<?= (int) $product['id'] ?>" <?= (int) ($line['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= inventoryEscape($product['name']) ?></option><?php endforeach; ?></select></td>
    <td><?php if ($linked): ?><input type="hidden" name="lines[<?= $lineKey ?>][product_id]" value="<?= (int)$line['product_id'] ?>"><?php endif; ?><input <?= $linked ? 'readonly' : '' ?> name="lines[<?= $lineKey ?>][demand]" aria-label="Demanda" inputmode="decimal" maxlength="12" value="<?= inventoryEscape($line['demand'] ?? '1.00') ?>"></td>
    <td><input name="lines[<?= $lineKey ?>][quantity]" aria-label="Cantidad" inputmode="decimal" maxlength="12" value="<?= inventoryEscape($line['quantity'] ?? '0.00') ?>"></td>
    <td><input type="hidden" name="lines[<?= $lineKey ?>][tracking]" data-line-tracking value="<?= inventoryEscape(is_string($line['tracking']??null)?$line['tracking']:json_encode($line['tracking']??[],JSON_THROW_ON_ERROR)) ?>"><?php if($done): ?><a href="#" class="contact-button" data-open-tracking>Ver lotes / series</a><?php else: ?><button class="contact-button" type="button" data-open-tracking>Lotes / series</button><?php endif; ?><small data-tracking-summary></small></td>
    <?php if ($page['type'] !== 'delivery'): ?><td data-operation-available><?= inventoryEscape($available) ?></td><?php endif; ?>
    <?php if (! $done && !$linked): ?><td class="contact-row-action"><button class="contact-button contact-icon-button" type="button" data-operation-remove hidden aria-label="Quitar producto" title="Quitar producto"><?php require dirname(__DIR__) . '/partials/remove-icon.php'; ?></button></td><?php endif; ?>
</tr>

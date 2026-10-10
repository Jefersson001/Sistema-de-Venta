<?php
$stockLocation = $record[$page['type'] === 'receipt' ? 'destination_location_id' : 'source_location_id'] ?? null;
$available = '0.000';
foreach ($page['stock'] as $balance) {
    if ((int) $balance['product_id'] === (int) ($line['product_id'] ?? 0) && (int) $balance['location_id'] === (int) $stockLocation) { $available = $balance['quantity']; break; }
}
?>
<tr data-operation-line>
    <td><input type="hidden" name="lines[<?= $lineKey ?>][id]" value="<?= (int)($line['id'] ?? 0) ?>"><select <?= $linked ? 'disabled' : '' ?> name="lines[<?= $lineKey ?>][product_id]" aria-label="Producto" data-operation-product><option value="">Selecciona un producto</option><?php foreach ($page['products'] as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (int) ($line['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= inventoryEscape($product['name']) ?></option><?php endforeach; ?></select></td>
    <td><?php if ($linked): ?><input type="hidden" name="lines[<?= $lineKey ?>][product_id]" value="<?= (int)$line['product_id'] ?>"><?php endif; ?><input <?= $linked ? 'readonly' : '' ?> name="lines[<?= $lineKey ?>][demand]" aria-label="Demanda" inputmode="decimal" maxlength="13" value="<?= inventoryEscape($line['demand'] ?? '1.000') ?>"></td>
    <td><input name="lines[<?= $lineKey ?>][quantity]" aria-label="Cantidad" inputmode="decimal" maxlength="13" value="<?= inventoryEscape($line['quantity'] ?? '0.000') ?>"></td>
    <?php if ($page['type'] !== 'delivery'): ?><td data-operation-available><?= inventoryEscape($available) ?></td><?php endif; ?>
    <?php if (! $done && !$linked): ?><td><button class="contact-button" type="button" data-operation-remove hidden aria-label="Quitar producto">Quitar</button></td><?php endif; ?>
</tr>

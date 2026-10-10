<?php
$record = $page['record'];
$editing = isset($record['id']);
$tabs = ['general' => 'Información general', 'variants' => 'Variantes', 'sales' => 'Ventas',
    'purchases' => 'Compras', 'inventory' => 'Inventario', 'accounting' => 'Contabilidad'];
?>
<section aria-labelledby="product-title">
    <div class="contact-toolbar"><div><a class="overview-link" href="index.php">← Productos</a><h1 id="product-title"><?= $editing ? 'Editar producto' : 'Nuevo producto' ?></h1></div></div>
    <form class="contact-sheet" method="post" action="index.php?<?= $editing ? 'id=' . (int) $record['id'] : 'new=1' ?>">
        <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_product">
        <div class="product-stat-buttons" role="group" aria-label="Estadísticas del producto" aria-describedby="product-stats-help">
            <?php foreach (['on-hand' => 'Cantidad a la mano', 'sold' => 'Vendido', 'purchased' => 'Comprado', 'incoming' => 'Entrada', 'outgoing' => 'Salida'] as $key => $label): ?>
                <button class="product-stat-button" type="button" disabled data-product-stat="<?= $key ?>">
                    <span class="product-stat-value" aria-label="Sin datos disponibles">—</span>
                    <span class="product-stat-label"><?= $label ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="product-stats-help" id="product-stats-help">Los indicadores estarán disponibles cuando se habiliten las operaciones de inventario, compras y ventas.</p>
        <label>Nombre *<input name="name" required maxlength="160" value="<?= inventoryEscape($record['name'] ?? '') ?>" placeholder="Nombre del producto"></label>
        <div class="product-options" role="group" aria-label="Uso del producto">
            <label><input type="checkbox" name="sale_enabled" value="1" <?= ! empty($record['sale_enabled']) ? 'checked' : '' ?>> Venta</label>
            <label><input type="checkbox" name="purchase_enabled" value="1" <?= ! empty($record['purchase_enabled']) ? 'checked' : '' ?>> Compra</label>
        </div>
        <div class="contact-notebook" data-notebook>
            <nav class="notebook-tabs" data-notebook-tabs aria-label="Información del producto">
                <?php foreach ($tabs as $key => $label): ?><a id="product-tab-<?= $key ?>" href="#product-page-<?= $key ?>"><?= $label ?></a><?php endforeach; ?>
            </nav>
            <?php foreach ($tabs as $key => $label): ?>
                <section class="notebook-page" id="product-page-<?= $key ?>" data-notebook-page aria-labelledby="product-tab-<?= $key ?>"><?php if ($key === 'general'): ?>
                    <div class="contact-form-grid">
                        <label>Precio de venta<input name="sale_price" inputmode="decimal" maxlength="13" value="<?= inventoryEscape($record['sale_price'] ?? '0.00') ?>" placeholder="0.00"></label>
                        <label>Impuesto de venta
                            <select name="sale_tax_id"><option value="">Sin impuesto</option><?php foreach ($page['saleTaxes'] as $tax): ?><option value="<?= (int) $tax['id'] ?>" <?= (int) ($record['sale_tax_id'] ?? 0) === (int) $tax['id'] ? 'selected' : '' ?>><?= inventoryEscape($tax['name']) ?></option><?php endforeach; ?></select>
                            <small><a class="inline-link" href="../ventas/configuracion.php?section=taxes">Gestionar impuestos de venta</a></small>
                        </label>
                        <label>Impuesto de compra
                            <select name="purchase_tax_id"><option value="">Sin impuesto</option><?php foreach ($page['purchaseTaxes'] as $tax): ?><option value="<?= (int) $tax['id'] ?>" <?= (int) ($record['purchase_tax_id'] ?? 0) === (int) $tax['id'] ? 'selected' : '' ?>><?= inventoryEscape($tax['name']) ?></option><?php endforeach; ?></select>
                            <small><a class="inline-link" href="../compras/configuracion.php?section=taxes">Gestionar impuestos de compra</a></small>
                        </label>
                        <label class="contact-full">Categoría
                            <select name="category_id"><option value="">Sin categoría</option><?php foreach ($page['categories'] as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) ($record['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= inventoryEscape(inventoryCategoryPath($page['categories'], $category)) ?></option><?php endforeach; ?></select>
                        </label>
                    </div>
                <?php endif; ?></section>
            <?php endforeach; ?>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar producto</button><a class="contact-button" href="index.php">Volver al listado</a></div>
    </form>
</section>

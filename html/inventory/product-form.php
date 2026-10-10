<?php
$record = $page['record'];
$editing = isset($record['id']);
$tabs = ['general' => 'Información general', 'variants' => 'Variantes', 'sales' => 'Ventas',
    'purchases' => 'Compras', 'inventory' => 'Inventario', 'accounting' => 'Contabilidad'];
$indicatorHelp = ['on-hand' => 'Existencias actuales en todas las ubicaciones.',
    'incoming' => 'Número de movimientos de entrada realizados en los últimos 12 meses.',
    'outgoing' => 'Número de movimientos de salida realizados en los últimos 12 meses.',
    'sold' => 'Unidades de órdenes de venta confirmadas en los últimos 365 días.',
    'purchased' => 'Unidades en órdenes de compra confirmadas en los últimos 12 meses.'];
$indicatorViews = ['on-hand' => 'stock', 'sold' => 'sold', 'purchased' => 'purchased'];
?>
<section aria-labelledby="product-title">
    <div class="contact-toolbar"><div><a class="overview-link" href="index.php">← Productos</a><h1 id="product-title"><?= $editing ? 'Editar producto' : 'Nuevo producto' ?></h1></div></div>
    <form class="contact-sheet" method="post" action="index.php?<?= $editing ? 'id=' . (int) $record['id'] : 'new=1' ?>">
        <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_product">
        <div class="product-stat-buttons" role="group" aria-label="Estadísticas del producto" aria-describedby="product-stats-help">
            <?php foreach (['on-hand' => 'Cantidad a la mano', 'sold' => 'Vendido', 'purchased' => 'Comprado'] as $key => $label): ?>
                <?php $value = $key === 'on-hand' ? $page['productStock']['total'] : $page['productIndicators'][$key]; ?>
                <?php $canOpen = $editing && $value !== null && isset($indicatorViews[$key]); ?>
                <?php if ($canOpen): ?><a class="product-stat-button" href="informes.php?view=<?= $indicatorViews[$key] ?>&amp;product_id=<?= (int) $record['id'] ?>" data-product-stat="<?= $key ?>" title="<?= inventoryEscape($indicatorHelp[$key]) ?>"><?php else: ?><button class="product-stat-button" type="button" disabled data-product-stat="<?= $key ?>" title="<?= inventoryEscape($indicatorHelp[$key]) ?>"><?php endif; ?>
                    <span class="product-stat-value" <?= $value === null ? 'aria-label="Sin datos disponibles"' : '' ?>><?= $value !== null ? inventoryEscape($value) : '—' ?></span>
                    <span class="product-stat-label"><?= $label ?></span>
                <?php if ($canOpen): ?></a><?php else: ?></button><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($editing): ?><a class="product-stat-button product-stat-movements" href="informes.php?view=movements&amp;product_id=<?= (int) $record['id'] ?>" data-product-stat="movements" title="Historial de movimientos del producto"><?php else: ?><button class="product-stat-button product-stat-movements" type="button" disabled data-product-stat="movements"><?php endif; ?>
                <span class="product-stat-movement-row"><span class="product-stat-label">Entrada:</span><span class="product-stat-value"><?= inventoryEscape($page['productIndicators']['incoming']) ?></span></span>
                <span class="product-stat-movement-row"><span class="product-stat-label">Salida:</span><span class="product-stat-value"><?= inventoryEscape($page['productIndicators']['outgoing']) ?></span></span>
            <?php if ($editing): ?></a><?php else: ?></button><?php endif; ?>
        </div>
        <p class="product-stats-help" id="product-stats-help">A la mano: existencias actuales. Vendido y Comprado: unidades de órdenes confirmadas del último año. Entrada/Salida: número de movimientos realizados del último año; su botón abre el historial completo.</p>
        <?php if($editing): ?><p class="product-stats-help">Reservado: <?= inventoryEscape($page['productStock']['reserved']) ?> · Disponible: <?= inventoryEscape($page['productStock']['available']) ?></p><?php endif; ?>
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
                        <label>Tipo de producto<select name="product_type"><option value="goods" <?= ($record['product_type']??'goods')==='goods'?'selected':'' ?>>Mercancía</option><option value="service" <?= ($record['product_type']??'goods')==='service'?'selected':'' ?>>Servicio</option></select></label>
                        <label>Facturar cantidades<select name="invoice_policy"><option value="delivered" <?= ($record['invoice_policy']??'delivered')==='delivered'?'selected':'' ?>>Entregadas / recibidas</option><option value="ordered" <?= ($record['invoice_policy']??'delivered')==='ordered'?'selected':'' ?>>Ordenadas</option></select></label>
                        <label>Costo unitario<input name="cost_price" inputmode="decimal" value="<?= inventoryEscape($record['cost_price']??'0.00') ?>"></label>
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
                <?php elseif ($key==='purchases'): ?>
                    <h2 class="notebook-page-title">Precios por proveedor</h2>
                    <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Proveedor</th><th>Precio de compra</th></tr></thead><tbody>
                    <?php foreach(array_merge($page['supplierPrices'],[[],[],[]]) as $i=>$supplierPrice): ?><tr><td><select name="supplier_prices[<?= $i ?>][supplier_id]"><option value="">Sin proveedor</option><?php foreach($page['suppliers'] as $supplier): ?><option value="<?= (int)$supplier['id'] ?>" <?= (int)($supplierPrice['supplier_id']??0)===(int)$supplier['id']?'selected':'' ?>><?= inventoryEscape($supplier['name']) ?></option><?php endforeach; ?></select></td><td><input name="supplier_prices[<?= $i ?>][price]" inputmode="decimal" value="<?= inventoryEscape($supplierPrice['price']??'0.00') ?>"></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?></section>
            <?php endforeach; ?>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar producto</button><a class="contact-button" href="index.php">Volver al listado</a></div>
    </form>
</section>

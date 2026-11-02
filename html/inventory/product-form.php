<?php
$record = $page['record'];
$editing = isset($record['id']);
$commissionPreviewPrice=preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/',(string)($record['sale_price']??'0')) ? (string)($record['sale_price']??'0') : '0';
$commissionPreviewUnit=productCommissionUnit($commissionPreviewPrice,$record['commission_type']??'none',$record['commission_value']??'0')??'0.00';
$tabs = ['general' => 'Información general', 'details' => 'Características', 'variants' => 'Variantes', 'sales' => 'Ventas',
    'purchases' => 'Compras', 'inventory' => 'Inventario', 'accounting' => 'Contabilidad'];
$isVariant=($page['variantCreation']??false)||!empty($record['parent_product_id']);
$isTemplate=!empty($page['isTemplate']);
$backUrl=$isVariant?'variantes.php'.(!empty($record['parent_product_id'])?'?parent_id='.(int)$record['parent_product_id']:''):'index.php';
$formUrl=($page['variantCreation']??false)?'variantes.php?new=1':('index.php?'.($editing?'id='.(int)$record['id']:'new=1'));
$indicatorHelp = ['on-hand' => 'Existencias actuales en todas las ubicaciones.',
    'incoming' => 'Número de movimientos de entrada realizados en los últimos 12 meses.',
    'outgoing' => 'Número de movimientos de salida realizados en los últimos 12 meses.',
    'sold' => 'Unidades de órdenes de venta confirmadas en los últimos 365 días.',
    'purchased' => 'Unidades en órdenes de compra confirmadas en los últimos 12 meses.'];
$indicatorViews = ['on-hand' => 'stock', 'sold' => 'sold', 'purchased' => 'purchased'];
if($isTemplate)foreach($indicatorHelp as &$help)$help.=' Total de las variantes; excluye registros anteriores del principal.';unset($help);
?>
<section aria-labelledby="product-title">
    <div class="contact-toolbar"><div><a class="overview-link" href="<?= inventoryEscape($backUrl) ?>">← <?= $isVariant?'Variantes':'Productos' ?></a><h1 id="product-title"><?= $editing ? ($isVariant?'Editar variante':($isTemplate?'Plantilla del producto':'Editar producto')) : ($isVariant?'Nueva variante':'Nuevo producto') ?></h1><?php if($isVariant&&$page['variantParent']): ?><p class="section-subtitle">Producto principal: <a class="inline-link" href="index.php?id=<?= (int)$page['variantParent']['id'] ?>#product-page-variants"><?= inventoryEscape($page['variantParent']['name']) ?></a></p><?php endif; ?></div></div>
    <?php if($isTemplate): ?><p class="notice" role="status"><strong>Plantilla del producto.</strong> Las compras, ventas y movimientos nuevos utilizan sus variantes. Los indicadores muestran los totales de las variantes. Los precios y opciones de esta ficha sirven como valores iniciales al crear otras variantes.</p><?php endif; ?>
    <?php if($isTemplate&&$page['legacyStock']&&(bccomp($page['legacyStock']['total'],'0',2)!==0||bccomp($page['legacyStock']['reserved'],'0',2)!==0)): ?><p class="notice" role="status"><strong>Existencias anteriores del principal:</strong> <?= inventoryEscape($page['legacyStock']['total']) ?> unidades · <?= inventoryEscape($page['legacyStock']['reserved']) ?> reservadas. Se conservan separadas del total de variantes; no se asignan automáticamente a un sabor o presentación. <a class="inline-link" href="informes.php?view=stock&amp;product_id=<?= (int)$record['id'] ?>&amp;exact=1">Consultar existencias originales</a></p><?php endif; ?>
    <form class="contact-sheet" method="post" action="<?= inventoryEscape($formUrl) ?>" data-product-commission-form data-unsaved-changes <?= !empty($page['errors'])?'data-unsaved-initial="true"':'' ?>>
        <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_product">
        <?php if($editing): ?><input type="hidden" name="product_revision" value="<?= inventoryEscape($page['productRevision']) ?>"><?php endif; ?>
        <div class="product-stat-buttons" role="group" aria-label="Estadísticas del producto" aria-describedby="product-stats-help">
            <?php foreach (['on-hand' => 'Cantidad a la mano', 'sold' => 'Vendido', 'purchased' => 'Comprado'] as $key => $label): ?>
                <?php $value = $key === 'on-hand' ? $page['productStock']['total'] : $page['productIndicators'][$key]; ?>
                <?php $canOpen = $editing && $value !== null && isset($indicatorViews[$key]); ?>
                <?php if ($canOpen): ?><a class="product-stat-button" href="informes.php?view=<?= $indicatorViews[$key] ?>&amp;product_id=<?= (int) $record['id'] ?>" data-product-stat="<?= $key ?>" title="<?= inventoryEscape($indicatorHelp[$key]) ?>"><?php else: ?><button class="product-stat-button" type="button" disabled data-product-stat="<?= $key ?>" title="<?= inventoryEscape($indicatorHelp[$key]) ?>"><?php endif; ?>
                    <span class="product-stat-value" <?= $value === null ? 'aria-label="Sin datos disponibles"' : '' ?>><?= $value !== null ? inventoryEscape($value) : '—' ?></span>
                    <span class="product-stat-label"><?= $label ?><?= $isTemplate?' · variantes':'' ?></span>
                <?php if ($canOpen): ?></a><?php else: ?></button><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($editing): ?><a class="product-stat-button product-stat-movements" href="informes.php?view=movements&amp;product_id=<?= (int) $record['id'] ?>" data-product-stat="movements" title="Historial de movimientos del producto"><?php else: ?><button class="product-stat-button product-stat-movements" type="button" disabled data-product-stat="movements"><?php endif; ?>
                <span class="product-stat-movement-row"><span class="product-stat-label">Entrada:</span><span class="product-stat-value"><?= inventoryEscape($page['productIndicators']['incoming']) ?></span></span>
                <span class="product-stat-movement-row"><span class="product-stat-label">Salida:</span><span class="product-stat-value"><?= inventoryEscape($page['productIndicators']['outgoing']) ?></span></span>
            <?php if ($editing): ?></a><?php else: ?></button><?php endif; ?>
        </div>
        <p class="product-stats-help" id="product-stats-help">A la mano: existencias actuales. Vendido y Comprado: unidades de órdenes confirmadas del último año. Entrada/Salida: número de movimientos realizados del último año; su botón abre el historial completo.</p>
        <?php if($isTemplate): ?><p class="product-stats-help">Los totales y sus enlaces incluyen todas las variantes, sin duplicar cantidades. <a class="inline-link" href="informes.php?view=movements&amp;product_id=<?= (int)$record['id'] ?>&amp;exact=1">Consultar historial anterior del principal</a></p><?php endif; ?>
        <?php if($editing&&permissionHas('inventario.stock')): ?><p class="product-stats-help">Reservado: <?= inventoryEscape($page['productStock']['reserved']) ?> · Disponible: <?= inventoryEscape($page['productStock']['available']) ?></p><?php endif; ?>
        <?php if($page['variantCreation']??false): ?><label class="record-name-field">Producto principal *<select name="parent_product_id" data-product-variant-parent required><option value="">Selecciona un producto</option><?php foreach($page['variantParents'] as $parent): ?><option value="<?= (int)$parent['id'] ?>" <?= (int)($record['parent_product_id']??0)===(int)$parent['id']?'selected':'' ?>><?= inventoryEscape($parent['name']) ?></option><?php endforeach; ?></select></label><p class="product-stats-help">Al elegir el principal se cargan sus datos. Cambia el nombre y los campos que necesites para esta variante.</p><?php endif; ?>
        <label class="record-name-field"><?= $isVariant?'Nombre de la variante':'Nombre' ?> *<input name="name" required maxlength="160" value="<?= inventoryEscape($record['name'] ?? '') ?>" placeholder="<?= $isVariant?'Ej. Torta de chocolate':'Nombre del producto' ?>"></label>
        <div class="product-options" role="group" aria-label="Uso del producto">
            <label><input type="checkbox" name="sale_enabled" value="1" <?= ! empty($record['sale_enabled']) ? 'checked' : '' ?>> Venta<?= $isTemplate?' para nuevas variantes':'' ?></label>
            <label><input type="checkbox" name="purchase_enabled" value="1" <?= ! empty($record['purchase_enabled']) ? 'checked' : '' ?>> Compra<?= $isTemplate?' para nuevas variantes':'' ?></label>
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
                        <label>Moneda de precios<select name="currency_code"><?php foreach(array_unique(array_merge(accountingCurrencyCodes($page['settings']),[$record['currency_code']??null])) as $code): ?><?php if($code): ?><option value="<?= inventoryEscape($code) ?>" <?= ($record['currency_code']??$page['settings']['primary_code'])===$code?'selected':'' ?>><?= inventoryEscape($code) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                        <?php if(permissionHas('inventario.products','view_cost')): ?><label>Costo unitario<input name="cost_price" inputmode="decimal" value="<?= inventoryEscape($record['cost_price']??'0.00') ?>"></label><?php endif; ?>
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
                <?php elseif ($key==='details'): ?>
                    <h2 class="notebook-page-title">Características del producto</h2>
                    <p class="section-subtitle">Todos los campos son opcionales. Completa únicamente los que correspondan al artículo. Cada variante conserva sus propios valores.</p>
                    <div class="contact-form-grid">
                        <label>Código de barras<input type="text" name="barcode" maxlength="100" value="<?= inventoryEscape($record['barcode']??'') ?>" autocomplete="off" spellcheck="false"><small>Único entre productos y variantes de esta empresa. Conserva los ceros iniciales.</small></label>
                        <label>Color<input type="text" name="color" maxlength="80" value="<?= inventoryEscape($record['color']??'') ?>" placeholder="Ej. Azul marino"></label>
                        <?php foreach(['weight_kg'=>'Peso (kg)','width_cm'=>'Ancho (cm)','height_cm'=>'Alto (cm)','depth_cm'=>'Profundidad / fondo (cm)'] as $field=>$label): ?>
                            <label><?= $label ?><input type="text" name="<?= $field ?>" inputmode="decimal" maxlength="11" value="<?= inventoryEscape($record[$field]??'') ?>" placeholder="Opcional"></label>
                        <?php endforeach; ?>
                    </div>
                    <p class="section-subtitle">Peso por unidad en kilogramos; dimensiones por unidad en centímetros. Admite valores no negativos con hasta dos decimales. Déjalos vacíos si no aplican.</p>
                <?php elseif ($key==='variants'): ?>
                    <?php if($isVariant): ?><h2 class="notebook-page-title">Variantes del producto principal</h2><p class="section-subtitle">Esta ficha pertenece a una variante. Las variantes de la familia se gestionan desde el producto principal.</p><?php if(!empty($record['parent_product_id'])): ?><a class="contact-button" href="index.php?id=<?= (int)$record['parent_product_id'] ?>#product-page-variants">Ver variantes del principal</a><?php endif; ?><?php else: ?>
                    <div class="contact-toolbar"><div><h2 class="notebook-page-title">Variantes del producto</h2><p class="section-subtitle">Escribe el nombre completo de cada variante. Cada una tiene su propio precio y existencias.</p></div><?php if($editing): ?><a class="contact-button contact-button-primary" href="variantes.php?new=1&amp;parent_id=<?= (int)$record['id'] ?>">+ Nueva variante</a><?php endif; ?></div>
                    <?php if(!$editing): ?><p class="contact-empty">Guarda primero el producto para añadir sus variantes.</p><?php elseif(!$page['variants']): ?><p class="contact-empty">Todavía no hay variantes. Por ejemplo, para «Torta» puedes crear «Torta de chocolate» y «Torta de fresa».</p><?php else: ?><div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Variante</th><th>Precio</th><?php if(permissionHas('inventario.stock')): ?><th>A la mano</th><th>Reservado</th><th>Disponible</th><?php endif; ?><th>Acción</th></tr></thead><tbody><?php foreach($page['variants'] as $variant): ?><tr><td><a class="contact-name" href="index.php?id=<?= (int)$variant['id'] ?>"><?= inventoryEscape($variant['name']) ?></a></td><td><?= inventoryEscape($variant['sale_price'].' '.$variant['currency_code']) ?></td><?php if(permissionHas('inventario.stock')): ?><td><?= inventoryEscape($variant['on_hand']??'0.00') ?></td><td><?= inventoryEscape($variant['reserved']??'0.00') ?></td><td><?= inventoryEscape($variant['available']??'0.00') ?></td><?php endif; ?><td><a class="inline-link" href="index.php?id=<?= (int)$variant['id'] ?>">Editar variante</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($key==='sales'): ?>
                    <h2 class="notebook-page-title">Comisión del vendedor</h2>
                    <div class="contact-form-grid">
                        <label>Tipo de comisión<select name="commission_type" data-product-commission-type <?= permissionHas('inventario.products','set_commission')?'':'disabled' ?>><?php foreach(['none'=>'Sin comisión automática','fixed'=>'Importe fijo por unidad','percent'=>'Porcentaje'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($record['commission_type']??'none')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                        <label data-product-commission-value-field><span data-product-commission-label>Valor de comisión</span><input name="commission_value" inputmode="decimal" maxlength="15" data-product-commission-value <?= permissionHas('inventario.products','set_commission')?'':'disabled' ?> value="<?= inventoryEscape(($record['commission_type']??'none')==='fixed'?bcadd($record['commission_value']??'0','0',2):($record['commission_value']??'0')) ?>"></label>
                        <label>Comisión por unidad<input readonly data-product-commission-unit value="<?= inventoryEscape($commissionPreviewUnit) ?>"></label>
                        <label>Precio para vendedor<input readonly data-product-seller-price value="<?= inventoryEscape(bcadd($commissionPreviewPrice,$commissionPreviewUnit,2)) ?>"></label>
                    </div>
                    <p class="section-subtitle">El importe fijo usa la moneda del producto. El porcentaje se calcula sobre su precio de venta en tienda. Con vendedor asignado, la comisión se suma al precio al agregar el producto a la venta.</p>
                <?php elseif ($key==='inventory'): ?>
                    <h2 class="workspace-section-title">Trazabilidad y vencimientos</h2>
                    <div class="contact-form-grid">
                        <label>Seguimiento<select name="tracking_mode"><?php foreach(trackingModes() as $value=>$trackingLabel): ?><option value="<?= $value ?>" <?= ($record['tracking_mode']??'none')===$value?'selected':'' ?>><?= $trackingLabel ?></option><?php endforeach; ?></select></label>
                        <label>Controlar vencimiento<select name="expiration_enabled"><option value="0" <?= empty($record['expiration_enabled'])?'selected':'' ?>>No</option><option value="1" <?= !empty($record['expiration_enabled'])?'selected':'' ?>>Sí</option></select><small>Disponible para lotes y series. No aplica a servicios.</small></label>
                        <?php foreach(['expiration_days'=>'Vida útil desde la recepción (días)','best_before_days'=>'Consumo preferente: días antes del vencimiento','removal_days'=>'Retirada: días antes del vencimiento','alert_days'=>'Alerta: días antes del vencimiento'] as $field=>$trackingLabel): ?><label><?= $trackingLabel ?><input name="<?= $field ?>" type="number" min="0" max="36500" step="1" value="<?= (int)($record[$field]??0) ?>"></label><?php endforeach; ?>
                    </div>
                    <p class="section-subtitle">Vida útil 0: indica manualmente el vencimiento al recibir. Las fechas se guardan por lote o serie. Las salidas proponen primero los identificadores con fecha de retirada más próxima (FEFO).</p>
                    <?php if($editing): ?><div class="document-controls"><a class="contact-button" href="lotes.php?product_id=<?= (int)$record['id'] ?>">Ver lotes y números de serie</a><a class="contact-button" href="lotes.php?initialize=1&amp;product_id=<?= (int)$record['id'] ?>" <?= $isTemplate?'hidden':'' ?>>Identificar existencias iniciales</a></div><?php endif; ?>
                <?php elseif ($key==='purchases'&&permissionHas('inventario.products','view_cost')): ?>
                    <h2 class="notebook-page-title">Precios por proveedor</h2>
                    <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Proveedor</th><th>Precio de compra</th></tr></thead><tbody>
                    <?php foreach(array_merge($page['supplierPrices'],[[],[],[]]) as $i=>$supplierPrice): ?><tr><td><select name="supplier_prices[<?= $i ?>][supplier_id]"><option value="">Sin proveedor</option><?php foreach($page['suppliers'] as $supplier): ?><option value="<?= (int)$supplier['id'] ?>" <?= (int)($supplierPrice['supplier_id']??0)===(int)$supplier['id']?'selected':'' ?>><?= inventoryEscape($supplier['name']) ?></option><?php endforeach; ?></select></td><td><input name="supplier_prices[<?= $i ?>][price]" inputmode="decimal" value="<?= inventoryEscape($supplierPrice['price']??'0.00') ?>"></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?></section>
            <?php endforeach; ?>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit"><?= $isVariant?'Guardar variante':'Guardar producto' ?></button><a class="contact-button" href="<?= inventoryEscape($backUrl) ?>">Volver al listado</a></div>
    </form>
</section>
<script src="../../js/product-commissions.js?v=<?= filemtime(dirname(__DIR__,2).'/js/product-commissions.js') ?>" defer></script>
<?php if($page['variantCreation']??false): ?><script src="../../js/product-variant-form.js?v=<?= filemtime(dirname(__DIR__,2).'/js/product-variant-form.js') ?>" defer></script><?php endif; ?>

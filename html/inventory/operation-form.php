<?php $record = $page['record']; $editing = isset($record['id']); $done = $record['status'] === 'done'; $base = 'operaciones.php?type=' . $page['type']; ?>
<section aria-labelledby="operation-title">
    <div class="contact-toolbar"><div><a class="overview-link" href="<?= $base ?>">← <?= inventoryEscape($page['definition']['label']) ?></a><h1 id="operation-title"><?= inventoryEscape($editing ? $record['name'] : $page['definition']['new']) ?></h1></div><ol class="quotation-status" aria-label="Estado de la operación"><li <?= ! $done ? 'aria-current="step"' : '' ?>>Borrador</li><li <?= $done ? 'aria-current="step"' : '' ?>>Realizado</li></ol></div>
    <?php if ($editing && ! $done): ?><form class="quotation-state-actions" method="post" action="<?= $base ?>&amp;id=<?= (int) $record['id'] ?>" data-operation-validate><input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>"><input type="hidden" name="action" value="validate_operation"><button class="contact-button contact-button-primary" type="submit">Validar</button><span class="section-subtitle" data-operation-dirty hidden>Guarda los cambios antes de validar.</span></form><?php endif; ?>
    <form class="contact-sheet" method="post" action="<?= $base ?><?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>" data-operation-form>
        <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_operation"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>">
        <fieldset <?= $done ? 'disabled' : '' ?> class="quotation-fields"><div class="contact-form-grid">
            <label>Referencia<input readonly value="<?= inventoryEscape($record['name']) ?>"><small>Se asigna automáticamente al guardar.</small></label>
            <?php if ($page['sourceOrder']): ?><label>Documento de origen<a class="inline-link" href="../ventas/index.php?id=<?= (int) $page['sourceOrder']['id'] ?>"><?= inventoryEscape($page['sourceOrder']['name']) ?></a></label><?php endif; ?>
            <?php if ($page['type'] !== 'internal'): ?><label><?= $page['type'] === 'delivery' ? 'Dirección de entrega' : 'Proveedor' ?> *<select name="contact_id" required><option value=""><?= $page['type'] === 'delivery' ? 'Selecciona un cliente' : 'Selecciona un contacto' ?></option><?php foreach ($page['contacts'] as $contact): ?><option value="<?= (int) $contact['id'] ?>" <?= (int) ($record['contact_id'] ?? 0) === (int) $contact['id'] ? 'selected' : '' ?>><?= inventoryEscape($contact['name']) ?><?= ! $contact['active'] ? ' (archivado)' : '' ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <?php foreach (['source' => 'Ubicación de origen', 'destination' => 'Ubicación de destino'] as $side => $label): ?>
                <?php if (($side === 'source' && $page['type'] === 'receipt') || ($side === 'destination' && $page['type'] === 'delivery')) { continue; } ?>
                <label><?= $label ?> *<select name="<?= $side ?>_location_id" required><option value="">Selecciona una ubicación</option><?php foreach ($page['storage']['locations'] as $location): ?><option value="<?= (int) $location['id'] ?>" <?= (int) ($record[$side . '_location_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>><?= inventoryEscape(storageLabel($page['storage'], (int) $location['warehouse_id'], (int) $location['id'])) ?></option><?php endforeach; ?></select></label>
                <?php if ($editing && empty($record[$side . '_location_id'])): ?><p class="section-subtitle contact-full">Selecciona la <?= mb_strtolower($label) ?> para guardar los cambios de esta operación.</p><?php endif; ?>
            <?php endforeach; ?>
            <?php if (! $page['storage']['locations']): ?><p class="section-subtitle contact-full">Crea una ubicación dentro del almacén para registrar operaciones. <a class="inline-link" href="configuracion.php?section=locations&amp;new=1">Crear ubicación</a></p><?php endif; ?>
            <label>Fecha programada *<input type="date" name="scheduled_date" required value="<?= inventoryEscape($record['scheduled_date']) ?>"></label>
            <?php if ($page['sourceOrder']): ?>
                <label>Tipo de orden<input readonly value="<?= $page['sourceOrder']['order_type'] === 'invoice' ? 'Factura' : 'Orden de entrega' ?>"></label>
            <?php endif; ?>
        </div>
        <div class="contact-notebook" data-notebook><nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la operación"><a id="operation-tab-lines" href="#operation-lines">Operaciones</a><?php if ($page['type'] === 'delivery'): ?><a id="operation-tab-other" href="#operation-other">Otra información</a><?php endif; ?><a id="operation-tab-notes" href="#operation-notes">Notas internas</a></nav>
            <section class="notebook-page" id="operation-lines" data-notebook-page aria-labelledby="operation-tab-lines">
                <div class="contact-table-wrap" role="region" aria-label="Productos de la operación" tabindex="0"><table class="contact-table"><thead><tr><th scope="col">Producto</th><th scope="col">Demanda</th><th scope="col">Cantidad</th><?php if ($page['type'] !== 'delivery'): ?><th scope="col">A la mano en <?= $page['type'] === 'receipt' ? 'destino' : 'origen' ?></th><?php endif; ?><?php if (! $done): ?><th scope="col">Acción</th><?php endif; ?></tr></thead><tbody data-operation-lines>
                    <?php $displayLines = $record['lines'] ?: ($done ? [] : [[]]); foreach ($displayLines as $lineKey => $line): ?><?php require __DIR__ . '/operation-line.php'; ?><?php endforeach; ?>
                </tbody></table></div>
                <?php if (! $done): ?><button class="contact-button" type="button" data-operation-add hidden>+ Agregar producto</button><template data-operation-template><?php $lineKey = '__INDEX__'; $line = []; require __DIR__ . '/operation-line.php'; ?></template><?php endif; ?>
                <p class="section-subtitle">Demanda: cantidad prevista. Cantidad: unidades que se moverán al validar. Guardar el borrador no modifica existencias.</p>
            </section>
            <?php if ($page['type'] === 'delivery'): ?><section class="notebook-page" id="operation-other" data-notebook-page aria-labelledby="operation-tab-other"><div class="contact-form-grid"><label>Vendedor relacionado<input readonly value="<?= inventoryEscape($page['sourceOrder']['seller_name'] ?? '') ?>"></label></div></section><?php endif; ?>
            <section class="notebook-page" id="operation-notes" data-notebook-page aria-labelledby="operation-tab-notes"><label>Notas internas<textarea name="notes" rows="4" maxlength="5000"><?= inventoryEscape($record['notes'] ?? '') ?></textarea></label></section>
        </div>
        </fieldset>
        <div class="contact-form-actions"><?php if (! $done): ?><button class="contact-button contact-button-primary" type="submit">Guardar</button><?php endif; ?><a class="contact-button" href="<?= $base ?>">Volver al listado</a></div>
    </form>
</section>
<script type="application/json" data-operation-stock><?= json_encode($page['stock'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="../../js/inventory-operations.js" defer></script>

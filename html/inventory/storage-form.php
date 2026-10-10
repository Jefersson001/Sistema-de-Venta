<?php $record = $page['record']; $editing = isset($record['id']); ?>
<form class="contact-sheet" method="post" action="configuracion.php?section=<?= $section ?><?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>">
    <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="<?= $definition['action'] ?>">
    <h2><?= $editing ? ($section === 'warehouses' ? 'Editar almacén' : 'Editar ubicación') : $definition['new'] ?></h2>
    <div class="contact-form-grid">
        <label class="contact-full">Nombre *<input name="name" required maxlength="160" value="<?= inventoryEscape($record['name'] ?? '') ?>"></label>
        <?php if ($section === 'locations'): ?>
            <label>Almacén *<select name="warehouse_id" required data-location-warehouse><option value="">Selecciona un almacén</option><?php foreach ($page['warehouses'] as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (int) ($record['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= inventoryEscape($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Ubicación superior<select name="parent_id" data-location-parent><option value="">Sin ubicación superior</option>
                <?php foreach ($page['locations'] as $location): ?><?php if (! in_array((int) $location['id'], $page['excludedParents'], true)): ?><option value="<?= (int) $location['id'] ?>" data-warehouse="<?= (int) $location['warehouse_id'] ?>" <?= (int) ($record['parent_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>><?= inventoryEscape(inventoryCategoryPath($page['locations'], $location)) ?> · <?= inventoryEscape(inventoryCategoryRecord($page['warehouses'], (int) $location['warehouse_id'])['name'] ?? '') ?></option><?php endif; ?><?php endforeach; ?>
            </select><small>Opcional. Permite crear sububicaciones dentro del mismo almacén.</small></label>
        <?php endif; ?>
    </div>
    <?php if ($section === 'locations' && ! $page['warehouses']): ?><p class="section-subtitle">Crea primero un almacén para poder registrar ubicaciones. <a class="inline-link" href="configuracion.php?section=warehouses&amp;new=1">Crear almacén</a></p><?php endif; ?>
    <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar</button><a class="contact-button" href="configuracion.php?section=<?= $section ?>">Volver al listado</a></div>
</form>

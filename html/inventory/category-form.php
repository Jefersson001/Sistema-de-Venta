<?php $record = $page['record']; $editing = isset($record['id']); ?>
<form class="contact-sheet" method="post" data-inventory-category action="configuracion.php?section=categories<?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>">
    <input type="hidden" name="csrf" value="<?= inventoryEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_category">
    <h2><?= $editing ? 'Editar categoría' : 'Nueva categoría' ?></h2>
    <div class="contact-form-grid">
        <label>Nombre *<input name="name" required maxlength="160" value="<?= inventoryEscape($record['name'] ?? '') ?>" placeholder="Ej. Electrónica"></label>
        <label>Categoría superior
            <select name="parent_id"><option value="">Sin categoría superior</option>
                <?php foreach ($page['categories'] as $category): ?>
                    <?php if (! in_array((int) $category['id'], $page['excludedParents'], true)): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (int) ($record['parent_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= inventoryEscape(inventoryCategoryPath($page['categories'], $category)) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select><small>Opcional. Permite organizar categorías y subcategorías.</small>
        </label>
        <div data-restaurant-category-option <?= !empty($record['parent_id'])?'hidden':'' ?>>
            <div class="product-flags"><label><input type="checkbox" name="restaurant_enabled" value="1" <?= !empty($record['restaurant_enabled'])?'checked':'' ?>> Es para restaurante</label></div>
            <small>Incluye esta categoría principal y todas sus subcategorías en la carta del restaurante.</small>
        </div>
        <small data-restaurant-category-inherited <?= empty($record['parent_id'])?'hidden':'' ?>>La disponibilidad en Restaurante se define en la categoría principal.</small>
    </div>
    <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar categoría</button><a class="contact-button" href="configuracion.php?section=categories">Volver al listado</a></div>
</form>
<script src="../../js/inventory-categories.js?v=<?= filemtime(dirname(__DIR__,2).'/js/inventory-categories.js') ?>" defer></script>

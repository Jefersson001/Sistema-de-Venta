<?php $record = $page['record']; $editing = isset($record['id']); ?>
<form class="contact-sheet" method="post" action="configuracion.php?section=categories<?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>">
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
    </div>
    <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar categoría</button><a class="contact-button" href="configuracion.php?section=categories">Volver al listado</a></div>
</form>

<form class="contact-filters" method="get" action="configuracion.php">
    <input type="hidden" name="section" value="categories">
    <label>Buscar categoría<input name="q" type="search" maxlength="160" value="<?= inventoryEscape($page['search']) ?>" placeholder="Nombre o categoría superior"></label>
    <button class="contact-button" type="submit">Buscar</button><a class="contact-button" href="configuracion.php?section=categories">Limpiar</a>
</form>
<div class="contact-table-wrap" role="region" aria-label="Listado de categorías de productos" tabindex="0">
    <table class="contact-table"><thead><tr><th scope="col">Categoría</th><th scope="col">Categoría superior</th><th scope="col">Acción</th></tr></thead><tbody>
        <?php foreach ($page['rows'] as $category): ?>
            <?php $parent = inventoryCategoryRecord($page['categories'], inventoryId($category['parent_id'])); ?>
            <tr><td><a class="contact-name" href="configuracion.php?section=categories&amp;id=<?= (int) $category['id'] ?>"><?= inventoryEscape(inventoryCategoryPath($page['categories'], $category)) ?></a></td><td><?= inventoryEscape($parent ? inventoryCategoryPath($page['categories'], $parent) : '—') ?></td><td><a class="inline-link" href="configuracion.php?section=categories&amp;id=<?= (int) $category['id'] ?>">Editar<span class="visually-hidden"> <?= inventoryEscape($category['name']) ?></span></a></td></tr>
        <?php endforeach; ?>
        <?php if (! $page['rows']): ?><tr><td colspan="3" class="contact-empty">No hay categorías que coincidan con esta búsqueda. Pulsa «Nueva categoría» para crear una.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="contact-pagination" aria-label="Páginas de categorías"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span>
    <?php foreach (['Anterior' => $page['currentPage'] - 1, 'Siguiente' => $page['currentPage'] + 1] as $label => $number): ?>
        <?php if ($number >= 1 && $number <= $page['pages']): ?><a class="contact-button" href="configuracion.php?<?= inventoryEscape(http_build_query(['section' => 'categories', 'q' => $page['search'], 'page' => $number])) ?>"><?= $label ?></a><?php endif; ?>
    <?php endforeach; ?>
</nav>

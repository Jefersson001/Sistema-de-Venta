<section aria-labelledby="products-title">
    <div class="contact-toolbar">
        <div><p class="eyebrow">INVENTARIO</p><h1 id="products-title">Productos</h1><p class="section-subtitle"><?= $page['total'] ?> productos · Vista de lista</p></div>
        <a class="contact-button contact-button-primary" href="index.php?new=1">+ Nuevo producto</a>
    </div>
    <form class="contact-filters" method="get" action="index.php">
        <label>Buscar producto<input name="q" type="search" maxlength="160" value="<?= inventoryEscape($page['search']) ?>" placeholder="Nombre del producto"></label>
        <button class="contact-button" type="submit">Buscar</button><a class="contact-button" href="index.php">Limpiar</a>
    </form>
    <div class="contact-table-wrap" role="region" aria-label="Listado de productos" tabindex="0">
        <table class="contact-table"><thead><tr><th scope="col">Nombre</th><th scope="col">Venta</th><th scope="col">Compra</th><th scope="col">Variantes</th><th scope="col">Acción</th></tr></thead><tbody>
            <?php foreach ($page['products'] as $product): ?>
                <tr><td><a class="contact-name" href="index.php?id=<?= (int) $product['id'] ?>"><?= inventoryEscape($product['name']) ?></a></td><td><?= $product['sale_enabled'] ? 'Sí' : 'No' ?></td><td><?= $product['purchase_enabled'] ? 'Sí' : 'No' ?></td><td><a class="inline-link" href="variantes.php?parent_id=<?= (int)$product['id'] ?>"><?= (int)$product['variants_count'] ?> variantes</a></td><td><a class="inline-link" href="index.php?id=<?= (int) $product['id'] ?>">Editar<span class="visually-hidden"> <?= inventoryEscape($product['name']) ?></span></a></td></tr>
            <?php endforeach; ?>
            <?php if (! $page['products']): ?><tr><td colspan="5" class="contact-empty">No hay productos que coincidan con esta búsqueda. Pulsa «Nuevo producto» para crear uno.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <nav class="contact-pagination" aria-label="Páginas de productos"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span>
        <?php foreach (['Anterior' => $page['currentPage'] - 1, 'Siguiente' => $page['currentPage'] + 1] as $label => $number): ?>
            <?php if ($number >= 1 && $number <= $page['pages']): ?><a class="contact-button" href="index.php?<?= inventoryEscape(http_build_query(['q' => $page['search'], 'page' => $number])) ?>"><?= $label ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>
</section>

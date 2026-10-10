<form class="contact-filters" method="get" action="configuracion.php"><input type="hidden" name="section" value="taxes">
    <label>Buscar impuesto<input name="q" type="search" maxlength="120" value="<?= taxEscape($page['search']) ?>" placeholder="Nombre del impuesto"></label>
    <button class="contact-button" type="submit">Buscar</button><a class="contact-button" href="configuracion.php?section=taxes">Limpiar</a>
</form>
<div class="contact-table-wrap" role="region" aria-label="Listado de impuestos" tabindex="0"><table class="contact-table"><thead><tr><th scope="col">Nombre</th><th scope="col">Acción</th></tr></thead><tbody>
    <?php foreach ($page['rows'] as $tax): ?><tr><td><a class="contact-name" href="configuracion.php?section=taxes&amp;id=<?= (int) $tax['id'] ?>"><?= taxEscape($tax['name']) ?></a></td><td><a class="inline-link" href="configuracion.php?section=taxes&amp;id=<?= (int) $tax['id'] ?>">Editar<span class="visually-hidden"> <?= taxEscape($tax['name']) ?></span></a></td></tr><?php endforeach; ?>
    <?php if (! $page['rows']): ?><tr><td colspan="2" class="contact-empty">No hay impuestos que coincidan con esta búsqueda. Pulsa «Nuevo impuesto» para crear uno.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="contact-pagination" aria-label="Páginas de impuestos"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span>
    <?php foreach (['Anterior' => $page['currentPage'] - 1, 'Siguiente' => $page['currentPage'] + 1] as $label => $number): ?>
        <?php if ($number >= 1 && $number <= $page['pages']): ?><a class="contact-button" href="configuracion.php?<?= taxEscape(http_build_query(['section' => 'taxes', 'q' => $page['search'], 'page' => $number])) ?>"><?= $label ?></a><?php endif; ?>
    <?php endforeach; ?>
</nav>

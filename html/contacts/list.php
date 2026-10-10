<section aria-labelledby="contacts-title">
    <div class="contact-toolbar">
        <div><p class="eyebrow">RELACIONES</p><h1 id="contacts-title">Contactos</h1><p class="section-subtitle"><?= $page['total'] ?> contactos · Vista de lista</p></div>
        <a class="contact-button contact-button-primary" href="index.php?new=1">+ Nuevo contacto</a>
    </div>
    <form class="contact-filters" method="get" action="index.php">
        <label>Buscar<input type="search" name="q" value="<?= contactEscape($page['search']) ?>" placeholder="Nombre, documento o correo" maxlength="160"></label>
        <label>Tipo<select name="type"><option value="">Todos los tipos</option><?php foreach ($page['catalogs']['types'] as $type): ?><option value="<?= (int) $type['id'] ?>" <?= (int) $type['id'] === $page['typeFilter'] ? 'selected' : '' ?>><?= contactEscape($type['name']) ?></option><?php endforeach; ?></select></label>
        <label>Estado<select name="status"><option value="active" <?= $page['statusFilter'] === 'active' ? 'selected' : '' ?>>Activos</option><option value="archived" <?= $page['statusFilter'] === 'archived' ? 'selected' : '' ?>>Archivados</option></select></label>
        <button class="contact-button" type="submit">Filtrar</button><a class="contact-button" href="index.php">Limpiar</a>
    </form>
    <div class="contact-table-wrap" role="region" aria-label="Listado de contactos" tabindex="0">
        <table class="contact-table">
            <thead><tr><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Documento</th><th scope="col">Correo</th><th scope="col">Teléfono</th><th scope="col">Localización</th></tr></thead>
            <tbody>
            <?php foreach ($page['contacts'] as $contact): ?>
                <tr><td><a class="contact-name" href="index.php?id=<?= (int) $contact['id'] ?>"><?= contactEscape($contact['name']) ?></a><small><?= $contact['entity_kind'] === 'company' ? 'Empresa' : 'Persona' ?></small></td><td><span class="contact-tag"><?= contactEscape($contact['type_name']) ?></span></td><td><?= contactEscape($contact['tax_id'] ? ($contact['document_code'] ? $contact['document_code'] . '-' : '') . $contact['tax_id'] : '—') ?></td><td><?= contactEscape($contact['email'] ?: '—') ?></td><td><?= contactEscape($contact['phone'] ?: $contact['mobile'] ?: '—') ?></td><td><?= contactEscape(implode(', ', array_filter([$contact['city_name'], $contact['country_name']])) ?: '—') ?></td></tr>
            <?php endforeach; ?>
            <?php if (! $page['contacts']): ?><tr><td colspan="6" class="contact-empty">No hay contactos que coincidan con esta búsqueda. Puedes crear uno con «Nuevo contacto».</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <nav class="contact-pagination" aria-label="Páginas de contactos">
        <span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span>
        <?php foreach (['Anterior' => $page['currentPage'] - 1, 'Siguiente' => $page['currentPage'] + 1] as $label => $number): ?>
            <?php if ($number >= 1 && $number <= $page['pages']): ?><a class="contact-button" href="index.php?<?= contactEscape(http_build_query(['q' => $page['search'], 'type' => $page['typeFilter'], 'status' => $page['statusFilter'], 'page' => $number])) ?>"><?= $label ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>
</section>

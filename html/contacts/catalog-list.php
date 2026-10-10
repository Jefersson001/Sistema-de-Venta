            <div class="contact-table-wrap" role="region" aria-label="<?= $definition['label'] ?> registrados" tabindex="0">
                <table class="contact-table"><thead><tr><th scope="col">Nombre</th><?php if ($section !== 'types'): ?><th scope="col"><?= $section === 'document_types' ? 'Letra' : ($section === 'countries' ? 'Código' : ($section === 'states' ? 'País' : 'Estado / País')) ?></th><?php endif; ?><th scope="col">Acción</th></tr></thead><tbody>
                    <?php foreach ($page['catalogs'][$section] as $item): ?>
                        <tr><td><?= contactEscape($item['name']) ?></td>
                        <?php if ($section === 'countries'): ?><td><?= contactEscape($item['code'] ?: '—') ?></td><?php endif; ?>
                        <?php if ($section === 'document_types'): ?><td><?= contactEscape($item['code']) ?></td><?php endif; ?>
                        <?php if ($section === 'states'): ?><td><?= contactEscape(contactCatalogRecord($page['catalogs']['countries'], (int) $item['country_id'])['name'] ?? '') ?></td><?php endif; ?>
                        <?php if ($section === 'cities'): ?><?php $state = contactCatalogRecord($page['catalogs']['states'], (int) $item['state_id']); ?><td><?= contactEscape($state['name'] ?? '') ?> / <?= contactEscape(contactCatalogRecord($page['catalogs']['countries'], (int) $state['country_id'])['name'] ?? '') ?></td><?php endif; ?>
                        <td><a class="inline-link" href="configuracion.php?section=<?= $section ?>&amp;id=<?= (int) $item['id'] ?>">Editar<span class="visually-hidden"> <?= contactEscape($item['name']) ?></span></a></td></tr>
                    <?php endforeach; ?>
                    <?php if (! $page['catalogs'][$section]): ?><tr><td colspan="<?= $section === 'types' ? 2 : 3 ?>" class="contact-empty">Todavía no hay registros. Pulsa «Nuevo» para crear el primero.</td></tr><?php endif; ?>
                </tbody></table>
            </div>

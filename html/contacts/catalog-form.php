            <form class="contact-sheet" method="post" action="configuracion.php?section=<?= $section ?><?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>">
                <input type="hidden" name="csrf" value="<?= contactEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_catalog">
                <h3><?= $editing ? 'Editar ' : 'Crear ' ?><?= $definition['singular'] ?></h3>
                <div class="contact-form-grid">
                    <label>Nombre *<input name="name" required maxlength="<?= $section === 'types' ? 80 : 120 ?>" value="<?= contactEscape($record['name'] ?? '') ?>"></label>
                    <?php if ($section === 'countries'): ?><label>Código del país<input name="code" maxlength="3" pattern="[A-Za-z]{2,3}" value="<?= contactEscape($record['code'] ?? '') ?>" placeholder="Ej. VE"><small>Opcional: dos o tres letras.</small></label><?php endif; ?>
                    <?php if ($section === 'document_types'): ?><label>Letra *<input name="code" required maxlength="1" pattern="[A-Za-z]" value="<?= contactEscape($record['code'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?> placeholder="Ej. V"><small>Una letra de A a Z. La letra de un tipo existente se conserva.</small></label><?php endif; ?>
                    <?php if ($section === 'states' || $section === 'cities'): ?>
                        <?php $field = $section === 'states' ? 'country_id' : 'state_id'; $parent = $section === 'states' ? 'countries' : 'states'; ?>
                        <label><?= $section === 'states' ? 'País' : 'Estado' ?> *
                            <select name="<?= $field ?>" required><option value="">Selecciona una opción</option>
                                <?php foreach ($page['catalogs'][$parent] as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>" <?= (int) ($record[$field] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= contactEscape($item['name']) ?><?= $section === 'cities' ? ' · ' . contactEscape(contactCatalogRecord($page['catalogs']['countries'], (int) $item['country_id'])['name'] ?? '') : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($editing): ?><small>Para conservar las relaciones, la localización superior no puede cambiarse.</small><?php endif; ?>
                        </label>
                    <?php endif; ?>
                </div>
                <?php if (isset($parent) && ! $page['catalogs'][$parent]): ?><p class="section-subtitle">Registra primero <?= $section === 'states' ? 'un país' : 'un estado' ?> en Localización.</p><?php endif; ?>
                <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar</button><a class="contact-button" href="configuracion.php?section=<?= $section ?>">Volver al listado</a></div>
            </form>

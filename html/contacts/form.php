<?php
$record = $page['record'];
$editing = isset($record['id']);
$selectedType = contactCatalogRecord($page['catalogs']['types'], contactId($record['type_id'] ?? null));
$isClient = ($selectedType['system_key'] ?? null) === 'client';
$isSeller = ($selectedType['system_key'] ?? null) === 'salesperson';
?>
<section aria-labelledby="contact-title">
    <div class="contact-toolbar">
        <div><a class="overview-link" href="index.php">← Contactos</a><h1 id="contact-title"><?= $editing ? contactEscape($record['name']) : 'Nuevo contacto' ?></h1></div>
        <?php if ($editing): ?>
        <form method="post" action="index.php?id=<?= (int) $record['id'] ?>">
            <input type="hidden" name="csrf" value="<?= contactEscape($page['csrf']) ?>"><input type="hidden" name="action" value="toggle_active">
            <button class="contact-button" type="submit"><?= $record['active'] ? 'Archivar contacto' : 'Restaurar contacto' ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (! ($record['active'] ?? 1)): ?><p class="notice" role="status">Este contacto está archivado. Puedes restaurarlo para que vuelva al listado de activos.</p><?php endif; ?>
    <form class="contact-sheet" method="post" action="index.php?<?= $editing ? 'id=' . (int) $record['id'] : 'new=1' ?>">
        <input type="hidden" name="csrf" value="<?= contactEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_contact">
        <p class="section-subtitle">Los campos marcados con * son obligatorios.</p>
        <fieldset><legend>Información principal</legend>
            <div class="contact-form-grid">
                <label class="contact-full">Nombre o razón social *<input name="name" required maxlength="160" autocomplete="name" value="<?= contactEscape($record['name'] ?? '') ?>"></label>
                <label>Persona o empresa *<select name="entity_kind" required><option value="person" <?= ($record['entity_kind'] ?? '') === 'person' ? 'selected' : '' ?>>Persona</option><option value="company" <?= ($record['entity_kind'] ?? '') === 'company' ? 'selected' : '' ?>>Empresa</option></select></label>
                <label>Tipo de contacto *<select name="type_id" required data-contact-type><option value="">Selecciona un tipo</option><?php foreach ($page['catalogs']['types'] as $type): ?><option value="<?= (int) $type['id'] ?>" data-contact-kind="<?= contactEscape($type['system_key'] ?? '') ?>" <?= (int) ($record['type_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= contactEscape($type['name']) ?></option><?php endforeach; ?></select></label>
                <div class="document-field">
                    <label for="document-number">Documento</label>
                    <div class="document-controls">
                        <label class="visually-hidden" for="document-type">Tipo de documento</label>
                        <select id="document-type" name="document_type_id" title="Tipo de documento">
                            <option value="">—</option>
                            <?php foreach ($page['catalogs']['document_types'] as $documentType): ?>
                                <option value="<?= (int) $documentType['id'] ?>" aria-label="<?= contactEscape($documentType['code'] . ' · ' . $documentType['name']) ?>" title="<?= contactEscape($documentType['name']) ?>" <?= (int) ($record['document_type_id'] ?? 0) === (int) $documentType['id'] ? 'selected' : '' ?>><?= contactEscape($documentType['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="document-number" name="tax_id" maxlength="40" value="<?= contactEscape($record['tax_id'] ?? '') ?>" placeholder="Número de documento">
                    </div>
                </div>
                <label>Correo electrónico<input name="email" type="email" maxlength="160" autocomplete="email" value="<?= contactEscape($record['email'] ?? '') ?>"></label>
                <label>Teléfono<input name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= contactEscape($record['phone'] ?? '') ?>"></label>
                <label>Móvil<input name="mobile" type="tel" maxlength="40" value="<?= contactEscape($record['mobile'] ?? '') ?>"></label>
                <label class="contact-full">Sitio web<input name="website" type="url" maxlength="200" value="<?= contactEscape($record['website'] ?? '') ?>" placeholder="https://"></label>
            </div>
        </fieldset>
        <fieldset data-contact-location><legend>Dirección y localización</legend>
            <p class="section-subtitle">Selecciona primero el país, después el estado y la ciudad. <a class="inline-link" href="configuracion.php">Gestionar localizaciones</a></p>
            <div class="contact-form-grid">
                <label>País<select name="country_id" data-country><option value="">Sin país</option><?php foreach ($page['catalogs']['countries'] as $country): ?><option value="<?= (int) $country['id'] ?>" <?= (int) ($record['country_id'] ?? 0) === (int) $country['id'] ? 'selected' : '' ?>><?= contactEscape($country['name']) ?></option><?php endforeach; ?></select></label>
                <label>Estado<select name="state_id" data-state><option value="">Sin estado</option><?php foreach ($page['catalogs']['states'] as $state): ?><option data-parent="<?= (int) $state['country_id'] ?>" value="<?= (int) $state['id'] ?>" <?= (int) ($record['state_id'] ?? 0) === (int) $state['id'] ? 'selected' : '' ?>><?= contactEscape($state['name']) ?></option><?php endforeach; ?></select></label>
                <label>Ciudad<select name="city_id" data-city><option value="">Sin ciudad</option><?php foreach ($page['catalogs']['cities'] as $city): ?><option data-parent="<?= (int) $city['state_id'] ?>" value="<?= (int) $city['id'] ?>" <?= (int) ($record['city_id'] ?? 0) === (int) $city['id'] ? 'selected' : '' ?>><?= contactEscape($city['name']) ?></option><?php endforeach; ?></select></label>
                <label>Código postal<input name="postal_code" maxlength="20" autocomplete="postal-code" value="<?= contactEscape($record['postal_code'] ?? '') ?>"></label>
                <label class="contact-full">Dirección<input name="address" maxlength="255" autocomplete="street-address" value="<?= contactEscape($record['address'] ?? '') ?>" placeholder="Calle, edificio, local y referencias"></label>
            </div>
            <?php if (! $page['catalogs']['countries']): ?><p class="section-subtitle">Aún no hay países registrados. Puedes guardar el contacto sin localización o cargar los catálogos desde Configuración.</p><?php endif; ?>
        </fieldset>
        <div class="contact-notebook" data-notebook>
            <nav class="notebook-tabs" data-notebook-tabs aria-label="Información del contacto">
                <a id="tab-sales" href="#page-sales">Ventas y compras</a>
                <a id="tab-accounting" href="#page-accounting">Contabilidad</a>
                <a id="tab-inventory" href="#page-inventory">Inventario</a>
                <a id="tab-notes" href="#page-notes">Notas internas</a>
            </nav>
            <section class="notebook-page" id="page-sales" data-notebook-page aria-labelledby="tab-sales">
                <h2 class="notebook-page-title">Ventas y compras</h2>
                <div class="contact-form-grid" data-salesperson-field <?= ! $isClient ? 'hidden' : '' ?>>
                    <label>Vendedor asignado
                        <select name="salesperson_id" <?= ! $isClient ? 'disabled' : '' ?>>
                            <option value="">Sin vendedor asignado</option>
                            <?php foreach ($page['salespeople'] as $salesperson): ?>
                                <option value="<?= (int) $salesperson['id'] ?>" <?= (int) ($record['salesperson_id'] ?? 0) === (int) $salesperson['id'] ? 'selected' : '' ?>><?= contactEscape($salesperson['name']) ?><?= ! $salesperson['active'] ? ' (archivado)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Selecciona un contacto de tipo Vendedor.</small>
                    </label>
                </div>
                <p class="section-subtitle" data-salesperson-help <?= $isClient ? 'hidden' : '' ?>>La asignación de vendedor está disponible para contactos de tipo Cliente.</p>
                <?php if (! $page['salespeople']): ?><p class="section-subtitle">Para asignar un vendedor, crea primero un contacto de tipo Vendedor.</p><?php endif; ?>
            </section>
            <section class="notebook-page" id="page-accounting" data-notebook-page aria-labelledby="tab-accounting">
                <h2 class="notebook-page-title">Contabilidad</h2>
                <p class="section-subtitle">Los datos contables del contacto estarán disponibles próximamente.</p>
            </section>
            <section class="notebook-page" id="page-inventory" data-notebook-page aria-labelledby="tab-inventory">
                <h2 class="notebook-page-title">Inventario</h2>
                <div class="contact-form-grid" data-storage-seller-field <?= ! $isSeller ? 'hidden' : '' ?>>
                    <label>Almacén relacionado
                        <select name="warehouse_id" data-related-warehouse <?= ! $isSeller ? 'disabled' : '' ?>>
                            <option value="">Sin almacén relacionado</option>
                            <?php foreach ($page['storage']['warehouses'] as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (int) ($record['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= contactEscape($warehouse['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </section>
            <section class="notebook-page" id="page-notes" data-notebook-page aria-labelledby="tab-notes">
                <h2 class="notebook-page-title">Notas internas</h2>
                <label><span class="visually-hidden">Notas internas</span><textarea name="notes" rows="4" maxlength="5000" placeholder="Información adicional del contacto"><?= contactEscape($record['notes'] ?? '') ?></textarea></label>
            </section>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar contacto</button><a class="contact-button" href="index.php">Volver al listado</a></div>
    </form>
</section>

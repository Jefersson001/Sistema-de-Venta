<section aria-labelledby="company-title">
    <div class="contact-toolbar"><div>
        <?php if($page['view']==='form'): ?><a class="overview-link" href="empresa.php">← Empresa</a><?php endif; ?>
        <p class="eyebrow">AJUSTES</p><h1 id="company-title"><?= $page['view']==='form'?$e($record['name']?:'Datos de Empresa'):'Empresa' ?></h1>
    </div><?php if($page['view']==='list'): ?><a class="contact-button contact-button-primary" href="empresa.php?id=1"><?= $record['name']!==''?'Editar empresa':'Configurar empresa' ?></a><?php endif; ?></div>
    <?php if($page['view']==='list'): ?>
        <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Empresa</th><th>Razón social</th><th>Documento / RIF</th><th>Teléfono</th><th>Correo</th></tr></thead><tbody><tr>
            <td><a class="contact-name" href="empresa.php?id=1"><?= $e($record['name']?:'Configurar empresa') ?></a></td>
            <?php foreach(['legal_name','tax_id','phone','email'] as $key): ?><td><?= $e($record[$key]?:'—') ?></td><?php endforeach; ?>
        </tr></tbody></table></div>
    <?php else: ?>
        <form class="contact-sheet" action="empresa.php?id=1" method="post" enctype="multipart/form-data" data-company-form>
            <input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><input type="hidden" name="action" value="save_company"><input type="hidden" name="MAX_FILE_SIZE" value="2097152">
            <div class="contact-form-grid">
                <label class="record-name-field contact-full">Nombre comercial *<input name="name" required maxlength="160" autocomplete="organization" value="<?= $e($record['name']) ?>"></label>
                <label>Razón social<input name="legal_name" maxlength="180" value="<?= $e($record['legal_name']) ?>"></label>
                <label>Documento / RIF<input name="tax_id" maxlength="40" value="<?= $e($record['tax_id']) ?>"></label>
                <label>Teléfono<input name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $e($record['phone']) ?>"></label>
                <label>Correo<input name="email" type="email" maxlength="160" autocomplete="email" value="<?= $e($record['email']) ?>"></label>
                <label>Sitio web<input name="website" type="url" maxlength="200" placeholder="https://" value="<?= $e($record['website']) ?>"></label>
                <label class="contact-full">Dirección<textarea name="address" rows="3" maxlength="1000" autocomplete="street-address"><?= $e($record['address']) ?></textarea></label>
            </div>
            <fieldset><legend>Logo de la empresa</legend><div class="company-logo-fields">
                <img class="company-logo" data-company-logo-preview <?= $record['has_logo']?'src="logo.php?v='.(int)$record['version'].'"':'' ?> alt="Logo de la empresa" <?= !$record['has_logo']?'hidden':'' ?>>
                <div><label>Seleccionar imagen<input name="logo" type="file" accept="image/png,image/jpeg,image/webp" data-company-logo-input><small>PNG, JPEG o WebP. Máximo 2 MB y 4096 × 4096 píxeles.</small></label>
                    <?php if($record['has_logo']): ?><label class="company-logo-remove"><input type="checkbox" name="remove_logo" value="1" data-company-logo-remove>Quitar el logo guardado</label><?php endif; ?>
                    <p class="notice notice-error" data-company-logo-error role="alert" hidden></p>
                </div>
            </div></fieldset>
            <div class="contact-form-actions"><button class="contact-button contact-button-primary" data-company-save>Guardar empresa</button><a class="contact-button" href="empresa.php">Descartar</a></div>
        </form>
        <script src="../../js/company-settings.js?v=<?= filemtime(dirname(__DIR__,2).'/js/company-settings.js') ?>" defer></script>
    <?php endif; ?>
</section>

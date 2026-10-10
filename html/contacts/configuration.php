<?php
$definitions = contactCatalogDefinitions();
$section = $page['section'];
$definition = $section !== null ? $definitions[$section] : null;
$record = $page['record'];
$editing = isset($record['id']);
?>
<section aria-labelledby="contact-configuration-title">
    <div class="contact-toolbar">
        <div>
            <p class="eyebrow">CONTACTOS · CONFIGURACIÓN</p>
            <?php if ($section !== null): ?><a class="overview-link" href="<?= $page['view'] === 'form' ? 'configuracion.php?section=' . $section : 'configuracion.php' ?>">← <?= $page['view'] === 'form' ? $definition['label'] : 'Configuración' ?></a><?php endif; ?>
            <h1 id="contact-configuration-title"><?= $definition['label'] ?? 'Configuración' ?></h1>
            <p class="section-subtitle"><?= $section === null ? 'Selecciona el catálogo que deseas administrar.' : ($page['view'] === 'form' ? 'Vista de formulario' : count($page['catalogs'][$section]) . ' registros · Vista de lista') ?></p>
        </div>
        <?php if ($section !== null && $page['view'] === 'list'): ?><a class="contact-button contact-button-primary" href="configuracion.php?section=<?= $section ?>&amp;new=1">+ Nuevo <?= $definition['singular'] ?></a><?php endif; ?>
    </div>
    <?php if ($section === null): ?>
        <?php foreach (['Localización' => ['countries', 'states', 'cities'], 'Contactos' => ['types', 'document_types']] as $group => $keys): ?>
            <h2 class="catalog-group-title"><?= $group ?></h2>
            <div class="catalog-options">
                <?php foreach ($keys as $key): ?>
                    <a class="catalog-option" href="configuracion.php?section=<?= $key ?>">
                        <strong><?= $definitions[$key]['label'] ?></strong>
                        <span><?= count($page['catalogs'][$key]) ?> registros <span aria-hidden="true">→</span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="contact-config-layout">
            <nav class="contact-config-nav" aria-label="Configuración de contactos">
                <strong>Localización</strong>
                <?php foreach (['countries', 'states', 'cities'] as $key): ?><a href="configuracion.php?section=<?= $key ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= $definitions[$key]['label'] ?></a><?php endforeach; ?>
                <strong>Contactos</strong><a href="configuracion.php?section=types" <?= $section === 'types' ? 'aria-current="page"' : '' ?>>Tipos de contacto</a>
                <a href="configuracion.php?section=document_types" <?= $section === 'document_types' ? 'aria-current="page"' : '' ?>>Tipos de documento</a>
            </nav>
            <div class="contact-config-content">
                <?php if ($section === 'types'): ?><p class="section-subtitle">Los tipos clasifican los contactos. No asignan permisos de acceso al sistema.</p><?php endif; ?>
                <?php require __DIR__ . ($page['view'] === 'form' ? '/catalog-form.php' : '/catalog-list.php'); ?>
            </div>
        </div>
    <?php endif; ?>
</section>

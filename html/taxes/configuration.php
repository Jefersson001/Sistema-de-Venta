<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= taxEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><strong>Revisa la siguiente información</strong><ul><?php foreach ($page['errors'] as $error): ?><li><?= taxEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?>
<section aria-labelledby="tax-configuration-title">
    <div class="contact-toolbar">
        <div>
            <p class="eyebrow"><?= taxEscape(mb_strtoupper($module['name'], 'UTF-8')) ?> · CONFIGURACIÓN</p>
            <?php if ($page['view'] !== 'home'): ?><a class="overview-link" href="<?= $page['view'] === 'form' ? 'configuracion.php?section=taxes' : 'configuracion.php' ?>">← <?= $page['view'] === 'form' ? 'Impuestos' : 'Configuración' ?></a><?php endif; ?>
            <h1 id="tax-configuration-title"><?= $page['view'] === 'home' ? 'Configuración' : 'Impuestos de ' . taxEscape(mb_strtolower($module['name'], 'UTF-8')) ?></h1>
            <p class="section-subtitle"><?= $page['view'] === 'home' ? 'Selecciona el catálogo que deseas administrar.' : ($page['view'] === 'form' ? 'Vista de formulario' : $page['total'] . ' impuestos · Vista de lista') ?></p>
        </div>
        <?php if ($page['view'] === 'list'): ?><a class="contact-button contact-button-primary" href="configuracion.php?section=taxes&amp;new=1">+ Nuevo impuesto</a><?php endif; ?>
    </div>
    <?php if ($page['view'] === 'home'): ?>
        <div class="catalog-options"><a class="catalog-option" href="configuracion.php?section=taxes"><strong>Impuestos</strong><span><?= count($page['taxes']) ?> registros <span aria-hidden="true">→</span></span></a></div>
    <?php else: ?>
        <div class="contact-config-layout">
            <nav class="contact-config-nav" aria-label="Configuración de <?= taxEscape($module['name']) ?>"><strong><?= taxEscape($module['name']) ?></strong><a href="configuracion.php?section=taxes" aria-current="page">Impuestos</a></nav>
            <div class="contact-config-content"><?php require __DIR__ . ($page['view'] === 'form' ? '/form.php' : '/list.php'); ?></div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

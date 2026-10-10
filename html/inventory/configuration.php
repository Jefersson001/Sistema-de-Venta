<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= inventoryEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><strong>Revisa la siguiente información</strong><ul><?php foreach ($page['errors'] as $error): ?><li><?= inventoryEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?>
<section aria-labelledby="inventory-configuration-title">
    <div class="contact-toolbar">
        <div>
            <p class="eyebrow">INVENTARIO · CONFIGURACIÓN</p>
            <?php if ($page['view'] !== 'home'): ?><a class="overview-link" href="<?= $page['view'] === 'form' ? 'configuracion.php?section=categories' : 'configuracion.php' ?>">← <?= $page['view'] === 'form' ? 'Categorías' : 'Configuración' ?></a><?php endif; ?>
            <h1 id="inventory-configuration-title"><?= $page['view'] === 'home' ? 'Configuración' : 'Categorías de productos' ?></h1>
            <p class="section-subtitle"><?= $page['view'] === 'home' ? 'Selecciona el catálogo que deseas administrar.' : ($page['view'] === 'form' ? 'Vista de formulario' : $page['total'] . ' categorías · Vista de lista') ?></p>
        </div>
        <?php if ($page['view'] === 'list'): ?><a class="contact-button contact-button-primary" href="configuracion.php?section=categories&amp;new=1">+ Nueva categoría</a><?php endif; ?>
    </div>
    <?php if ($page['view'] === 'home'): ?>
        <h2 class="catalog-group-title">Productos</h2>
        <div class="catalog-options"><a class="catalog-option" href="configuracion.php?section=categories"><strong>Categorías</strong><span><?= count($page['categories']) ?> registros <span aria-hidden="true">→</span></span></a></div>
    <?php else: ?>
        <div class="contact-config-layout">
            <nav class="contact-config-nav" aria-label="Configuración de inventario"><strong>Productos</strong><a href="configuracion.php?section=categories" aria-current="page">Categorías</a></nav>
            <div class="contact-config-content">
                <?php require __DIR__ . ($page['view'] === 'form' ? '/category-form.php' : '/category-list.php'); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

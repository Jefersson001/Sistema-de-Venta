<?php
$page = $modulePageData;
$definitions = inventoryConfigurationDefinitions();
$section = $page['section'] ?? null;
$definition = $section !== null ? $definitions[$section] : null;
?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= inventoryEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><strong>Revisa la siguiente información</strong><ul><?php foreach ($page['errors'] as $error): ?><li><?= inventoryEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?>
<section aria-labelledby="inventory-configuration-title">
    <div class="contact-toolbar">
        <div>
            <p class="eyebrow">INVENTARIO · CONFIGURACIÓN</p>
            <?php if ($page['view'] !== 'home'): ?><a class="overview-link" href="<?= $page['view'] === 'form' ? 'configuracion.php?section=' . $section : 'configuracion.php' ?>">← <?= $page['view'] === 'form' ? $definition['short'] : 'Configuración' ?></a><?php endif; ?>
            <h1 id="inventory-configuration-title"><?= $definition['label'] ?? 'Configuración' ?></h1>
            <p class="section-subtitle"><?= $page['view'] === 'home' ? 'Selecciona el catálogo que deseas administrar.' : ($page['view'] === 'form' ? 'Vista de formulario' : $page['total'] . ' registros · Vista de lista') ?></p>
        </div>
        <?php if ($page['view'] === 'list'): ?><a class="contact-button contact-button-primary" href="configuracion.php?section=<?= $section ?>&amp;new=1">+ <?= $definition['new'] ?></a><?php endif; ?>
    </div>
    <?php if ($page['view'] === 'home'): ?>
        <?php foreach (['Productos' => ['categories'], 'Gestión de almacenes' => ['warehouses', 'locations']] as $group => $keys): ?>
            <h2 class="catalog-group-title"><?= $group ?></h2>
            <div class="catalog-options"><?php foreach ($keys as $key): ?><a class="catalog-option" href="configuracion.php?section=<?= $key ?>"><strong><?= $definitions[$key]['short'] ?></strong><span><?= count($page[$key]) ?> registros <span aria-hidden="true">→</span></span></a><?php endforeach; ?></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="contact-config-layout">
            <nav class="contact-config-nav" aria-label="Configuración de inventario">
                <strong>Productos</strong><a href="configuracion.php?section=categories" <?= $section === 'categories' ? 'aria-current="page"' : '' ?>>Categorías</a>
                <strong>Gestión de almacenes</strong>
                <?php foreach (['warehouses', 'locations'] as $key): ?><a href="configuracion.php?section=<?= $key ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= $definitions[$key]['short'] ?></a><?php endforeach; ?>
            </nav>
            <div class="contact-config-content">
                <?php require __DIR__ . ($section === 'categories'
                    ? ($page['view'] === 'form' ? '/category-form.php' : '/category-list.php')
                    : ($page['view'] === 'form' ? '/storage-form.php' : '/storage-list.php')); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

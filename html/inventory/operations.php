<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= inventoryEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><strong>Revisa la siguiente información</strong><ul><?php foreach ($page['errors'] as $error): ?><li><?= inventoryEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?>
    <?php require __DIR__ . ($page['view'] === 'form' ? '/operation-form.php' : '/operation-list.php'); ?>
<?php endif; ?>

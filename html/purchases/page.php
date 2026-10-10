<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= salesEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><ul><?php foreach ($page['errors'] as $error): ?><li><?= salesEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?><?php require __DIR__ . ($page['view'] === 'form' ? '/form.php' : '/list.php'); ?><?php endif; ?>

<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= accountingEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><ul><?php foreach ($page['errors'] as $error): ?><li><?= accountingEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?><?php require __DIR__ . ($page['view'] === 'form' ? '/invoice-form.php' : '/invoice-list.php'); ?><?php endif; ?>

<?php $page=$modulePageData; ?>
<?php if($page['flash']): ?><p class="notice notice-success" role="status"><?= accountingEscape($page['flash']) ?></p><?php endif; ?>
<?php foreach($page['errors'] as $error): ?><p class="notice notice-error" role="alert"><?= accountingEscape($error) ?></p><?php endforeach; ?>
<?php if($page['available'])require __DIR__.($page['view']==='form'?'/payment-form.php':'/payment-list.php'); ?>

<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?>
    <p class="notice notice-success" role="status"><?= contactEscape($page['flash']) ?></p>
<?php endif; ?>
<?php if ($page['errors']): ?>
    <div class="notice notice-error" role="alert">
        <strong>Revisa la siguiente información</strong>
        <ul><?php foreach ($page['errors'] as $error): ?><li><?= contactEscape($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($page['available']): ?>
    <?php if ($isConfiguration): ?>
        <?php require __DIR__ . '/configuration.php'; ?>
    <?php elseif ($page['view'] === 'form'): ?>
        <?php require __DIR__ . '/form.php'; ?>
    <?php else: ?>
        <?php require __DIR__ . '/list.php'; ?>
    <?php endif; ?>
<?php endif; ?>

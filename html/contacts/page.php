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
<?php if (!empty($page['wizardCustomer'])): ?>
    <p class="notice notice-success" role="status">Cliente creado. Volviendo a la orden de venta…</p>
    <script type="application/json" data-created-sales-customer><?= json_encode($page['wizardCustomer'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="../../js/contact-sales-wizard.js?v=<?= filemtime(dirname(__DIR__, 2).'/js/contact-sales-wizard.js') ?>" defer></script>
<?php elseif ($page['available']): ?>
    <?php if ($isConfiguration): ?>
        <?php require __DIR__ . '/configuration.php'; ?>
    <?php elseif ($page['view'] === 'form'): ?>
        <?php require __DIR__ . '/form.php'; ?>
    <?php else: ?>
        <?php require __DIR__ . '/list.php'; ?>
    <?php endif; ?>
<?php endif; ?>

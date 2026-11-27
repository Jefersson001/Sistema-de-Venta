<dialog id="record-products-dialog" class="record-products-dialog" data-company-scope="<?= authEscape(companyScopeToken()) ?>" aria-labelledby="record-products-title">
<header><h2 id="record-products-title">Productos</h2><button type="button" class="contact-button" data-close-record-products>Cerrar</button></header>
<div class="record-products-body"><p role="status" aria-live="polite" data-record-products-status></p><div data-record-products-content></div></div>
</dialog>
<link rel="stylesheet" href="<?= authEscape(authBasePath()) ?>/css/record-products.css?v=<?= filemtime(dirname(__DIR__,2).'/css/record-products.css') ?>">
<script src="<?= authEscape(authBasePath()) ?>/js/record-products.js?v=<?= filemtime(dirname(__DIR__,2).'/js/record-products.js') ?>" defer></script>

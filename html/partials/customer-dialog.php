<dialog class="sales-customer-dialog" data-sales-customer-dialog aria-labelledby="sales-customer-dialog-title">
    <div class="sales-customer-dialog-header"><h2 id="sales-customer-dialog-title">Crear cliente</h2><button type="button" class="contact-button" data-close-sales-customer>Cerrar</button></div>
    <p class="section-subtitle" data-sales-customer-loading role="status">Cargando Contactos…</p>
    <iframe title="Crear cliente en Contactos" data-sales-customer-frame></iframe>
</dialog>
<p class="notice notice-success" role="status" data-sales-customer-result hidden></p>
<script src="<?= authEscape(authBasePath()) ?>/js/sales-customer-wizard.js?v=<?= filemtime(dirname(__DIR__,2).'/js/sales-customer-wizard.js') ?>" defer></script>

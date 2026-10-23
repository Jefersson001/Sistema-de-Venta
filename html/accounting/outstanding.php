<div data-live-report>
<div class="contact-toolbar"><div><p class="eyebrow">CONTABILIDAD · INFORMES</p><h1>Cuentas por cobrar y por pagar</h1></div><a class="contact-button" href="../dashboard/index.php#dashboard-page-balances">Ver en Dashboard</a></div>
<p class="section-subtitle">Saldos actuales al <?= authEscape($modulePageData['as_of']) ?>. Se descuentan pagos confirmados y notas de crédito registradas. Los pagos en proceso se muestran por separado y todavía forman parte del saldo pendiente. Las monedas se presentan separadas.</p>
<form class="contact-filters" method="get" action="cuentas-pendientes.php" data-live-filters><label>Buscar<input type="search" name="q" maxlength="160" placeholder="Cliente, proveedor o factura" value="<?= authEscape($modulePageData['filters']['q']) ?>"></label><label>Vencimiento<select name="due"><option value="all">Todos los pendientes</option><option value="overdue" <?= $modulePageData['filters']['due']==='overdue'?'selected':'' ?>>Solo vencidos</option></select></label><?php if($modulePageData['filters']['contact_id']): ?><input type="hidden" name="contact_id" value="<?= (int)$modulePageData['filters']['contact_id'] ?>"><?php endif; ?><a class="overview-link" href="cuentas-pendientes.php">Limpiar filtros</a></form>
<p class="section-subtitle" data-live-filter-status role="status" aria-live="polite"></p>
<div data-live-results>
<?php foreach($modulePageData['errors'] as $error): ?><p class="cash-flow-error" role="alert"><?= authEscape($error) ?></p><?php endforeach; ?>
<?php if(!$modulePageData['errors']): ?>
<div class="outstanding-columns">
<?php $balanceSections=$modulePageData['sections'];$balanceLive=false;$balancePrefix='';require dirname(__DIR__).'/partials/outstanding.php'; ?>
</div>
<?php endif; ?>
</div>
</div>
<script src="../../js/live-report.js?v=<?= filemtime(dirname(__DIR__,2).'/js/live-report.js') ?>" defer></script>

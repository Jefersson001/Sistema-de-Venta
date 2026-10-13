<?php
$cashFlow = $modulePageData['cashFlow']; $cashFlowRange = $modulePageData['cashFlowRange'];
$cashFlowErrors = $modulePageData['cashFlowErrors']; $cashFlowLive = false; $history = $modulePageData['history'];
$account = $modulePageData['account'];
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="contact-toolbar"><div><?php if ($account): ?><a class="overview-link" href="ingresos-egresos.php?<?= $escape(cashFlowQuery($cashFlowRange)) ?>">← Cajas y bancos</a><?php endif; ?><p class="eyebrow">CONTABILIDAD · INFORMES</p><h1><?= $account ? $escape($account['name']) : 'Ingresos y egresos' ?></h1></div><a class="contact-button" href="../dashboard/index.php?<?= $escape(cashFlowQuery($cashFlowRange)) ?>">Ver en Dashboard</a></div>
<?php require dirname(__DIR__) . '/partials/cash-flow.php'; ?>
<?php if ($cashFlow): ?>
<?php if (!$account): ?>
<section class="business-overview" aria-labelledby="cash-flow-accounts-title">
    <div class="section-heading"><h2 id="cash-flow-accounts-title">Cajas y bancos</h2></div>
    <div class="cash-flow-accounts-grid">
        <?php foreach ($modulePageData['accounts'] as $item): ?>
        <a class="overview-card cash-flow-account-card" data-cash-flow-account="<?= (int)$item['id'] ?>" href="ingresos-egresos.php?<?= $escape(cashFlowQuery($cashFlowRange,(int)$item['id'])) ?>">
            <div class="cash-flow-account-heading"><h3><?= $escape($item['name']) ?></h3><span class="module-status"><?= $item['account_type']==='cash' ? 'Caja' : 'Banco' ?></span></div>
            <?php foreach ($item['currencies'] as $currency): ?><div class="cash-flow-account-currency"><strong><?= $escape($currency['code']) ?></strong><dl class="cash-flow-account-totals"><?php foreach (['income'=>'Ingresos','expense'=>'Egresos','net'=>'Diferencia'] as $key=>$label): ?><div class="cash-flow-<?= $key ?>"><dt><?= $label ?></dt><dd><?= $escape($currency[$key]) ?></dd></div><?php endforeach; ?></dl></div><?php endforeach; ?>
            <div class="cash-flow-account-footer"><span><?= $item['count'] ?> pagos confirmados</span><span class="overview-link">Ver historial →</span></div>
        </a>
        <?php endforeach; ?>
        <?php if (!$modulePageData['accounts']): ?><p class="section-subtitle">No hay cajas ni bancos configurados.</p><?php endif; ?>
    </div>
</section>
<?php else: ?>
<section aria-labelledby="cash-flow-history-title">
    <div class="section-heading"><div><h2 id="cash-flow-history-title">Historial de pagos confirmados</h2><p class="section-subtitle"><?= $history['total'] ?> registros · Fecha del pago</p></div></div>
    <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Fecha</th><th>Pago</th><th>Contacto</th><th>Tipo</th><th>Caja / banco</th><th>Medio de pago</th><th>Importe</th></tr></thead><tbody>
    <?php foreach ($history['rows'] as $row): ?><tr><td><?= $escape((new DateTimeImmutable($row['payment_date']))->format('d/m/Y')) ?></td><td><a class="contact-name" href="pagos.php?type=<?= $escape($row['partner_type']) ?>&amp;id=<?= (int)$row['id'] ?>"><?= $escape($row['name']) ?></a></td><td><?= $escape($row['contact_name']) ?></td><td><span class="cash-flow-type cash-flow-<?= $row['direction']==='inbound' ? 'income' : 'expense' ?>"><?= $row['direction']==='inbound' ? 'Ingreso' : 'Egreso' ?></span></td><td><?= $escape($row['account_name']) ?></td><td><?= $escape($row['method_name']) ?></td><td class="quotation-money"><?= $escape($row['amount'].' '.$row['currency_code']) ?></td></tr><?php endforeach; ?>
    <?php if (!$history['rows']): ?><tr><td colspan="7" class="contact-empty">No hay pagos confirmados en este período.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="contact-pagination" aria-label="Páginas del historial"><span>Página <?= $history['currentPage'] ?> de <?= $history['pages'] ?></span><?php foreach (['Anterior'=>$history['currentPage']-1,'Siguiente'=>$history['currentPage']+1] as $label=>$number): ?><?php if ($number>=1 && $number<=$history['pages']): ?><a class="contact-button" href="ingresos-egresos.php?<?= $escape(cashFlowQuery($cashFlowRange,(int)$account['id']).'&page='.$number) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
</section>
<?php endif; ?>
<?php endif; ?>
<script src="../../js/cash-flow.js?v=<?= filemtime(dirname(__DIR__, 2).'/js/cash-flow.js') ?>" defer></script>

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
<?php $cashFlowAccounts=$modulePageData['accounts'];$cashFlowAccountHrefPrefix='';require dirname(__DIR__).'/partials/cash-flow-accounts.php'; ?>
<?php else: ?>
<section class="business-overview" aria-labelledby="cash-flow-methods-title">
    <div class="section-heading"><h2 id="cash-flow-methods-title">Ingresos y egresos por medio de pago</h2></div>
    <div class="cash-flow-accounts-grid">
    <?php foreach($cashFlow['currencies'] as $currency): if(empty($currency['methods']))continue; ?>
    <div class="overview-card cash-flow-account-currency"><strong><?= $escape($currency['code']) ?></strong><?php require dirname(__DIR__).'/partials/cash-flow-methods.php'; ?></div>
    <?php endforeach; ?>
    </div>
    <?php if(!array_filter($cashFlow['currencies'],static fn($currency)=>!empty($currency['methods']))): ?><p class="section-subtitle">No hay ingresos ni egresos confirmados en este período.</p><?php endif; ?>
</section>
<section aria-labelledby="cash-flow-history-title">
    <div class="section-heading"><div><h2 id="cash-flow-history-title">Historial de movimientos confirmados</h2><p class="section-subtitle"><?= $history['total'] ?> registros · Fecha del movimiento</p></div></div>
    <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Fecha</th><th>Documento</th><th>Contacto</th><th>Tipo</th><th>Caja / banco</th><th>Concepto</th><th>Medio de pago</th><th>Importe</th></tr></thead><tbody>
    <?php foreach($history['rows'] as $row): ?><?php $href=$row['payment_id']?'pagos.php?type='.$row['partner_type'].'&id='.(int)$row['payment_id']:'tesoreria.php?id='.(int)$row['document_id'];$label=match($row['category']){'opening'=>'Saldo inicial','transfer'=>$row['direction']==='inbound'?'Transferencia recibida':'Transferencia enviada','reversal'=>'Reversión',default=>$row['direction']==='inbound'?'Ingreso':'Egreso'}; ?>
<tr><td><?= $escape((new DateTimeImmutable($row['movement_date']))->format('d/m/Y')) ?></td><td><a class="contact-name" href="<?= $escape($href) ?>"><?= $escape($row['name']) ?></a></td><td><?= $escape($row['contact_name']??'—') ?></td><td><span class="cash-flow-type cash-flow-<?= $row['direction']==='inbound'?'income':'expense' ?>"><?= $escape($label) ?></span></td><td><?= $escape($row['account_name']) ?></td><td><?= $escape($row['concept_name']??'—') ?></td><td><?= $escape($row['method_name']??'—') ?></td><td class="quotation-money"><?= $escape($row['amount'].' '.$row['currency_code']) ?></td></tr><?php endforeach; ?>
    <?php if (!$history['rows']): ?><tr><td colspan="8" class="contact-empty">No hay movimientos confirmados en este período.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="contact-pagination" aria-label="Páginas del historial"><span>Página <?= $history['currentPage'] ?> de <?= $history['pages'] ?></span><?php foreach (['Anterior'=>$history['currentPage']-1,'Siguiente'=>$history['currentPage']+1] as $label=>$number): ?><?php if ($number>=1 && $number<=$history['pages']): ?><a class="contact-button" href="ingresos-egresos.php?<?= $escape(cashFlowQuery($cashFlowRange,(int)$account['id']).'&page='.$number) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
</section>
<?php endif; ?>
<?php endif; ?>
<script src="../../js/cash-flow.js?v=<?= filemtime(dirname(__DIR__, 2).'/js/cash-flow.js') ?>" defer></script>

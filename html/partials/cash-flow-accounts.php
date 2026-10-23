<section class="business-overview" aria-labelledby="cash-flow-accounts-title">
    <div class="section-heading"><h2 id="cash-flow-accounts-title">Cajas y bancos</h2></div>
    <div class="cash-flow-accounts-grid" data-cash-flow-account-grid data-history-prefix="<?= htmlspecialchars($cashFlowAccountHrefPrefix,ENT_QUOTES,'UTF-8') ?>">
    <?php foreach($cashFlowAccounts as $item): ?>
    <a class="overview-card cash-flow-account-card" data-cash-flow-account="<?= (int)$item['id'] ?>" href="<?= htmlspecialchars($cashFlowAccountHrefPrefix.'ingresos-egresos.php?'.cashFlowQuery($cashFlowRange,(int)$item['id']),ENT_QUOTES,'UTF-8') ?>">
        <div class="cash-flow-account-heading"><h3><?= htmlspecialchars($item['name'],ENT_QUOTES,'UTF-8') ?></h3><span class="module-status"><?= $item['account_type']==='cash'?'Caja':'Banco' ?></span></div>
        <?php foreach($item['currencies'] as $currency): ?><div class="cash-flow-account-currency"><strong><?= htmlspecialchars($currency['code'],ENT_QUOTES,'UTF-8') ?></strong><dl class="cash-flow-account-totals">
        <?php foreach(['balance_start'=>'Saldo al inicio','income'=>'Ingresos','expense'=>'Egresos','net'=>'Diferencia','transfer_in'=>'Transferencias recibidas','transfer_out'=>'Transferencias enviadas','opening_changes'=>'Saldos iniciales del período','balance_end'=>'Saldo al cierre'] as $key=>$label): ?>
        <?php if(in_array($key,['transfer_in','transfer_out','opening_changes'],true)&&bccomp($currency[$key],'0',2)===0)continue; ?>
        <div class="cash-flow-<?= $key ?>"><dt><?= $label ?></dt><dd><?= htmlspecialchars($currency[$key],ENT_QUOTES,'UTF-8') ?></dd></div>
        <?php endforeach; ?></dl><?php require __DIR__.'/cash-flow-methods.php'; ?></div><?php endforeach; ?>
        <div class="cash-flow-account-footer"><span><?= $item['count'] ?> movimientos confirmados</span><span class="overview-link">Ver historial →</span></div>
    </a><?php endforeach; ?>
    <?php if(!$cashFlowAccounts): ?><p class="section-subtitle">No hay cajas ni bancos configurados.</p><?php endif; ?>
    </div>
</section>

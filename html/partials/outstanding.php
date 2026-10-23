<?php $balanceEscape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<?php foreach(['customer'=>'Cuentas por cobrar','supplier'=>'Cuentas por pagar'] as $balanceType=>$balanceTitle): $balanceSection=$balanceSections[$balanceType]??null;if($balanceSection===null)continue; ?>
<section class="business-overview outstanding-section" id="accounts-<?= $balanceType ?>" data-outstanding-section="<?= $balanceType ?>">
    <div class="section-heading"><div><h2><?= $balanceTitle ?></h2><p class="section-subtitle">Saldos actuales de facturas registradas · <?= $balanceType==='customer'?'Clientes':'Proveedores' ?></p></div>
    <?php if($balanceLive): ?><a class="overview-link" href="<?= $balancePrefix ?>cuentas-pendientes.php#accounts-<?= $balanceType ?>">Ver informe completo →</a><?php endif; ?></div>
    <div class="outstanding-totals" data-outstanding-totals="<?= $balanceType ?>">
    <?php foreach($balanceSection['currencies'] as $amount): ?><article class="overview-card"><span class="overview-label"><?= $balanceEscape($amount['code']) ?></span><dl class="cash-flow-account-totals">
        <?php foreach(['balance'=>'Saldo pendiente','overdue'=>'Vencido','pending'=>'Pagos en proceso'] as $key=>$label): ?><div><dt><?= $label ?></dt><dd><?= $balanceEscape($amount[$key]) ?></dd></div><?php endforeach; ?></dl><small><?= (int)$amount['count'] ?> facturas con saldo</small></article><?php endforeach; ?>
    <?php if($balanceLive&&!$balanceSection['currencies']): ?><article class="overview-card"><span class="overview-label">Saldo pendiente</span><strong>0.00</strong></article><?php endif; ?>
    </div>
    <?php if(!$balanceLive): ?>
    <div class="outstanding-groups">
    <?php foreach($balanceSection['groups'] as $group): ?>
    <details class="contact-sheet outstanding-partner" data-outstanding-partner="<?= (int)$group['contact_id'] ?>"><summary><span><strong><?= $balanceEscape($group['contact_name']) ?></strong><small><?= $balanceEscape($group['company_name']) ?> · <?= (int)$group['count'] ?> facturas · Vencido: <?= $balanceEscape($group['overdue'].' '.$group['code']) ?></small></span><strong><?= $balanceEscape($group['balance'].' '.$group['code']) ?></strong></summary>
        <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Factura</th><th>Fecha</th><th>Vencimiento</th><th>Total</th><th>Notas de crédito</th><th>Pagado</th><th>En proceso</th><th>Saldo pendiente</th></tr></thead><tbody>
        <?php foreach($group['invoices'] as $invoice): ?><tr data-outstanding-invoice="<?= (int)$invoice['id'] ?>"><td><a class="contact-name" href="<?= $balancePrefix ?>index.php?type=<?= $balanceType ?>&amp;id=<?= (int)$invoice['id'] ?>"><?= $balanceEscape($invoice['name']) ?></a></td><td><?= $balanceEscape($invoice['invoice_date']) ?></td><td class="<?= $invoice['overdue']?'outstanding-overdue':'' ?>"><?= $balanceEscape($invoice['due_date']??'Sin vencimiento') ?></td><?php foreach(['total_amount','credited','paid','pending','balance'] as $key): ?><td class="quotation-money"><?= $balanceEscape($invoice[$key].' '.$invoice['currency_code']) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php endforeach; ?>
    <?php if(!$balanceSection['count']): ?><p class="section-subtitle">No hay <?= $balanceType==='customer'?'cuentas por cobrar':'cuentas por pagar' ?> pendientes con estos filtros.</p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if(!$balanceLive&&($balanceSection['pages']??1)>1): ?><nav class="contact-pagination"><span>Página <?= $balanceSection['page'] ?> de <?= $balanceSection['pages'] ?></span><?php foreach(['Anterior'=>$balanceSection['page']-1,'Siguiente'=>$balanceSection['page']+1] as $label=>$number): if($number<1||$number>$balanceSection['pages'])continue; ?><a class="contact-button" href="cuentas-pendientes.php?<?= $balanceEscape(http_build_query(array_replace($modulePageData['filters'],[$balanceType.'_page'=>$number]))) ?>#accounts-<?= $balanceType ?>"><?= $label ?></a><?php endforeach; ?></nav><?php endif; ?>
</section>
<?php endforeach; ?>

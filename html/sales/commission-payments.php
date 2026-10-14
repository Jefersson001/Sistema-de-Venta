<?php $page=$modulePageData; $e='salesEscape'; $record=$page['record']; ?>
<?php foreach($page['errors'] as $message): ?><p class="notice notice-error" role="alert"><?= $e($message) ?></p><?php endforeach; ?>
<?php if($page['available']): ?>
<section class="cash-flow" data-cash-flow>
    <div class="contact-toolbar"><div>
        <?php if($page['view']!=='list'): ?><a class="overview-link" href="pagos-comisiones.php">← Pagos de comisiones</a><?php else: ?><p class="eyebrow">VENTAS</p><?php endif; ?>
        <h1><?= $page['view']==='form' ? $e($record['name']) : ($page['view']==='new' ? 'Pagar comisión' : 'Pagos de comisiones') ?></h1>
    </div><?php if($page['view']==='list'): ?><a class="contact-button contact-button-primary" href="pagos-comisiones.php?new=1&amp;<?= $e(cashFlowQuery($page['range']).'&'.http_build_query(['seller_id'=>$page['sellerId']])) ?>">Nuevo</a><?php elseif($page['view']==='form'): ?><span class="contact-tag"><?= $record['reversed_by_id']?'Revertido':'Pagado' ?></span><?php endif; ?></div>
    <?php if($page['view']!=='form'): ?>
    <form class="contact-filters" action="pagos-comisiones.php" data-cash-flow-filter>
        <?php if($page['view']==='new'): ?><input type="hidden" name="new" value="1"><?php if($page['commissionCurrency']!==''): ?><input type="hidden" name="commission_currency" value="<?= $e($page['commissionCurrency']) ?>"><?php endif; ?><?php endif; ?>
        <label>Vendedor<select name="seller_id" data-auto-submit><option value=""><?= $page['view']==='new'?'Selecciona un vendedor':'Todos los vendedores' ?></option><?php foreach($page['sellers'] as $seller): ?><option value="<?= (int)$seller['id'] ?>" <?= $page['sellerId']===(int)$seller['id']?'selected':'' ?>><?= $e($seller['name']) ?></option><?php endforeach; ?></select></label>
        <label>Período<select name="period" data-cash-flow-period><?php foreach(cashFlowPeriods() as $key=>$label): ?><option value="<?= $key ?>" <?= $page['range']['period']===$key?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
        <div class="cash-flow-date-range" data-cash-flow-dates <?= $page['range']['period']!=='custom'?'hidden':'' ?>><label>Desde<input type="date" name="date_from" min="1000-01-01" max="9999-12-31" value="<?= $e($page['range']['date_from']) ?>"></label><label>Hasta<input type="date" name="date_to" min="1000-01-01" max="9999-12-31" value="<?= $e($page['range']['date_to']) ?>"></label></div>
    </form><p class="cash-flow-error" data-cash-flow-error hidden></p>
    <?php if($page['view']==='new' && $page['payable']): ?><form class="contact-filters" action="pagos-comisiones.php">
        <?php foreach(array_merge(['new'=>1,'seller_id'=>$page['sellerId'],'commission_currency'=>$page['commissionCurrency']],$page['range']['period']==='custom'?['period'=>'custom','date_from'=>$page['range']['date_from'],'date_to'=>$page['range']['date_to']]:['period'=>$page['range']['period']]) as $key=>$value): ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e((string)$value) ?>"><?php endforeach; ?>
        <label>Factura / comisión<select name="invoice_id" data-auto-submit><?php foreach($page['payable'] as $commission): ?><option value="<?= (int)$commission['id'] ?>" <?= (int)($record['id']??0)===(int)$commission['id']?'selected':'' ?>><?= $e($commission['name'].' · '.$commission['customer_name'].' · Pendiente '.$commission['commission_pending'].' '.$commission['currency_code']) ?></option><?php endforeach; ?></select></label>
    </form><?php endif; ?>
    <?php endif; ?>
    <?php if($page['view']==='list'): ?>
        <p class="section-subtitle">Período por fecha de pago. Cada registro corresponde al mismo egreso registrado en Tesorería; los pagos revertidos se conservan.</p>
        <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Pago</th><th>Fecha</th><th>Vendedor</th><th>Factura</th><th>Cuenta de egreso</th><th>Medio de pago</th><th>Importe pagado</th><th>Comisión aplicada</th><th>Estado</th></tr></thead><tbody>
        <?php foreach($page['rows'] as $payment): ?><tr><td><a class="contact-name" href="pagos-comisiones.php?id=<?= (int)$payment['id'] ?>"><?= $e($payment['name']) ?></a></td><td><?= $e($payment['movement_date']) ?></td><td><?= $e($payment['seller_name']) ?></td><td><a class="inline-link" href="comisiones.php?id=<?= (int)$payment['invoice_id'] ?>"><?= $e($payment['invoice_name']) ?></a></td><td><?= $e($payment['account_name']) ?></td><td><?= $e($payment['method_name']) ?></td><td><?= $e($payment['payment_amount'].' '.$payment['currency_code']) ?></td><td><?= $e($payment['amount'].' '.$payment['invoice_currency']) ?></td><td><?= $payment['reversed_by_id']?'Revertido':'Pagado' ?></td></tr><?php endforeach; ?>
        <?php if(!$page['rows']): ?><tr><td colspan="9" class="contact-empty">No hay pagos de comisiones en este período.</td></tr><?php endif; ?></tbody></table></div>
        <nav class="contact-pagination" aria-label="Páginas de pagos de comisiones"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span><?php foreach(['Anterior'=>$page['currentPage']-1,'Siguiente'=>$page['currentPage']+1] as $label=>$number): ?><?php if($number>=1&&$number<=$page['pages']): ?><a class="contact-button" href="pagos-comisiones.php?<?= $e(cashFlowQuery($page['range']).'&'.http_build_query(['seller_id'=>$page['sellerId'],'page'=>$number])) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
    <?php elseif($page['view']==='form'): ?>
        <div class="document-actions"><a class="contact-button" href="comisiones.php?id=<?= (int)$record['invoice_id'] ?>">Comisión relacionada</a><a class="contact-button" href="../contabilidad/index.php?type=customer&amp;id=<?= (int)$record['invoice_id'] ?>">Factura · <?= $e($record['invoice_name']) ?></a><?php if($record['sale_id']): ?><a class="contact-button" href="index.php?id=<?= (int)$record['sale_id'] ?>">Venta · <?= $e($record['sale_name']) ?></a><?php endif; ?><a class="contact-button" href="../contabilidad/tesoreria.php?id=<?= (int)$record['treasury_document_id'] ?>">Movimiento en Tesorería</a><?php if($record['reversed_by_id']): ?><a class="contact-button" href="../contabilidad/tesoreria.php?id=<?= (int)$record['reversed_by_id'] ?>">Reversión · <?= $e($record['reversal_name']) ?></a><?php endif; ?></div>
        <div class="contact-sheet"><div class="contact-form-grid">
            <?php foreach(['seller_name'=>'Vendedor','customer_name'=>'Cliente','invoice_name'=>'Factura','movement_date'=>'Fecha de pago','account_name'=>'Cuenta de egreso','method_name'=>'Medio de pago'] as $key=>$label): ?><label><?= $label ?><input readonly value="<?= $e($record[$key]) ?>"></label><?php endforeach; ?>
            <label>Importe pagado<input readonly value="<?= $e($record['payment_amount'].' '.$record['currency_code']) ?>"></label><label>Comisión aplicada<input readonly value="<?= $e($record['amount'].' '.$record['invoice_currency']) ?>"></label><label>Tasa de cambio<input readonly value="<?= $e($record['exchange_rate']) ?>"></label><label>Referencia<input readonly value="<?= $e($record['reference']) ?>"></label>
        </div><?php if($record['reversed_by_id']): ?><p class="notice">Este pago fue revertido. Su salida original y el movimiento de devolución se conservan en Tesorería.</p><?php endif; ?></div>
    <?php else: ?>
        <p class="section-subtitle">Selecciona la factura cuya comisión vas a pagar. El período filtra la fecha de factura; el egreso quedará registrado en la fecha de pago.</p>
        <?php if($page['hasAdjustment']): ?><p class="notice notice-error">Este vendedor tiene un ajuste por recuperar, incluso fuera de este período. Revisa la comisión y su pago antes de registrar otro pago.</p><?php endif; ?>
        <?php if(!$record): ?><p class="notice"><?= $page['sellerId']?'No hay comisiones pendientes habilitadas por cobros para este vendedor y período.':'Selecciona un vendedor para consultar sus comisiones pendientes.' ?></p>
        <?php else: ?>
            <div class="document-actions"><a class="contact-button" href="comisiones.php?id=<?= (int)$record['id'] ?>">Comisión relacionada</a><a class="contact-button" href="../contabilidad/index.php?type=customer&amp;id=<?= (int)$record['id'] ?>">Factura · <?= $e($record['name']) ?></a></div>
            <div class="contact-sheet"><div class="contact-form-grid"><label>Vendedor<input readonly value="<?= $e($record['seller_name']) ?>"></label><label>Cliente<input readonly value="<?= $e($record['customer_name']) ?>"></label><label>Factura<input readonly value="<?= $e($record['name']) ?>"></label><label>Pendiente de pago<input readonly value="<?= $e($record['commission_pending'].' '.$record['currency_code']) ?>"></label></div>
            <?php if(!$page['hasAdjustment'] && bccomp($record['commission_pending'],'0',2)>0): $input=$page['formInput']; ?>
                <form method="post" action="<?= $e($page['paymentAction']) ?>" data-commission-payment-form>
                    <?php require __DIR__.'/commission-payment-fields.php'; ?>
                    <div class="contact-form-actions"><button class="contact-button contact-button-primary">Confirmar pago</button><a class="contact-button" href="pagos-comisiones.php">Cancelar</a></div>
                </form>
                <script type="application/json" data-currency-settings><?= json_encode($page['settings'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script src="../../js/currency.js" defer></script><script type="application/json" data-commission-settings><?= json_encode(['invoice_currency'=>$record['currency_code']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script src="../../js/commissions.js?v=<?= filemtime(dirname(__DIR__,2).'/js/commissions.js') ?>" defer></script>
            <?php else: ?><p class="notice">El pago no está habilitado para esta comisión.</p><?php endif; ?></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php if($page['view']!=='form'): ?><script src="../../js/cash-flow.js?v=<?= filemtime(dirname(__DIR__,2).'/js/cash-flow.js') ?>" defer></script><?php endif; ?>
<?php endif; ?>

<?php
$record = $page['record']; $posted = $record['status'] !== 'draft';
$credit = $record['document_type']==='credit';
$details = $page['paymentDetails'];
$available = bcsub($details['effective_amount'], bcadd($details['settled'], $details['pending'], 2), 2);
$canRegisterPayment = $record['status'] === 'posted' && $record['payment_state'] !== 'reversed' && bccomp($page['debt']['balance'], '0', 2) > 0;
$canCreateCredit = $record['status'] === 'posted' && !$credit && $record['payment_state'] !== 'reversed';
$statusLabels = invoiceFilterStates();
$paymentTone=match($record['payment_state']){'paid'=>'success','not_paid','in_payment','partial'=>'warning','reversed'=>'danger',default=>'neutral'};
$invoiceOperations=array_filter($page['links']['operations']??[],static fn(array $operation):bool=>empty($operation['return_of_id'])&&$operation['status']!=='cancel');
$invoiceCompletedOperations=count(array_filter($invoiceOperations,static fn(array $operation):bool=>$operation['status']==='done'));
?>
<section aria-labelledby="invoice-title">
    <div class="contact-toolbar invoice-toolbar">
    </div>
    <?php if($credit): ?><p class="notice">Nota de crédito · <?= accountingEscape($record['credit_reason']) ?> · <a href="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['reversal_of_id'] ?>">Factura original</a></p><?php endif; ?>
    <?php foreach($page['paymentDetails']['credits'] as $creditDocument): ?><p class="notice"><a href="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$creditDocument['id'] ?>">Nota de crédito <?= accountingEscape($creditDocument['name']) ?></a> · <?= invoiceStatuses()[$creditDocument['status']] ?></p><?php endforeach; ?>
    <div class="document-actions invoice-document-actions" role="group" aria-label="Acciones de la factura">
        <?php if(permissionHas(permissionInvoiceResource($page['type']),'send')): ?><a class="contact-button" data-open-invoice-email href="enviar.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>">Enviar por correo</a><a class="contact-button" data-open-document-share href="compartir.php?type=<?= $page['type'] ?>&amp;id=<?= (int)$record['id'] ?>">WhatsApp</a><?php endif; ?>
        <?php $documentView = 'invoice'; require dirname(__DIR__) . '/commerce/links.php'; ?>
        <?php if(!$credit && $page['type']==='customer' && !empty($record['salesperson_id']) && array_filter($page['lines'],static fn($line)=>isset($line['commission_unit_amount']) ? bccomp($line['commission_unit_amount'],'0',2)>0 : (isset($line['commission_reference_price']) && bccomp($line['unit_price'],$line['commission_reference_price'],2)>0))): ?><a class="contact-button" href="../ventas/comisiones.php?id=<?= (int)$record['id'] ?>">Comisión del vendedor</a><?php endif; ?>
        <?php require __DIR__ . '/invoice-status-actions.php'; ?>
    </div>
    <dl class="workspace-balance-summary" aria-label="Resumen de la factura">
        <div><dt>Total de la factura</dt><dd><?= accountingEscape($record['total_amount'].' '.$record['currency_code']) ?></dd></div>
        <div class="workspace-balance-paid"><dt>Pagado<?= $page['debt']['indexed']?' · referencia USD':'' ?></dt><dd><?= accountingEscape($page['debt']['settled'].' '.$page['debt']['currency']) ?></dd></div>
        <div class="workspace-balance-due"><dt>Saldo sin pagar<?= $page['debt']['indexed']?' · referencia USD':'' ?></dt><dd><?= accountingEscape(($record['payment_state']==='reversed'?'0.00':$page['debt']['unpaid']).' '.$page['debt']['currency']) ?></dd></div>
    </dl>
    <div class="contact-sheet quotation-sheet"><form id="invoice-details" method="post" action="index.php?type=<?= $page['type'] ?>&amp;id=<?= (int) $record['id'] ?>" data-invoice-rate-form data-rate-endpoint="<?= accountingEscape(authBasePath().'/tasa.php?invoice_id='.(int)$record['id']) ?>">
        <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="version" value="<?= (int) $record['version'] ?>">
        <fieldset <?= $posted ? 'disabled' : '' ?> class="quotation-fields">
<section class="workspace-form-section" aria-labelledby="invoice-title"><header class="invoice-record-heading"><h1 id="invoice-title"><?= accountingEscape($record['name']) ?></h1>
        <div class="document-state-groups" role="region" aria-label="Estados de la factura">
            <div class="document-state-group"><span class="document-state-label">Documento</span><span class="workspace-status" data-tone="<?= $record['status']==='posted'?'success':($record['status']==='cancel'?'danger':'neutral') ?>"><?= accountingEscape($statusLabels[$record['status']]) ?></span></div>
            <?php if($record['status']==='posted'): ?><div class="document-state-group"><span class="document-state-label"><?= $credit?'Reembolso':($page['type']==='customer'?'Cobro':'Pago') ?></span><span class="workspace-status" data-tone="<?= $paymentTone ?>"><?= accountingEscape($statusLabels[$record['payment_state']]) ?></span></div><?php endif; ?>
            <?php if($invoiceOperations): ?><div class="document-state-group"><span class="document-state-label"><?= $page['type']==='customer'?'Entregas':'Recepciones' ?></span><span class="workspace-status" data-tone="<?= $invoiceCompletedOperations===count($invoiceOperations)?'success':'warning' ?>"><?= $invoiceCompletedOperations ?> de <?= count($invoiceOperations) ?> realizadas</span></div><?php endif; ?>
            <?php if($record['sent_at']): ?><div class="document-state-group"><span class="document-state-label">Envío</span><span class="workspace-status" data-tone="info">Enviada</span></div><?php endif; ?>
        </div>
</header><div class="contact-form-grid">
<label><?= $page['type'] === 'customer' ? 'Cliente' : 'Proveedor' ?><input readonly value="<?= accountingEscape($record['contact_name']) ?>"></label>
<label>Teléfono<input type="tel" readonly value="<?= accountingEscape($record['contact_phone'] ?? '') ?>" placeholder="Sin teléfono registrado"></label>
<label>Dirección de factura<textarea readonly rows="3"><?= accountingEscape($record['invoice_address']) ?></textarea></label>
<label>Términos de pago<input readonly value="<?= accountingEscape($record['term_name'] ?? '') ?>"></label>
</div></section>
<section class="workspace-form-section" aria-labelledby="invoice-section-1"><h2 id="invoice-section-1" class="workspace-section-title">Fechas y moneda</h2><div class="contact-form-grid">
<label>Fecha de factura *<input type="date" name="invoice_date" required value="<?= accountingEscape($record['invoice_date']) ?>"></label>
<label>Fecha de vencimiento<input type="date" name="due_date" value="<?= accountingEscape($record['due_date'] ?? '') ?>"></label>
<label>Moneda<input readonly value="<?= accountingEscape($record['currency_code']) ?>"></label>
<label>Tasa de la factura<input readonly data-invoice-rate value="<?= accountingEscape($record['exchange_rate']??'') ?>"><small>1 <?= accountingEscape($record['currency_primary_code']) ?> = tasa en <?= accountingEscape($record['currency_alternate_code']) ?>.</small><small data-invoice-rate-date><?= accountingEscape(($record['exchange_rate_date']??'Fecha histórica no registrada').' · '.(($record['exchange_rate_source']??'')==='bcv'?'BCV':(($record['exchange_rate_source']??'')==='manual'?'Manual':'Tasa guardada'))) ?></small></label>
</div></section></fieldset></form><div class="contact-notebook" data-notebook><nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la factura"><a id="invoice-tab-lines" href="#invoice-lines">Líneas de factura</a><a id="invoice-tab-other" href="#invoice-other">Otra información</a></nav>
            <section class="notebook-page" id="invoice-lines" data-notebook-page aria-labelledby="invoice-tab-lines">
                <div class="contact-table-wrap"><table class="contact-table">
                    <thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Impuesto</th><th scope="col">Base imponible</th><?php if ($page['showCommission']): ?><th scope="col" title="Comisión por la cantidad facturada, incluida en el precio">Comisión</th><?php endif; ?><th scope="col">Total</th></tr></thead>
                    <tbody><?php foreach ($page['lines'] as $line): ?><tr>
                        <td><a class="contact-name" href="../inventario/index.php?id=<?= (int) $line['product_id'] ?>"><?= accountingEscape($line['product_name']) ?></a></td>
                        <td><?php if($credit && !$posted): ?><input form="invoice-details" name="credit_quantities[<?= (int)$line['id'] ?>]" inputmode="decimal" value="<?= accountingEscape($line['quantity']) ?>" aria-label="Cantidad a revertir"><?php else: ?><?= accountingEscape($line['quantity']) ?><?php endif; ?></td>
                        <td><?= accountingEscape($line['unit_price']) ?></td><td><?= accountingEscape($line['tax_name'] ?? '—') ?></td><td><?= accountingEscape($line['base_amount']) ?></td>
                        <?php if ($page['showCommission']): ?><td data-invoice-line-commission><?= accountingEscape($line['commission_amount']) ?></td><?php endif; ?>
                        <td><?= accountingEscape($line['total_amount']) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
                <p class="section-subtitle">Los porcentajes e importes se conservan desde la orden de origen.</p>
                <dl class="quotation-totals">
                    <div><dt>Base imponible</dt><dd><?= accountingEscape($record['base_amount']) ?></dd></div>
                    <?php if ($page['showCommission']): ?><div data-invoice-commission-total><dt>Comisión incluida</dt><dd><?= accountingEscape($page['commissionTotal']) ?> <?= accountingEscape($record['currency_code']) ?></dd></div><?php endif; ?>
                    <div data-invoice-total><dt>Total</dt><dd><?= accountingEscape($record['total_amount']) ?></dd></div>
                    <?php $currencyReference=invoiceCurrencyReference($record); ?>
                    <div><dt>Referencia en otra moneda</dt><dd data-invoice-currency-reference><?= $currencyReference?accountingEscape($currencyReference['amount'].' '.$currencyReference['code']):'Sin tasa para convertir' ?></dd></div>
                </dl>
                <?php if ($page['showCommission']): ?><p class="section-subtitle">La comisión ya está incluida en el precio; no aumenta el total. Su cobro, pago y ajustes se consultan en Comisiones.</p><?php endif; ?>
                <?php require __DIR__ . '/invoice-payments.php'; ?>
            </section><section class="notebook-page" id="invoice-other" data-notebook-page aria-labelledby="invoice-tab-other"><div class="contact-form-grid"><?php if ($page['type'] === 'customer'): ?><label>Vendedor relacionado<input readonly value="<?= accountingEscape($record['seller_name'] ?? '') ?>"></label><?php endif; ?><label>Documento de origen<input readonly value="<?= accountingEscape($page['links']['source']['name']??'Sin orden vinculada') ?>"></label></div>
    <?php if($customerMap=geolocationMapUrl($record['delivery_latitude']??null,$record['delivery_longitude']??null)): ?><a class="contact-button" href="<?= accountingEscape($customerMap) ?>" target="_blank" rel="noopener noreferrer">Abrir mapa de entrega en otra pestaña</a><?php $mapLatitude=$record['delivery_latitude'];$mapLongitude=$record['delivery_longitude'];$mapTitle='Ubicación de entrega';require dirname(__DIR__).'/partials/geolocation-map.php';unset($mapLatitude,$mapLongitude,$mapTitle); ?><?php endif; ?>
            </section>
        </div><div class="contact-form-actions"><?php if (! $posted): ?><button class="contact-button" type="submit" form="invoice-details" name="action" value="save_invoice">Guardar factura</button><button class="contact-button contact-button-primary" type="submit" form="invoice-details" name="action" value="confirm_invoice">Registrar factura</button><?php endif; ?><a class="contact-button" href="index.php?type=<?= $page['type'] ?>">Volver al listado</a></div>
    </div>
    <?php if(!empty($record['delivery_address'])): ?><details class="workspace-details"><summary>Dirección de entrega conservada</summary><p><?= nl2br(accountingEscape($record['delivery_address'])) ?></p></details><?php endif; ?>
    <?php require __DIR__ . '/invoice-payment-wizard.php'; ?>
    <script src="../../js/invoice-rate.js?v=<?= filemtime(dirname(__DIR__,2).'/js/invoice-rate.js') ?>" defer></script>
    <?php if(permissionHas(permissionInvoiceResource($page['type']),'send')): ?>
    <?php require dirname(__DIR__).'/partials/document-email-dialog.php'; ?>
    <?php require dirname(__DIR__).'/partials/document-share-dialog.php'; ?>
    <?php endif; ?>
</section>

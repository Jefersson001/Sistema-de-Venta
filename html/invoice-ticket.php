<?php
extract($document,EXTR_SKIP);$e=static fn($v)=>authEscape($v??'');$number=static fn($v)=>decimalDisplay((string)$v);$currency=$row['currency_code'];
$supplier=$row['invoice_type']==='supplier';$credit=$row['document_type']==='credit';
$ticketTitle=$credit?'NOTA DE CRÉDITO':($supplier?'TICKET DE COMPRA':'TICKET DE VENTA');
$baseQuery=['source'=>$source,'action'=>'print','format'=>'ticket'];
$balance=$row['payment_state']==='reversed'?'0.00':bcsub($ticketPayable,$paymentDetails['settled'],2);if(bccomp($balance,'0',2)<0)$balance='0.00';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($ticketTitle.' · '.$row['name']) ?></title><?php require __DIR__.'/partials/favicon.php'; ?>
<link rel="stylesheet" href="<?= $e(authBasePath()) ?>/css/invoice-ticket.css?v=<?= filemtime(dirname(__DIR__).'/css/invoice-ticket.css') ?>">
<script src="<?= $e(authBasePath()) ?>/js/invoice-ticket.js?v=<?= filemtime(dirname(__DIR__).'/js/invoice-ticket.js') ?>" defer></script></head>
<body data-ticket-width="<?= $width ?>">
<nav class="ticket-toolbar" aria-label="Impresión de ticket"><a href="<?= $e($source) ?>">← Factura</a><a href="<?= $e(authBasePath().'/acciones.php?'.http_build_query($baseQuery+['width'=>'80'])) ?>" <?= $width==='80'?'aria-current="page"':'' ?>>80 mm</a><a href="<?= $e(authBasePath().'/acciones.php?'.http_build_query($baseQuery+['width'=>'58'])) ?>" <?= $width==='58'?'aria-current="page"':'' ?>>58 mm</a><button type="button" data-print-ticket>Imprimir / Guardar PDF</button></nav>
<p class="ticket-help">Selecciona el ancho del papel de tu impresora. En la ventana de impresión usa escala 100 % y desactiva encabezados y pies del navegador.</p>
<main class="invoice-ticket" aria-label="Ticket de la factura">
<header class="ticket-center">
<?php if(!empty($company['logo_data'])&&in_array($company['logo_mime']??'',['image/png','image/jpeg','image/webp'],true)): ?><img class="ticket-logo" alt="Logo" src="data:<?= $e($company['logo_mime']) ?>;base64,<?= base64_encode($company['logo_data']) ?>"><?php endif; ?>
<h1><?= $e($company['legal_name']?:$company['name']) ?></h1>
<?php foreach(['tax_id'=>'RIF / Documento','address'=>'','phone'=>'Tel.'] as $field=>$label): ?><?php if(!empty($company[$field])): ?><p><?= $label?$e($label).': ':'' ?><?= nl2br($e($company[$field])) ?></p><?php endif; ?><?php endforeach; ?>
<div class="ticket-rule"><h2><?= $e($ticketTitle) ?></h2><strong><?= $e($row['name']) ?></strong></div>
</header>
<section class="ticket-identity"><p>Fecha: <?= $e(implode('/',array_reverse(explode('-',$row['invoice_date'])))) ?></p><p>Estado: <?= $e($status) ?></p><?php if($registeredBy): ?><p>Registrado por: <?= $e($registeredBy) ?></p><?php endif; ?><p><?= $supplier?'Proveedor':'Cliente' ?>: <?= $e($contact['name']??'') ?></p><?php foreach(['tax_id'=>'Documento','phone'=>'Teléfono','address'=>'Dirección'] as $field=>$label): ?><?php if(!empty($contact[$field])): ?><p><?= $label ?>: <?= nl2br($e($contact[$field])) ?></p><?php endif; ?><?php endforeach; ?><?php foreach($references as $label=>$value): ?><p><?= $e($label.': '.$value) ?></p><?php endforeach; ?><?php if(!empty($meta['Condiciones'])): ?><p>Condiciones: <?= $e($meta['Condiciones']) ?></p><?php endif; ?><?php if(!empty($row['due_date'])): ?><p>Vence: <?= $e(implode('/',array_reverse(explode('-',$row['due_date'])))) ?></p><?php endif; ?></section>
<?php if($row['status']!=='posted'): ?><p class="ticket-center ticket-rule"><strong><?= $row['status']==='draft'?'BORRADOR · SIN CONFIRMAR':'DOCUMENTO CANCELADO' ?></strong></p><?php endif; ?>
<table><thead><tr><th>Cant.</th><th>Descripción</th><th>Importe</th></tr></thead><tbody><?php foreach($lines as $line): ?><tr><td><?= $number($line['quantity']) ?></td><td><?= $e($line['product_name']) ?><small>× <?= $number($line['unit_price']) ?> <?= $e($currency) ?><?php if(bccomp($line['tax_rate']??'0','0',2)>0): ?> · <?= $number($line['tax_rate']) ?> %<?php endif; ?></small></td><td><?= $number($line['total_amount']) ?></td></tr><?php endforeach; ?></tbody></table>
<dl class="ticket-totals"><div><dt>Base</dt><dd><?= $number($row['base_amount']) ?></dd></div><?php foreach($taxes as $tax): ?><div><dt><?= $e($tax['name']) ?></dt><dd><?= $number($tax['amount']) ?></dd></div><?php endforeach; ?><div class="ticket-total"><dt>TOTAL <?= $e($currency) ?></dt><dd><?= $number($row['total_amount']) ?></dd></div></dl>
<section class="ticket-rule"><p>Estado de pago: <?= $e(invoicePaymentStates()[$row['payment_state']]??$row['payment_state']) ?></p>
<?php foreach($ticketPayments as $payment): ?><p><?= $payment['status']==='settled'?'Pago confirmado':'Pago en revisión' ?>: <?= $e($payment['method_name']) ?> · <?= $number($payment['amount']) ?> <?= $e($currency) ?></p><?php endforeach; ?>
<?php if(bccomp($paymentDetails['credited'],'0',2)>0): ?><p>Notas de crédito: <?= $number($paymentDetails['credited']) ?> <?= $e($currency) ?></p><?php endif; ?>
<dl class="ticket-totals"><div><dt><?= $credit?'Reembolsado confirmado':'Pagado confirmado' ?></dt><dd><?= $number($paymentDetails['settled']) ?> <?= $e($currency) ?></dd></div><?php if(bccomp($paymentDetails['pending'],'0',2)>0): ?><div><dt>En revisión</dt><dd><?= $number($paymentDetails['pending']) ?> <?= $e($currency) ?></dd></div><?php endif; ?><div><dt><?= $credit?'Saldo por reembolsar':'Saldo pendiente' ?></dt><dd><?= $number($balance) ?> <?= $e($currency) ?></dd></div></dl></section>
<?php if(!empty($row['credit_reason'])||!empty($row['notes'])): ?><p class="ticket-rule"><?= nl2br($e($row['credit_reason']??$row['notes'])) ?></p><?php endif; ?>
<footer class="ticket-center ticket-rule"><p><?= $supplier?'Comprobante de compra registrada.':'¡Gracias por su compra!' ?></p><p>Konta · <?= $e($row['name']) ?></p></footer>
</main></body></html>

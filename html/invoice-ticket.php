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
<?php require __DIR__.'/partials/invoice-ticket-content.php'; ?></body></html>

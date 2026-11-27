<?php
extract($document,EXTR_SKIP);$e=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$number=static fn($v)=>decimalDisplay((string)$v);
$currency=$row['currency_code'];$supplier=$row['invoice_type']==='supplier';$credit=$row['document_type']==='credit';
$ticketTitle=$credit?'NOTA DE CRÉDITO':($supplier?'TICKET DE COMPRA':'TICKET DE VENTA');
$balance=$row['payment_state']==='reversed'?'0.00':bcsub($ticketPayable,$paymentDetails['settled'],2);if(bccomp($balance,'0',2)<0)$balance='0.00';
if(($company['logo_mime']??'')==='image/png'&&!function_exists('imagecreatefromstring'))unset($company['logo_data']);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
<?= file_get_contents(dirname(__DIR__).'/css/invoice-ticket.css') ?>
@page { margin:3mm; }
body { margin:0; padding:0; background:white; }
.invoice-ticket { width:74mm; max-width:none; margin:0; padding:0; font-family:Courier,monospace; }
.ticket-totals>div { display:table; width:100%; }
.ticket-totals dt,.ticket-totals dd { display:table-cell; width:50%; }
.ticket-totals dd { text-align:right; }
</style></head><body><?php require __DIR__.'/partials/invoice-ticket-content.php'; ?></body></html>

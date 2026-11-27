<?php
$directPrintOptions=[
    ['Imprimir factura','contabilidad.customer_invoices',(int)$detail['invoice_id'],'contabilidad/index.php?type=customer&id='.(int)$detail['invoice_id'],'a4'],
    ['Imprimir ticket de factura','contabilidad.customer_invoices',(int)$detail['invoice_id'],'contabilidad/index.php?type=customer&id='.(int)$detail['invoice_id'],'ticket'],
    ['Imprimir orden de venta','ventas.orders',(int)$detail['sale_id'],'ventas/index.php?id='.(int)$detail['sale_id'],'a4'],
    ['Imprimir entrega','inventario.deliveries',(int)$detail['operation_id'],'inventario/operaciones.php?type=delivery&id='.(int)$detail['operation_id'],'a4'],
];
?>
<div class="direct-actions direct-print-actions" role="group" aria-label="Imprimir documentos de la venta">
<?php foreach($directPrintOptions as [$label,$resource,$documentId,$route,$format]): if(!permissionRecord($resource,$documentId))continue; ?>
    <a class="contact-button" data-direct-document-print="<?= $format==='ticket'?'ticket':$resource ?>" target="_blank" rel="noopener" href="<?= $e(authBasePath().'/acciones.php?'.http_build_query(['source'=>authBasePath().'/modules/'.$route,'action'=>'print','format'=>$format])) ?>"><?= $e($label) ?></a>
<?php endforeach; ?>
</div>

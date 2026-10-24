<?php
$links=$page['links'];$kind=$page['documentKind'];$sourceId=(int)($links['source']['id']??0);if(!$sourceId)return;
$sale=$kind==='sale';$invoiceType=$sale?'customer':'supplier';$operationType=$sale?'delivery':'receipt';
$filter=($sale?'sale_id=':'purchase_id=').$sourceId;$operations=$links['operations'];$invoices=$links['invoices'];
$showOrigin=in_array($documentView,['operation','invoice'],true);
$showOperation=$documentView==='order';
$stockPending=array_filter($operations,static fn($o)=>!$o['return_of_id']&&in_array($o['status'],['draft','waiting','ready'],true));
$invoiceDraft=array_filter($invoices,static fn($i)=>$i['document_type']==='invoice'&&$i['status']==='draft');
?>
<div class="quotation-state-actions" aria-label="Documentos relacionados">
<?php if($showOrigin): ?><a class="contact-button" data-related-source href="../<?= $sale?'ventas':'compras' ?>/index.php?id=<?= $sourceId ?>"><?= $sale?'Venta':'Compra' ?> · <?= salesEscape($links['source']['name']) ?></a><?php endif; ?>
<?php if($showOperation&&$operations): ?><a class="contact-button" data-related-operation data-quotation-delivery href="../inventario/operaciones.php?type=<?= $operationType ?>&amp;<?= count($operations)===1?'id='.(int)$operations[0]['id']:$filter ?>"><?= $sale?'Entrega':'Recepción' ?> · <?= count($operations) ?></a><?php endif; ?>
<?php if($documentView==='order'&&$links['can_stock']&&!$stockPending): ?><form method="post" action="index.php?id=<?= $sourceId ?>"><input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="action" value="<?= $sale?'create_delivery':'create_receipt' ?>"><button class="contact-button" data-quotation-delivery><?= $sale?'Crear entrega':'Crear recepción' ?></button></form><?php endif; ?>
<?php if($documentView==='order'): ?>
<?php if($invoices): ?><a class="contact-button" data-related-invoice href="../contabilidad/index.php?type=<?= $invoiceType ?>&amp;<?= count($invoices)===1?'id='.(int)$invoices[0]['id']:$filter ?>">Factura · <?= count($invoices) ?></a><?php endif; ?>
<?php if($links['can_invoice']&&!$invoiceDraft): ?><form method="post" action="index.php?id=<?= $sourceId ?>"><input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="action" value="create_invoice"><button class="contact-button contact-button-primary" data-quotation-invoice>Crear factura</button></form><?php endif; ?>
<?php endif; ?>
</div>

<?php
$links = $page['links']; $kind = $page['documentKind']; $sourceId = (int) $links['source']['id'];
$invoiceType = $kind === 'sale' ? 'customer' : 'supplier';
$showOrigin = $documentView === 'operation' || ($documentView === 'invoice' && ($kind === 'sale' || ! $links['operation']));
$showOperation = $documentView === 'order' || ($documentView === 'invoice' && $kind === 'purchase');
?>
<div class="quotation-state-actions" aria-label="Documentos relacionados">
    <?php if ($showOrigin): ?><a class="contact-button" data-related-source href="../<?= $kind === 'sale' ? 'ventas' : 'compras' ?>/index.php?id=<?= $sourceId ?>"><?= $kind === 'sale' ? 'Venta' : 'Orden de compra' ?> · <?= salesEscape($links['source']['name']) ?></a><?php endif; ?>
    <?php if ($showOperation && $links['operation']): ?><a class="contact-button" data-related-operation data-quotation-delivery href="../inventario/operaciones.php?type=<?= $kind === 'sale' ? 'delivery' : 'receipt' ?>&amp;id=<?= (int) $links['operation']['id'] ?>"><?= $documentView === 'invoice' ? 'Orden de recepción' : ($kind === 'sale' ? 'Entrega' : 'Recepción') ?> · 1</a>
    <?php elseif ($documentView === 'order' && $links['source']['order_type'] !== 'invoice'): ?><form method="post" action="index.php?id=<?= $sourceId ?>"><input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="action" value="<?= $kind === 'sale' ? 'create_delivery' : 'create_receipt' ?>"><button class="contact-button" type="submit" data-quotation-delivery><?= $kind === 'sale' ? 'Entrega' : 'Recepción' ?></button></form><?php endif; ?>
    <?php if ($documentView === 'order'): ?>
        <?php if ($links['invoice']): ?><a class="contact-button" data-related-invoice href="../contabilidad/index.php?type=<?= $invoiceType ?>&amp;id=<?= (int) $links['invoice']['id'] ?>">Factura · 1</a>
        <?php elseif ($links['can_invoice']): ?><form method="post" action="index.php?id=<?= $sourceId ?>"><input type="hidden" name="csrf" value="<?= salesEscape($page['csrf']) ?>"><input type="hidden" name="action" value="create_invoice"><button class="contact-button contact-button-primary" type="submit" data-quotation-invoice>Factura</button></form><?php endif; ?>
    <?php endif; ?>
</div>

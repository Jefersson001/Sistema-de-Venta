<?php $page = $modulePageData; ?>
<?php foreach ($page['errors'] as $error): ?><p class="notice notice-error" role="alert"><?= inventoryEscape($error) ?></p><?php endforeach; ?>
<?php if ($page['available']): ?><?php $record = $page['record']; ?>
<section><div class="contact-toolbar"><div><a class="overview-link" href="../inventario/informes.php?view=purchased">← Historial de compras</a><h1><?= inventoryEscape($record['name']) ?></h1></div><span class="module-status"><?= $record['status'] === 'purchase' ? 'Orden de compra' : 'Borrador' ?></span></div>
    <div class="contact-sheet"><div class="contact-form-grid"><label>Proveedor<input readonly value="<?= inventoryEscape($record['supplier_name']) ?>"></label><label>Fecha de la orden<input readonly value="<?= inventoryEscape($record['order_date']) ?>"></label><label>Fecha de confirmación<input readonly value="<?= inventoryEscape($record['approved_date'] ?? '') ?>"></label></div>
        <h2 class="notebook-page-title">Productos</h2><div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Importe sin impuestos</th></tr></thead><tbody>
            <?php foreach ($page['lines'] as $line): ?><tr><td><a class="contact-name" href="../inventario/index.php?id=<?= (int) $line['product_id'] ?>"><?= inventoryEscape($line['product_name']) ?></a></td><td><?= inventoryEscape($line['quantity']) ?></td><td><?= inventoryEscape($line['unit_price']) ?></td><td><?= inventoryEscape(bcmul($line['quantity'], $line['unit_price'], 2)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
</section>
<?php endif; ?>

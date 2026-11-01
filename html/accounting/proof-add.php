<?php if ($editing && permissionHas($proofResource, 'edit')): ?>
<details class="workspace-details payment-proof-add"><summary>Adjuntar otro comprobante</summary>
<form method="post" enctype="multipart/form-data" action="<?= accountingEscape($proofBase) ?>">
    <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="action" value="add_payment_proof">
    <?php require __DIR__.'/proof-input.php'; ?>
    <button type="submit" class="contact-button">Guardar comprobante</button>
</form></details>
<?php endif; ?>

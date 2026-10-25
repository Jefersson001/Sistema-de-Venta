<?php $record = $page['record']; $editing = isset($record['id']); ?>
<form class="contact-sheet" method="post" action="configuracion.php?section=taxes<?= $editing ? '&amp;id=' . (int) $record['id'] : '&amp;new=1' ?>">
    <input type="hidden" name="csrf" value="<?= taxEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_tax">
    <h2><?= $editing ? 'Editar impuesto' : 'Nuevo impuesto' ?></h2>
    <label>Nombre *<input name="name" required maxlength="120" value="<?= taxEscape($record['name'] ?? '') ?>" placeholder="Nombre del impuesto"></label>
    <label>Porcentaje *<input name="rate" inputmode="decimal" required maxlength="6" value="<?= taxEscape($record['rate'] ?? '0.00') ?>"></label>
    <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar impuesto</button><a class="contact-button" href="configuracion.php?section=taxes">Volver al listado</a></div>
</form>

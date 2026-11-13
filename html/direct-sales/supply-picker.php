<?php if(!$page['locations']): ?>
<p class="notice">No hay ubicaciones internas en almacenes sin vendedor asignado. Revisa la configuración de almacenes y los contactos vendedores.</p>
<?php else: ?>
<form method="get" class="direct-supply-picker">
<?php if($detail): ?><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><?php endif; ?>
<label>Almacén / ubicación de origen<select name="source_location_id" required><?php foreach($page['locations'] as $location): ?><option value="<?= (int)$location['id'] ?>" <?= (int)($page['source']['id']??0)===(int)$location['id']?'selected':'' ?>><?= $e($location['warehouse_name'].' / '.$location['name']) ?></option><?php endforeach; ?></select></label>
<button type="submit" class="contact-button">Consultar existencias</button>
</form>
<?php if($page['source']): ?><p class="direct-location">Origen consultado: <strong><?= $e($page['source']['warehouse_name'].' / '.$page['source']['name']) ?></strong></p><?php endif; ?>
<?php endif; ?>

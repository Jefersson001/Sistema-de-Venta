<?php
$canManage=$account['status']==='open'&&permissionRecord('restaurante.accounts',(int)$account['id'],'edit');
$canClose=$account['status']==='open'&&permissionRecord('restaurante.accounts',(int)$account['id'],'cancel');
$tableOptions=['details'=>['Detalle de la atención','Mesero, comensales y notas del servicio.']];
if($canManage){
    $tableOptions['move_account']=['Trasladar mesa','Mover toda la cuenta a una mesa libre.'];
    $tableOptions['attach_table']=['Unir mesa','Añadir una mesa libre a esta misma cuenta.'];
    if(count($current)>1)$tableOptions['detach_table']=['Separar mesa','Liberar una mesa unida conservando la cuenta.'];
}
$tableOptions['history']=['Historial','Consultar cambios de mesa y actividad.'];
if($canClose)$tableOptions['close_empty_account']=['Cerrar sin consumo','Descartar el pedido y finalizar la atención.'];
$postedAction=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['action']??''):'';
$retryOption=$page['error']&&isset($tableOptions[$postedAction])?$postedAction:'';
?>
<div class="restaurant-table-tools" data-table-tools data-table-retry="<?= $h($retryOption) ?>">
<button type="button" class="button" data-table-menu-toggle aria-expanded="false" aria-controls="restaurant-table-menu">Opciones de mesa <span aria-hidden="true">⌄</span></button>
<div id="restaurant-table-menu" class="restaurant-table-menu" hidden>
<?php foreach($tableOptions as $option=>[$label,$description]): ?>
<button type="button" data-table-option="<?= $h($option) ?>" <?= $option==='close_empty_account'?'class="restaurant-table-danger"':'' ?>><strong><?= $h($label) ?></strong><small><?= $h($description) ?></small></button>
<?php endforeach; ?></div>
</div>
<dialog class="restaurant-pos-dialog restaurant-table-dialog" data-table-dialog aria-labelledby="restaurant-table-dialog-title">
<div class="restaurant-pos-catalog-head"><h2 id="restaurant-table-dialog-title" data-table-dialog-title>Opciones de mesa</h2><button type="button" class="button" data-table-dialog-close aria-label="Cerrar opciones de mesa">×</button></div>
<?php foreach($tableOptions as $option=>[$label,$description]): ?>
<section data-table-panel="<?= $h($option) ?>" data-table-title="<?= $h($label) ?>" hidden>
<?php if($option==='details'): ?>
<dl class="restaurant-service-details"><div><dt>Cuenta</dt><dd><?= $h($account['name']) ?> · <?= $h($stateNames[$account['status']]) ?></dd></div><div><dt>Mesas actuales</dt><dd><?= $h(implode(' + ',array_column($current,'table_name'))?:'Servicio finalizado') ?></dd></div><div><dt>Mesero</dt><dd><?= $h($page['waiter']) ?></dd></div><div><dt>Comensales</dt><dd><?= (int)$account['guests'] ?></dd></div><div><dt>Apertura</dt><dd><?= $h($account['opened_at']) ?></dd></div><?php if($account['closed_at']): ?><div><dt>Cierre</dt><dd><?= $h($account['closed_at']) ?></dd></div><?php endif; ?><?php if($account['notes']): ?><div><dt>Notas</dt><dd><?= nl2br($h($account['notes'])) ?></dd></div><?php endif; ?></dl>
<?php elseif($option==='history'): ?>
<h3>Historial de mesas</h3><div class="restaurant-list"><?php foreach($page['assignments'] as $s): ?><div class="restaurant-list-row"><span><strong><?= $h($s['area_name'].' · '.$s['table_name']) ?></strong><small><?= $h($s['assigned_at']) ?> → <?= $h($s['released_at']??'En atención') ?></small><?php if($s['release_reason']): ?><small><?= $h($s['release_reason']) ?></small><?php endif; ?></span></div><?php endforeach; ?></div>
<h3>Actividad</h3><ol class="restaurant-timeline"><?php $eventNames=['account_opened'=>'Cuenta abierta','move_account'=>'Cuenta trasladada','attach_table'=>'Mesa unida','detach_table'=>'Mesa separada','close_empty_account'=>'Cierre sin consumo','pos_add'=>'Producto agregado','pos_quantity'=>'Cantidad actualizada','pos_remove'=>'Producto retirado','pos_note'=>'Observación actualizada','pos_checkout'=>'Cuenta facturada y cobrada'];foreach($page['events'] as $event): ?><li><strong><?= $h($eventNames[$event['action']]??$event['action']) ?></strong><span><?= $h($event['created_at'].' · '.($event['actor_name']??'Usuario histórico')) ?></span><?php if($event['reason']): ?><p><?= $h($event['reason']) ?></p><?php endif; ?></li><?php endforeach; ?></ol>
<?php else: $isRetry=$retryOption===$option;$tableChoices=$option==='detach_table'?$current:$page['freeTables']; ?>
<form method="post" class="restaurant-form" data-table-wizard data-table-operation="<?= $h($option) ?>">
<?php $formToken();$requestKey(); ?><input type="hidden" name="action" value="<?= $h($option) ?>"><input type="hidden" name="version" value="<?= (int)$account['version'] ?>">
<p class="restaurant-wizard-progress" data-table-progress>Paso 1 de 2 · Datos del cambio</p>
<?php if($isRetry): ?><p class="restaurant-error" role="alert"><?= $h($page['error']) ?></p><?php endif; ?>
<div data-table-step="edit" class="restaurant-wizard-step">
<p><?= $h($description) ?></p>
<?php if($option!=='close_empty_account'): ?>
<?php if(!$tableChoices): ?><p class="restaurant-notice">No hay mesas libres disponibles. Libera una mesa o vuelve al mapa para revisar su estado.</p><?php endif; ?>
<label><?= $option==='detach_table'?'Mesa que deseas separar':'Mesa de destino' ?><select name="table_id" required><option value="">Selecciona una mesa</option><?php foreach($tableChoices as $t): $choiceId=(int)($t['table_id']??$t['id']); ?><option value="<?= $choiceId ?>" <?= $isRetry&&(int)($_POST['table_id']??0)===$choiceId?'selected':'' ?>><?= $h($t['area_name'].' · '.($t['table_name']??$t['name'])) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<label>Motivo<input name="reason" required maxlength="255" placeholder="Ej. Solicitud del cliente" value="<?= $h($isRetry?($_POST['reason']??''):'') ?>"></label>
<div class="restaurant-actions"><button type="button" class="button" data-table-dialog-close>Cancelar</button><button type="button" class="button button-primary" data-table-next <?= $option!=='close_empty_account'&&!$tableChoices?'disabled':'' ?>>Continuar</button></div>
</div>
<div data-table-step="confirm" class="restaurant-wizard-step" hidden><p>Revisa el cambio antes de confirmarlo.</p><div class="restaurant-wizard-review"><strong data-table-review-target></strong><p data-table-review-reason></p></div>
<p><?= $h(match($option){'move_account'=>'La cuenta y todos sus productos pasarán a la mesa seleccionada. Las mesas anteriores quedarán pendientes de limpieza.','attach_table'=>'Las mesas compartirán la cuenta actual y sus productos. Solo puedes unir una mesa libre.','detach_table'=>'La mesa seleccionada quedará pendiente de limpieza. La cuenta y sus productos permanecerán en las otras mesas.',default=>'Se quitarán todos los productos cargados, sin generar factura, cobro ni salida de inventario. El cambio quedará en el historial y las mesas quedarán pendientes de limpieza.'}) ?></p>
<div class="restaurant-actions"><button type="button" class="button" data-table-back>Volver</button><button type="submit" class="button button-primary"><?= $h(match($option){'move_account'=>'Confirmar traslado','attach_table'=>'Confirmar unión','detach_table'=>'Confirmar separación',default=>'Cerrar sin consumo'}) ?></button></div></div>
</form>
<?php endif; ?></section>
<?php endforeach; ?>
</dialog>

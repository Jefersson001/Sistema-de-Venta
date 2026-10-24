<?php
$quick=!$table['occupied']&&$state==='free'&&permissionHas('restaurante.accounts','create');
$tileClass='restaurant-table restaurant-table--'.$state;
$tileStyle='--table-column:'.(int)$table['position_x'].';--table-row:'.(int)$table['position_y'];
?>
<?php if($quick): ?><form class="restaurant-tile-form" style="<?= $h($tileStyle) ?>" action="cuenta.php?new=1" method="post"><?php $formToken();$requestKey(); ?><input type="hidden" name="action" value="save_account"><input type="hidden" name="table_id" value="<?= (int)$table['id'] ?>"><input type="hidden" name="table_version" value="<?= (int)$table['version'] ?>"><input type="hidden" name="waiter_id" value="<?= (int)permissionUser()['id'] ?>"><input type="hidden" name="guests" value="1"><button type="submit" class="<?= $h($tileClass) ?>">
<?php else: ?><a class="<?= $h($tileClass) ?>" style="<?= $h($tileStyle) ?>" href="<?= $table['account_id']?'cuenta.php?id='.(int)$table['account_id']:'mesa.php?id='.(int)$table['id'] ?>"><?php endif; ?>
<span class="restaurant-table-top"><span class="restaurant-state"><?= $h($stateNames[$state]) ?></span><span><?= (int)$table['capacity'] ?> pers.</span></span>
<span class="restaurant-table-symbol" aria-hidden="true"><svg viewBox="0 0 64 48" fill="none"><rect x="13" y="10" width="38" height="28" rx="9"/><path d="M24 5h16M24 43h16M7 19v10M57 19v10"/></svg></span>
<strong><?= $h($table['name']) ?></strong>
<?php if(isset($table['consumption_total'])): ?><strong><?= $h($table['consumption_total']) ?></strong><?php endif; ?>
<span class="restaurant-table-detail"><?= $h($table['occupied']?($table['waiter_name']??'En atención'):($state==='free'?'Toca para agregar productos':($state==='cleaning'?'Pendiente de limpieza':'Mesa reservada'))) ?></span>
<?php if($table['opened_at']): ?><time data-restaurant-elapsed datetime="<?= $h(str_replace(' ','T',$table['opened_at']).'-04:00') ?>">En atención</time><?php endif; ?>
<?php if($quick): ?></button></form><?php else: ?></a><?php endif; ?>

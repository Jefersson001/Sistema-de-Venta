<?php $geoPrefix=$geoPrefix??''; ?>
<?php foreach(['country_id'=>['País','countries','data-country',null],'state_id'=>['Estado','states','data-state','country_id'],'municipality_id'=>['Municipio','municipalities','data-municipality','state_id'],'city_id'=>['Ciudad','cities','data-city','state_id']] as $field=>[$geoLabel,$catalog,$attribute,$parent]): ?>
<label><?= $geoLabel ?><select name="<?= contactEscape($geoPrefix!==''?$geoPrefix.'['.$field.']':$field) ?>" <?= $attribute ?>>
    <option value="">Sin <?= $field==='city_id'?'ciudad':mb_strtolower($geoLabel) ?></option>
    <?php foreach($page['catalogs'][$catalog] as $place): ?><option value="<?= (int)$place['id'] ?>" <?= $parent?'data-parent="'.(int)$place[$parent].'"':'' ?> <?= $field==='city_id'?'data-municipality="'.(int)($place['municipality_id']??0).'"':'' ?> <?= (int)($geoRecord[$field]??0)===(int)$place['id']?'selected':'' ?>><?= contactEscape($place['name']) ?></option><?php endforeach; ?>
</select></label>
<?php endforeach; ?>

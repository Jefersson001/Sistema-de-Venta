<?php
$addressField=$addressKind.'_address_id';$addressSnapshot=$addressKind.'_address';
if(!empty($operationAddress)){$addressField='partner_address_id';$addressSnapshot='partner_address';}
$partnerId=(int)($record['customer_id']??$record['supplier_id']??$record['contact_id']??0);$selectedAddress=(int)($record[$addressField]??0);
?>
<label><?= $addressKind==='invoice'?'Dirección de factura':'Dirección de entrega' ?>
<select name="<?= $addressField ?>" data-order-address="<?= $addressKind ?>">
    <option value=""><?= $editing&&!$selectedAddress?'Dirección guardada en el documento':'Dirección principal / predeterminada' ?></option>
    <?php foreach($page['catalogs']['addresses']??[] as $addressOption): ?><?php if((int)$addressOption['contact_id']!==$partnerId||(!(int)$addressOption['active']&&(int)$addressOption['id']!==$selectedAddress)||(!in_array($addressOption['usage_type'],['both',$addressKind],true)&&(int)$addressOption['id']!==$selectedAddress))continue; ?>
    <option value="<?= (int)$addressOption['id'] ?>" <?= $selectedAddress===(int)$addressOption['id']?'selected':'' ?>><?= salesEscape($addressOption['label']) ?><?= !$addressOption['active']?' (archivada)':'' ?></option>
    <?php endforeach; ?>
</select>
<input type="hidden" name="refresh_<?= $addressKind ?>_address" value="0" data-refresh-order-address="<?= $addressKind ?>">
<textarea rows="3" readonly data-<?= $addressKind==='invoice'?'invoice':'delivery' ?>-address><?= salesEscape($record[$addressSnapshot]??'') ?></textarea>
</label>

<?php
$addressRows=array_values(array_filter($page['addresses']??[],static fn($r)=>!$r['is_primary']));
$addressDefaults=['invoice'=>'primary','delivery'=>'primary'];
foreach($addressRows as $index=>$row)foreach(['invoice','delivery'] as $kind)if($row['default_'.$kind])$addressDefaults[$kind]='row_'.$index;
if(!empty($page['addressInput'])){$addressRows=$page['addressInput']['rows'];$addressDefaults=$page['addressInput']['defaults'];}
?>
<h2 class="notebook-page-title">Direcciones secundarias</h2>
<p class="section-subtitle">La dirección principal se edita en la subpestaña Principal. Agrega aquí sucursales o destinos alternativos y elige las direcciones predeterminadas para facturación y entrega. Guarda el contacto para conservar los cambios.</p>
<input type="hidden" name="addresses_present" value="1">
<input type="hidden" name="addresses_revision" value="<?= contactEscape(is_string($_POST['addresses_revision']??null)?$_POST['addresses_revision']:contactAddressesRevision($page['addresses']??[])) ?>">
<div class="contact-address-row"><strong>Principal</strong><p><?= contactEscape(contactAddressText($record,$page['catalogs'])?:'Completa la dirección principal en Dirección y localización.') ?></p><div class="contact-address-options">
<?php foreach(['invoice'=>'Facturación predeterminada','delivery'=>'Entrega predeterminada'] as $kind=>$text): ?><label><input type="radio" name="default_<?= $kind ?>_address" value="primary" <?= $addressDefaults[$kind]==='primary'?'checked':'' ?>> <?= $text ?></label><?php endforeach; ?>
</div></div>
<div data-contact-address-lines><?php foreach($addressRows as $addressIndex=>$addressRow)require __DIR__.'/address-line.php'; ?></div>
<?php if(permissionHas('contactos.contacts',$editing?'edit':'create')): ?><button type="button" class="contact-button" data-add-contact-address>+ Agregar dirección</button><?php endif; ?>
<template data-contact-address-template><?php $addressIndex='__INDEX__';$addressRow=[];require __DIR__.'/address-line.php'; ?></template>

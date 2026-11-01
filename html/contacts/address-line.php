<?php $prefix='addresses['.$addressIndex.']'; ?>
<article class="contact-address-row" data-contact-address-row>
    <input type="hidden" name="<?= $prefix ?>[id]" value="<?= (int)($addressRow['id']??0)?:'' ?>">
    <div class="contact-form-grid">
        <label>Nombre de dirección *<input name="<?= $prefix ?>[label]" maxlength="80" required value="<?= contactEscape($addressRow['label']??'') ?>" placeholder="Ej. Sucursal centro"></label>
        <label>Uso<select name="<?= $prefix ?>[usage_type]" data-address-usage><?php foreach(['both'=>'Facturación y entrega','invoice'=>'Solo facturación','delivery'=>'Solo entrega'] as $value=>$text): ?><option value="<?= $value ?>" <?= ($addressRow['usage_type']??'both')===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select></label>
        <label class="contact-full">Dirección *<input name="<?= $prefix ?>[address]" maxlength="255" required value="<?= contactEscape($addressRow['address']??'') ?>"></label>
    </div>
    <div class="contact-form-grid" data-contact-location><?php $geoRecord=$addressRow;$geoPrefix=$prefix;require __DIR__.'/geography.php'; ?></div>
    <label>Código postal<input name="<?= $prefix ?>[postal_code]" maxlength="20" value="<?= contactEscape($addressRow['postal_code']??'') ?>"></label>
    <?php
    $geoRole='address';$geoTitle='Ubicación de esta dirección';$geoHint='Este punto pertenece al cliente, no al lugar donde el vendedor realizó una venta.';
    $geoLatName=$prefix.'[latitude]';$geoLonName=$prefix.'[longitude]';$geoLatitude=$addressRow['latitude']??null;$geoLongitude=$addressRow['longitude']??null;$geoSourceName=null;$geoReadonly=!permissionHas('contactos.contacts',$editing?'edit':'create');
    require dirname(__DIR__).'/partials/geolocation-fields.php';unset($geoHint,$geoReadonly);
    ?>
    <div class="contact-address-options">
        <label><input type="checkbox" name="<?= $prefix ?>[active]" value="1" data-address-active <?= ($addressRow['active']??1)?'checked':'' ?>> Activa</label>
        <label><input type="radio" name="default_invoice_address" value="row_<?= $addressIndex ?>" data-address-default="invoice" <?= $addressDefaults['invoice']==='row_'.$addressIndex?'checked':'' ?>> Facturación predeterminada</label>
        <label><input type="radio" name="default_delivery_address" value="row_<?= $addressIndex ?>" data-address-default="delivery" <?= $addressDefaults['delivery']==='row_'.$addressIndex?'checked':'' ?>> Entrega predeterminada</label>
        <?php if(empty($addressRow['id'])): ?><button type="button" class="contact-button" data-remove-contact-address>Quitar línea</button><?php else: ?><small>Desmarca Activa para archivar; los documentos anteriores conservan esta dirección.</small><?php endif; ?>
    </div>
</article>

<?php $geoReadonly=$geoReadonly??false;$mapUrl=geolocationMapUrl($geoLatitude??null,$geoLongitude??null); ?>
<section class="geolocation-panel" data-geolocation="<?= authEscape($geoRole??'address') ?>">
    <h3><?= authEscape($geoTitle??'Ubicación de la dirección') ?> <small>(opcional)</small></h3>
    <?php if(!empty($geoHint)): ?><p class="section-subtitle"><?= authEscape($geoHint) ?></p><?php endif; ?>
    <div class="geolocation-coordinates">
        <label>Latitud<input type="text" inputmode="decimal" name="<?= authEscape($geoLatName) ?>" value="<?= authEscape($geoLatitude??'') ?>" placeholder="Ej. 10.480600" data-geo-latitude <?= $geoReadonly?'readonly':'' ?>></label>
        <label>Longitud<input type="text" inputmode="decimal" name="<?= authEscape($geoLonName) ?>" value="<?= authEscape($geoLongitude??'') ?>" placeholder="Ej. -66.903600" data-geo-longitude <?= $geoReadonly?'readonly':'' ?>></label>
    </div>
    <?php if(!empty($geoSourceName)): ?><input type="hidden" name="<?= authEscape($geoSourceName) ?>" value="<?= authEscape($geoSourceValue??'manual') ?>" data-geo-source><?php endif; ?>
    <div class="geolocation-actions">
        <?php if(!$geoReadonly): ?><button type="button" class="contact-button" data-geo-capture>Capturar ubicación actual</button><button type="button" class="contact-button contact-button-link" data-geo-clear>Borrar ubicación</button><?php endif; ?>
        <a class="contact-button" href="<?= authEscape($mapUrl??'#') ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-geo-map <?= $mapUrl?'':'hidden' ?>>Ver en mapa</a>
    </div>
    <p class="section-subtitle" role="status" data-geo-status></p>
</section>

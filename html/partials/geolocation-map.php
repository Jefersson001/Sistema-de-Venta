<?php $previewValid=geolocationMapUrl($mapLatitude??null,$mapLongitude??null)!==null; ?>
<details class="geolocation-map-preview" data-geo-map-preview data-latitude="<?= authEscape($previewValid?str_replace(',','.',(string)$mapLatitude):'') ?>" data-longitude="<?= authEscape($previewValid?str_replace(',','.',(string)$mapLongitude):'') ?>" data-map-title="<?= authEscape('Mapa: '.($mapTitle??'Ubicación')) ?>" <?= $previewValid?'':'hidden' ?> open>
    <summary>Mapa de la ubicación · ocultar</summary>
    <div class="geolocation-map-canvas" data-geo-map-canvas><p class="section-subtitle">Cargando mapa…</p></div>
</details>

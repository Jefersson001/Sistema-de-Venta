<details class="workspace-details sales-geolocation" open>
    <summary>Ubicaciones del cliente y de la venta</summary>
    <p class="section-subtitle">Son puntos independientes. La ubicación del cliente corresponde al destino; la ubicación de la venta indica dónde se encontraba quien la registró. Ninguna es obligatoria.</p>
    <div class="geolocation-grid">
        <?php
        $geoReadonly=$locked||!permissionHas('ventas.orders',$editing?'edit':'create');
        $geoRole='customer';$geoTitle='Ubicación del cliente';$geoHint='Se propone desde la dirección de entrega. Puedes ajustar el punto solo para esta orden.';
        $geoLatName='customer_latitude';$geoLonName='customer_longitude';$geoLatitude=$record[$geoLatName]??null;$geoLongitude=$record[$geoLonName]??null;$geoSourceName='customer_location_source';$geoSourceValue=$record[$geoSourceName]??'address';
        require dirname(__DIR__).'/partials/geolocation-fields.php';
        $geoRole='sale';$geoTitle='Ubicación donde se realizó la venta';$geoHint='El vendedor puede capturar su posición actual al registrar la venta. Este punto no cambia la dirección ni la ubicación del cliente.';
        $geoLatName='sale_latitude';$geoLonName='sale_longitude';$geoLatitude=$record[$geoLatName]??null;$geoLongitude=$record[$geoLonName]??null;$geoSourceName='sale_location_source';$geoSourceValue=$record[$geoSourceName]??'manual';
        require dirname(__DIR__).'/partials/geolocation-fields.php';
        unset($geoSourceName,$geoSourceValue,$geoHint,$geoReadonly);
        ?>
    </div>
    <?php if(!empty($record['sale_location_recorded_at'])): ?><p class="section-subtitle">Ubicación de la venta guardada el <?= salesEscape($record['sale_location_recorded_at']) ?>. El usuario que la registró queda relacionado con la orden.</p><?php endif; ?>
</details>

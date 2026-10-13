<?php $page = $modulePageData; $record = $page['record']; ?>
<?php foreach ($page['errors'] as $error): ?><p class="notice notice-error" role="alert"><?= accountingEscape($error) ?></p><?php endforeach; ?>
<?php if ($page['available']): ?>
<section aria-labelledby="currencies-title">
    <div class="contact-toolbar">
        <div><a class="overview-link" href="configuracion.php<?= $page['view']==='list'?'':'?section=currencies' ?>">← <?= $page['view']==='list'?'Configuración':'Monedas' ?></a><h1 id="currencies-title"><?= accountingEscape($page['view']==='settings'?'Monedas del negocio':($page['view']==='form'?($record['code']?:'Nueva moneda'):'Monedas')) ?></h1></div>
        <?php if ($page['view']==='list'): ?><div class="action-buttons"><a class="contact-button" href="configuracion.php?section=currency_settings">Principal y alterna</a><a class="contact-button contact-button-primary" href="configuracion.php?section=currencies&amp;new=1">Nueva moneda</a></div><?php endif; ?>
    </div>
    <?php if ($page['view']==='list'): ?>
        <p class="section-subtitle">Principal: <?= accountingEscape($page['settings']['primary_code']) ?> · Alterna: <?= accountingEscape($page['settings']['alternate_code']) ?></p>
        <form class="contact-filters" method="get" action="configuracion.php"><input type="hidden" name="section" value="currencies"><label>Buscar<input name="q" type="search" maxlength="120" value="<?= accountingEscape($page['search']) ?>" placeholder="Código, nombre o símbolo"></label><label>Estado<select name="active"><option value="all" <?= $page['filter']==='all'?'selected':'' ?>>Todas</option><option value="active" <?= $page['filter']==='active'?'selected':'' ?>>Activas</option><option value="inactive" <?= $page['filter']==='inactive'?'selected':'' ?>>Inactivas</option></select></label><button class="contact-button">Buscar</button></form>
        <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Moneda</th><th scope="col">Nombre</th><th scope="col">Símbolo</th><th scope="col">Activa</th><th scope="col">Tasa vigente por 1 <?= accountingEscape($page['settings']['primary_code']) ?></th></tr></thead><tbody>
            <?php foreach ($page['rows'] as $currency): ?><tr><td><a class="contact-name" href="configuracion.php?section=currencies&amp;id=<?= (int)$currency['id'] ?>"><?= accountingEscape($currency['code']) ?></a></td><td><?= accountingEscape($currency['name']) ?></td><td><?= accountingEscape($currency['symbol']) ?></td><td><?= $currency['active']?'Sí':'No' ?></td><td><?= accountingEscape($currency['current_rate']??'Sin tasa') ?></td></tr><?php endforeach; ?>
            <?php if (!$page['rows']): ?><tr><td colspan="5" class="contact-empty">No hay monedas que coincidan con la búsqueda.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?php elseif ($page['view']==='settings'): ?>
        <form class="contact-sheet" method="post" action="configuracion.php?section=currency_settings">
            <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_currency_settings"><input type="hidden" name="version" value="<?= (int)$page['settings']['version'] ?>">
            <div class="contact-form-grid">
                <?php foreach (['primary'=>'Moneda principal','alternate'=>'Moneda alterna'] as $key=>$label): ?><label><?= $label ?> *<select name="<?= $key ?>_id" required><?php foreach ($page['currencies'] as $currency): ?><?php if ($currency['active']): ?><option value="<?= (int)$currency['id'] ?>" <?= (isset($_POST[$key.'_id'])?(string)currencyCatalogId($_POST[$key.'_id'])===(string)$currency['id']:$page['settings'][$key.'_code']===$currency['code'])?'selected':'' ?>><?= accountingEscape($currency['code'].' · '.$currency['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label><?php endforeach; ?>
            </div>
            <p class="section-subtitle">La tasa vigente se toma de la pestaña Tasas de las monedas. Los documentos guardados conservan su moneda y tasa.</p>
            <div class="contact-form-actions"><button class="contact-button contact-button-primary">Guardar</button><a class="contact-button" href="configuracion.php?section=currencies">Volver al listado</a></div>
        </form>
    <?php else: ?>
        <form class="contact-sheet" method="post" action="configuracion.php?section=currencies<?= isset($record['id'])?'&amp;id='.(int)$record['id']:'&amp;new=1' ?>" data-currency-form>
            <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_currency"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>">
            <div class="contact-form-grid">
                <label>Moneda *<input name="code" required maxlength="3" value="<?= accountingEscape($record['code']) ?>" <?= $page['codeLocked']?'readonly':'' ?> placeholder="USD"><?php if ($page['codeLocked']): ?><small>El código se conserva porque esta moneda ya está relacionada.</small><?php endif; ?></label>
                <label>Nombre *<input name="name" required maxlength="120" value="<?= accountingEscape($record['name']) ?>"></label>
                <div class="product-options currency-options"><label><input type="checkbox" name="active" value="1" <?= $record['active']?'checked':'' ?>>Activa</label></div>
                <label>Símbolo *<input name="symbol" required maxlength="12" value="<?= accountingEscape($record['symbol']) ?>" data-currency-symbol></label>
                <label>Unidad de moneda<input name="unit_label" maxlength="80" value="<?= accountingEscape($record['unit_label']) ?>" placeholder="Dólar"></label>
                <label>Subunidad de moneda<input name="subunit_label" maxlength="80" value="<?= accountingEscape($record['subunit_label']) ?>" placeholder="Centavo"></label>
                <label>Posición del símbolo<select name="symbol_position" data-currency-position><option value="before" <?= $record['symbol_position']==='before'?'selected':'' ?>>Antes del importe</option><option value="after" <?= $record['symbol_position']==='after'?'selected':'' ?>>Después del importe</option></select></label>
                <div><span class="section-subtitle">Vista previa</span><p data-currency-example><?= accountingEscape($record['symbol_position']==='before'?$record['symbol'].' 100.00':'100.00 '.$record['symbol']) ?></p></div>
            </div>
            <div class="contact-notebook" data-notebook><nav class="notebook-tabs" data-notebook-tabs aria-label="Información de la moneda"><a id="currency-tab-rates" href="#currency-rates">Tasas</a></nav>
                <section class="notebook-page" id="currency-rates" data-notebook-page aria-labelledby="currency-tab-rates">
                    <p class="section-subtitle">Cada tasa indica cuántas unidades de esta moneda equivalen a 1 unidad de la moneda base. La tasa vigente es la última registrada hasta hoy.</p>
                    <div class="contact-table-wrap"><table class="contact-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Moneda base</th><th scope="col">Tasa</th><th scope="col">Tasa inversa</th><th scope="col"><span class="visually-hidden">Acción</span></th></tr></thead><tbody data-currency-rates>
                        <?php $rateRows = $record['rates']; if (!$rateRows) $rateRows=[[]]; foreach ($rateRows as $rateKey=>$rateRow): ?><?php require __DIR__.'/currency-rate-line.php'; ?><?php endforeach; ?>
                    </tbody></table></div>
                    <button class="contact-button contact-button-link" type="button" data-add-currency-rate hidden>+ Agregar tasa</button>
                    <template data-currency-rate-template><?php $rateKey='__INDEX__'; $rateRow=[]; require __DIR__.'/currency-rate-line.php'; ?></template>
                    <?php if ($page['ratePages']>1): ?><nav class="contact-pagination" aria-label="Páginas de tasas"><span>Página <?= $page['ratePage'] ?> de <?= $page['ratePages'] ?></span><?php foreach (['Anterior'=>$page['ratePage']-1,'Siguiente'=>$page['ratePage']+1] as $label=>$number): ?><?php if ($number>=1 && $number<=$page['ratePages']): ?><a class="contact-button" href="configuracion.php?section=currencies&amp;id=<?= (int)$record['id'] ?>&amp;rate_page=<?= $number ?>#currency-rates"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
                </section>
            </div>
            <div class="contact-form-actions"><button class="contact-button contact-button-primary">Guardar moneda</button><a class="contact-button" href="configuracion.php?section=currencies">Volver al listado</a></div>
        </form>
        <script src="../../js/currency-catalog.js" defer></script>
    <?php endif; ?>
</section>
<?php endif; ?>

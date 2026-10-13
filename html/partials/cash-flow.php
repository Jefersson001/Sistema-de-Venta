<?php
$cashFlowEscape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="business-overview cash-flow" aria-labelledby="cash-flow-title" data-cash-flow <?= $cashFlowLive ? 'data-cash-flow-endpoint="ingresos-egresos.php"' : '' ?>>
    <div class="section-heading">
        <div><h2 id="cash-flow-title"><?= $cashFlowLive ? 'Ingresos y egresos' : 'Resumen del período' ?></h2><p class="section-subtitle">Pagos confirmados · <span data-cash-flow-range><?= $cashFlow ? $cashFlowEscape($cashFlowRange['label']) : 'Selecciona un período válido' ?></span></p></div>
        <?php if ($cashFlowLive): ?><a class="overview-link" data-cash-flow-history href="../contabilidad/ingresos-egresos.php?<?= $cashFlowEscape(cashFlowQuery($cashFlowRange)) ?>">Ver historial →</a><?php endif; ?>
    </div>
    <form class="contact-filters cash-flow-filters" method="get" data-cash-flow-filter>
        <?php if (!$cashFlowLive && !empty($modulePageData['account']['id'])): ?><input type="hidden" name="account_id" value="<?= (int)$modulePageData['account']['id'] ?>"><?php endif; ?>
        <label>Período<select name="period" data-cash-flow-period><?php foreach (cashFlowPeriods() as $value=>$label): ?><option value="<?= $value ?>" <?= $cashFlowRange['period']===$value ? 'selected' : '' ?>><?= $cashFlowEscape($label) ?></option><?php endforeach; ?></select></label>
        <div class="cash-flow-date-range" data-cash-flow-dates <?= $cashFlowRange['period'] !== 'custom' ? 'hidden' : '' ?>>
            <label>Desde<input type="date" name="date_from" min="1000-01-01" max="9999-12-31" value="<?= $cashFlowEscape($cashFlowRange['date_from']) ?>"></label>
            <label>Hasta<input type="date" name="date_to" min="1000-01-01" max="9999-12-31" value="<?= $cashFlowEscape($cashFlowRange['date_to']) ?>"></label>
        </div>
    </form>
    <p class="cash-flow-error" role="alert" data-cash-flow-error <?= !$cashFlowErrors ? 'hidden' : '' ?>><?= $cashFlowEscape(implode(' ', $cashFlowErrors)) ?></p>
    <div class="cash-flow-grid" data-cash-flow-cards>
        <?php foreach (['income'=>'Ingresos', 'expense'=>'Egresos', 'net'=>'Diferencia'] as $key=>$label): ?>
        <article class="overview-card cash-flow-card cash-flow-<?= $key ?>"><span class="overview-label"><?= $label ?></span><div class="cash-flow-amounts" data-cash-flow-amount="<?= $key ?>">
            <?php if ($cashFlow): ?><?php foreach ($cashFlow['currencies'] as $currency): ?><p class="cash-flow-money"><strong><?= $cashFlowEscape($currency[$key]) ?></strong><span><?= $cashFlowEscape($currency['code']) ?></span></p><?php endforeach; ?><?php else: ?><p class="cash-flow-money">—</p><?php endif; ?>
        </div><p><?= match ($key) { 'income'=>'Dinero recibido.', 'expense'=>'Dinero enviado.', default=>'Ingresos menos egresos del período.' } ?></p></article>
        <?php endforeach; ?>
    </div>
    <?php if ($cashFlowLive): ?><p class="section-subtitle cash-flow-updated" data-cash-flow-updated role="status"><?= $cashFlow ? 'Actualizado '.$cashFlowEscape($cashFlow['updated_time']).' · Hora de Venezuela' : 'Resumen no disponible.' ?></p><noscript><p class="section-subtitle">Recarga la página para actualizar el resumen.</p></noscript><?php endif; ?>
</section>

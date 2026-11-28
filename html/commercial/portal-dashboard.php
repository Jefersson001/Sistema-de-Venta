
<h1 class="visually-hidden">Mis solicitudes de demostración</h1>

<section id="resumen" class="workspace-section" aria-labelledby="summary-title"><header class="workspace-section-heading"><div><h2 id="summary-title">Resumen</h2></div></header>
<div class="tracking-metrics" aria-label="Resumen de mis solicitudes">
<a href="<?= $base ?>/portal-demo.php#mis-solicitudes" class="tracking-metric"><span class="tracking-metric-icon"><?= kontaIcon('closing') ?></span><span>Total de solicitudes<strong><?= $total ?></strong></span></a>
<a href="#mis-solicitudes" class="tracking-metric"><span class="tracking-metric-icon metric-pending"><?= kontaIcon('contactos') ?></span><span>Pendientes de coordinar<strong><?= $counts['new']+$counts['contacted'] ?></strong></span></a>
<a href="?status=approved#mis-solicitudes" class="tracking-metric"><span class="tracking-metric-icon metric-scheduled"><?= kontaIcon('check') ?></span><span>Demos aprobadas<strong><?= $counts['approved'] ?></strong></span></a>
<a href="?status=rejected#mis-solicitudes" class="tracking-metric"><span class="tracking-metric-icon metric-rejected"><?= kontaIcon('close') ?></span><span>Solicitudes rechazadas<strong><?= $counts['rejected'] ?></strong></span></a>
</div></section>

<section id="mis-solicitudes" class="portal-history workspace-section" aria-labelledby="requests-title"><header class="workspace-section-heading"><div><h2 id="requests-title">Mis solicitudes</h2></div><form method="get" class="tracking-filter"><label for="tracking-status">Filtrar por estado</label><select id="tracking-status" name="status" data-status-filter><option value="">Todos los estados</option><?php foreach($statuses as $key=>$label): ?><option value="<?= $key ?>" <?= $status===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><noscript><button class="site-button button-small" type="submit">Aplicar</button></noscript></form></header>
<?php if(!$rows): ?><div class="portal-empty"><?= kontaIcon('closing') ?><h3><?= $status!==''?'No hay solicitudes en este estado':'Todavía no has solicitado una demo' ?></h3><p><?= $status!==''?'Selecciona otro estado para consultar tus solicitudes.':'Cuando envíes tu solicitud, aquí encontrarás su estado y las respuestas del equipo.' ?></p><?php if($status!==''): ?><a class="site-text-link" href="<?= $base ?>/portal-demo.php#mis-solicitudes">Ver todas mis solicitudes</a><?php elseif($canRequest): ?><a class="site-text-link" href="<?= $base ?>/portal-demo.php?section=solicitar">Preparar mi solicitud</a><?php endif; ?></div><?php endif; ?>
<?php require __DIR__.'/portal-request-tree.php'; ?>
<?php if($pages>1): ?><nav class="admin-pagination" aria-label="Mis solicitudes"><span>Página <?= $current ?> de <?= $pages ?></span><?php foreach(['Anterior'=>$current-1,'Siguiente'=>$current+1] as $label=>$number): if($number<1||$number>$pages)continue; ?><a class="site-text-link" href="?<?= $e(http_build_query(['page'=>$number,'status'=>$status])) ?>#mis-solicitudes"><?= $label ?></a><?php endforeach; ?></nav><?php endif; ?></section>


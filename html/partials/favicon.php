<?php
$faviconFile='Logo moderno de Vent-As con crecimiento y ventas.png';
$faviconVersion=(string)filemtime(dirname(__DIR__,2).'/img/'.$faviconFile);
?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars(authBasePath().'/img/'.rawurlencode($faviconFile).'?v='.$faviconVersion,ENT_QUOTES,'UTF-8') ?>">

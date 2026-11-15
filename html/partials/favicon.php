<?php
$faviconFile='konta-mark.svg';
$faviconVersion=(string)filemtime(dirname(__DIR__,2).'/img/'.$faviconFile);
?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(authBasePath().'/img/'.rawurlencode($faviconFile).'?v='.$faviconVersion,ENT_QUOTES,'UTF-8') ?>">

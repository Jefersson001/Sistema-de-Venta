<link rel="stylesheet" href="<?= htmlspecialchars(authBasePath(),ENT_QUOTES,'UTF-8') ?>/css/vent-as-theme.css?v=<?= filemtime(dirname(__DIR__,2).'/css/vent-as-theme.css') ?>">

<link rel="stylesheet" href="<?= authBasePath() ?>/css/inventory-tracking.css?v=<?= filemtime(dirname(__DIR__,2).'/css/inventory-tracking.css') ?>">
<script src="<?= htmlspecialchars(authBasePath(),ENT_QUOTES,'UTF-8') ?>/js/input-validation.js?v=<?= filemtime(dirname(__DIR__,2).'/js/input-validation.js') ?>" defer></script>
<link rel="stylesheet" href="<?= htmlspecialchars(authBasePath(),ENT_QUOTES,'UTF-8') ?>/css/geolocation-maps.css?v=<?= filemtime(dirname(__DIR__,2).'/css/geolocation-maps.css') ?>">
<script src="<?= htmlspecialchars(authBasePath(),ENT_QUOTES,'UTF-8') ?>/js/geolocation-maps.js?v=<?= filemtime(dirname(__DIR__,2).'/js/geolocation-maps.js') ?>" defer></script>
<link rel="stylesheet" href="<?= authEscape(authBasePath()) ?>/css/konta.css?v=<?= filemtime(dirname(__DIR__,2).'/css/konta.css') ?>">
<link rel="stylesheet" href="<?= authEscape(authBasePath()) ?>/css/konta-workspace.css?v=<?= filemtime(dirname(__DIR__,2).'/css/konta-workspace.css') ?>">
<script src="<?= authEscape(authBasePath()) ?>/js/konta-theme.js?v=<?= filemtime(dirname(__DIR__,2).'/js/konta-theme.js') ?>"></script>
<?php
require_once dirname(__DIR__,2).'/php/ui-icons.php';
$workspaceIcons=[];
foreach(['plus','save','back','close','edit','filter','refresh','download','upload','check','closing','empty'] as $workspaceIconName)$workspaceIcons[$workspaceIconName]=kontaIcon($workspaceIconName,'konta-action-icon');
?>
<script type="application/json" data-konta-workspace-icons><?= json_encode($workspaceIcons,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="<?= authEscape(authBasePath()) ?>/js/konta-workspace.js?v=<?= filemtime(dirname(__DIR__,2).'/js/konta-workspace.js') ?>" defer></script>

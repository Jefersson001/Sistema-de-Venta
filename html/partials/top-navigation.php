<?php
require_once dirname(__DIR__,2).'/php/ui-icons.php';
require_once dirname(__DIR__,2).'/php/navigation-search.php';
if($isHomePage??false){require __DIR__.'/konta-header.php';return;}
$navigationSearchEntries=navigationSearchEntries();
?>
<details class="module-header-panel" data-module-header data-header-user="<?= (int)(authCurrentUser()['id'] ?? 0) ?>">
    <summary class="module-header-toggle"><span class="module-header-show">Mostrar cabecera</span><span class="module-header-hide">Ocultar cabecera</span><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg></summary>
    <?php require __DIR__.'/konta-header.php'; ?>
</details>
<script src="<?= authBasePath() ?>/js/navigation-search.js?v=<?= filemtime(dirname(__DIR__,2).'/js/navigation-search.js') ?>" defer></script>

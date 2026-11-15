<header class="konta-header"><div class="konta-topbar">
<?php require __DIR__.'/konta-brand.php'; ?>
<div class="konta-company"><?php require __DIR__.'/company-selector.php'; ?></div>
<?php require __DIR__.'/navigation-search.php'; ?>
<div class="konta-rate"><?php require __DIR__.'/exchange-rate.php'; ?></div>
<button type="button" class="konta-icon-button" data-konta-theme-toggle aria-label="Usar modo oscuro" aria-pressed="false"><?= kontaIcon('moon') ?></button>
<?php $navigationUser=authCurrentUser(); ?>
<details class="module-menu user-menu konta-user"><summary class="user-menu-summary" aria-label="Opciones de <?= authEscape($navigationUser['name']) ?>"><span class="avatar"><?= authEscape(mb_strtoupper(mb_substr($navigationUser['name'],0,1))) ?></span><span class="user-menu-copy"><span class="user-menu-name"><?= authEscape($navigationUser['name']) ?></span><small><?= !empty($navigationUser['administrator'])?'Administrador':'Mi cuenta' ?></small></span><svg class="user-menu-chevron" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><div class="module-menu-options user-menu-options"><a href="<?= authBasePath() ?>/perfil.php">Mi perfil</a><form method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button type="submit">Cerrar sesión</button></form></div></details>
</div></header><script src="<?= authBasePath() ?>/js/navigation.js?v=<?= filemtime(dirname(__DIR__,2).'/js/navigation.js') ?>" defer></script>

<header class="top-navigation top-navigation--refined">
    <div class="top-navigation-inner">
        <nav class="top-navigation-primary" aria-label="Navegación principal">
            <div class="brand">
                <span class="brand-mark brand-mark--vent-as"><span class="connection-indicator <?= $databaseStatus['connected'] ? 'connection-online' : 'connection-offline' ?>" role="img" title="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>" aria-label="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>"><span class="connection-dot" aria-hidden="true"></span></span></span>
                <span class="brand-name">Vent-<span>As</span><small>GESTIÓN EMPRESARIAL</small></span>
            </div>
        </nav>
        <div class="top-navigation-status">
            <?php require __DIR__.'/exchange-rate.php'; ?>
            <?php require __DIR__.'/company-selector.php'; ?>
            <?php $navigationUser=function_exists('authCurrentUser')?authCurrentUser():null; ?>
            <?php if($navigationUser): ?><details class="module-menu user-menu"><summary class="user-menu-summary" aria-label="Opciones de <?= authEscape($navigationUser['name']) ?>"><span class="avatar"><?= authEscape(mb_strtoupper(mb_substr($navigationUser['name'],0,1))) ?></span><span class="user-menu-copy"><small>Mi cuenta</small><span class="user-menu-name"><?= authEscape($navigationUser['name']) ?></span></span><svg class="user-menu-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary>
                <div class="module-menu-options user-menu-options"><a href="<?= authBasePath() ?>/perfil.php">Mi perfil</a><form method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button type="submit">Cerrar sesión</button></form></div>
            </details><?php else: ?><span class="avatar" aria-label="Usuario">U</span><?php endif; ?>
        </div>
    </div>
</header>
<script src="<?= authBasePath() ?>/js/navigation.js?v=<?= filemtime(dirname(__DIR__,2).'/js/navigation.js') ?>" defer></script>

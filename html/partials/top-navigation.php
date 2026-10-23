<header class="top-navigation">
    <div class="top-navigation-inner">
        <nav class="top-navigation-primary" aria-label="Navegación principal">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">N</span>
                <span class="brand-name">nexo<span>.</span><small>GESTIÓN EMPRESARIAL</small></span>
            </div>
            <?php if (! $isHomePage): ?><a class="top-navigation-home" href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 10 9-7 9 7M5 9v11h5v-6h4v6h5V9"/></svg>
                <span>Inicio</span>
            </a><?php endif; ?>
        </nav>
        <div class="top-navigation-status">
            <?php require __DIR__.'/company-selector.php'; ?>
            <span class="connection-pill <?= $databaseStatus['connected'] ? 'connection-online' : 'connection-offline' ?>" title="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>" aria-label="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>">
                <span class="connection-dot" aria-hidden="true"></span>
                <?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>
            </span>
            <?php $navigationUser=function_exists('authCurrentUser')?authCurrentUser():null; ?>
            <?php if($navigationUser): ?><details class="module-menu user-menu"><summary class="user-menu-summary" aria-label="Opciones de <?= authEscape($navigationUser['name']) ?>"><span class="avatar"><?= authEscape(mb_strtoupper(mb_substr($navigationUser['name'],0,1))) ?></span><span class="user-menu-name"><?= authEscape($navigationUser['name']) ?></span><span aria-hidden="true">▾</span></summary>
                <div class="module-menu-options user-menu-options"><a href="<?= authBasePath() ?>/perfil.php">Mi perfil</a><a href="<?= authBasePath() ?>/cambiar-contrasena.php">Cambiar contraseña</a><form method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button type="submit">Cerrar sesión</button></form></div>
            </details><?php else: ?><span class="avatar" aria-label="Usuario">U</span><?php endif; ?>
        </div>
    </div>
</header>

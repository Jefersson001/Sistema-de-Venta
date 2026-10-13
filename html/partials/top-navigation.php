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
            <span class="connection-pill <?= $databaseStatus['connected'] ? 'connection-online' : 'connection-offline' ?>" title="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>" aria-label="<?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>">
                <span class="connection-dot" aria-hidden="true"></span>
                <?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>
            </span>
            <span class="avatar" aria-label="Usuario">U</span>
        </div>
    </div>
</header>

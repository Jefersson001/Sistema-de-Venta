<nav class="bottom-navigation" aria-label="Navegación principal">
    <div class="bottom-navigation-inner">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">N</span>
            <span class="brand-name">nexo<span>.</span><small>GESTIÓN EMPRESARIAL</small></span>
        </div>
        <a class="bottom-navigation-home" href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" <?= $isHomePage ? 'aria-current="page"' : '' ?>>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 10 9-7 9 7M5 9v11h5v-6h4v6h5V9"/></svg>
            <span>Inicio</span>
        </a>
    </div>
</nav>

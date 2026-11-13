<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#F5F6FC">
    <title>Inicio | Vent-As</title><?php require __DIR__.'/partials/favicon.php'; ?>
    <link rel="stylesheet" href="css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <script src="js/app.js?v=<?= filemtime(dirname(__DIR__) . '/js/app.js') ?>" defer></script>
    <script src="js/navigation-search.js?v=<?= filemtime(dirname(__DIR__).'/js/navigation-search.js') ?>" defer></script>
<?php require __DIR__.'/partials/theme.php'; ?></head>
<body class="vent-as-home">
    <div class="app-shell">
        <?php $homeHref = 'index.php'; $isHomePage = true; require __DIR__ . '/partials/top-navigation.php'; ?>
        <main class="main-area">
            <div class="page-content">
                <section class="module-section module-launcher" aria-labelledby="modules-title">
                    <div class="section-heading">
                        <div class="launcher-heading">
                            <svg class="launcher-symbol" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true"><rect x="2" y="2" width="15" height="15" rx="5"/><rect x="23" y="2" width="15" height="15" rx="5" opacity=".45"/><rect x="2" y="23" width="15" height="15" rx="5" opacity=".65"/><rect x="23" y="23" width="15" height="15" rx="5" opacity=".85"/></svg>
                            <div><h1 id="modules-title">Panel de Control</h1><p class="section-subtitle">Accede a los módulos de tu negocio con <strong>Vent-As.</strong></p></div>
                        </div>
                        <div class="navigation-search" data-navigation-search>
                        <label class="module-search">
                            <svg class="module-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
                            <span class="visually-hidden">Buscar secciones del sistema</span>
                            <input type="search" placeholder="Buscar sección..." autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="navigation-search-results" aria-keyshortcuts="Control+K Meta+K /" data-module-search>
                            <kbd>Ctrl K</kbd>
                        </label>
                        <div id="navigation-search-results" class="navigation-search-results" role="listbox" aria-label="Secciones encontradas" hidden></div>
                        <p class="visually-hidden" role="status" aria-live="polite" data-navigation-search-status></p>
                        <script type="application/json" data-navigation-search-data><?= json_encode($navigationSearchEntries??[],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
                        </div>
                    </div>

                    <div class="module-grid" data-module-grid>
                        <?php if(!$modules): ?><div class="notice contact-full">No tienes módulos autorizados. Solicita al administrador que revise tus grupos de acceso. Puedes consultar <a class="inline-link" href="perfil.php">tu perfil</a> y gestionar tu contraseña.</div><?php endif; ?>
                        <?php foreach ($modules as $key => $module): ?>
                            <a class="module-card" href="<?= htmlspecialchars($module['url'], ENT_QUOTES, 'UTF-8') ?>" data-module="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>" data-module-card data-module-name="<?= htmlspecialchars(mb_strtolower($module['name']), ENT_QUOTES, 'UTF-8') ?>">
                                <?php if($key==='venta_directa' && is_file(dirname(__DIR__).'/img/ventas-directas.png')): ?>
                                <img class="module-icon module-art module-art-custom" src="img/ventas-directas.png?v=<?= filemtime(dirname(__DIR__).'/img/ventas-directas.png') ?>" alt="" aria-hidden="true">
                                <?php else: ?>
                                <span class="module-icon module-art module-art-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div class="module-card-content">
                                <div class="module-card-copy">
                                    <div class="module-title-row">
                                        <h2><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                        <span class="card-arrow" aria-hidden="true">↗</span>
                                    </div>
                                    <p><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="module-card-footer">
                                    <span class="module-category"><?= htmlspecialchars($module['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="module-open">Abrir módulo <span aria-hidden="true">→</span></span>
                                </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>


            </div>
        </main>
    </div>
</body>
</html>

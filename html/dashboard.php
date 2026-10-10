<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title>Inicio | Nexo</title>
    <link rel="stylesheet" href="css/app.css">
    <script src="js/app.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand" href="index.php" aria-label="Nexo, inicio">
                <span class="brand-mark">N</span>
                <span class="brand-name">nexo<span>.</span><small>GESTIÓN EMPRESARIAL</small></span>
            </a>

            <p class="sidebar-label">ESPACIO DE TRABAJO</p>
            <a class="sidebar-link sidebar-link-active" href="index.php">
                <span class="sidebar-icon" aria-hidden="true">⌂</span>
                <span>Inicio</span>
            </a>

            <p class="sidebar-label sidebar-label-modules">MÓDULOS</p>
            <nav class="sidebar-modules" aria-label="Módulos">
                <?php foreach ($modules as $key => $module): ?>
                    <a class="sidebar-link" href="modules/<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>/index.php">
                        <span class="sidebar-dot dot-<?= htmlspecialchars($module['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                        <span><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sidebar-arrow" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-bottom">
                <div class="workspace-card">
                    <span class="workspace-icon" aria-hidden="true">✦</span>
                    <div><strong>Un sistema conectado</strong><p>Tus áreas, en un mismo lugar.</p></div>
                </div>
                <p class="sidebar-version">NEXO · ESPACIO DE TRABAJO</p>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false" data-menu-toggle>☰</button>
                <div class="breadcrumb"><span>Espacio de trabajo</span><span aria-hidden="true">/</span><strong>Inicio</strong></div>
                <div class="topbar-right">
                    <span class="connection-pill <?= $databaseStatus['connected'] ? 'connection-online' : 'connection-offline' ?>">
                        <span class="connection-dot"></span>
                        <?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?>
                    </span>
                    <span class="avatar" aria-label="Usuario">U</span>
                </div>
            </header>

            <div class="page-content">
                <?php if (! $databaseStatus['connected']): ?>
                    <section class="connection-warning" role="status">
                        <span class="warning-icon" aria-hidden="true">!</span>
                        <div>
                            <strong>No se pudo conectar a <?= htmlspecialchars($databaseStatus['database'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <p>El inicio sigue disponible, pero algunas funciones necesitarán la base de datos.</p>
                            <details><summary>Ver detalle técnico</summary><code><?= htmlspecialchars($databaseStatus['error'] ?? 'Error desconocido.', ENT_QUOTES, 'UTF-8') ?></code></details>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="welcome-section">
                    <div class="welcome-copy">
                        <p class="eyebrow"><span class="eyebrow-line"></span> TU NEGOCIO, EN ORDEN</p>
                        <h1>Todo empieza <span>aquí.</span></h1>
                        <p class="welcome-description">Un espacio para conectar las áreas de tu negocio. Elige un módulo para comenzar.</p>
                    </div>
                    <div class="welcome-art" aria-hidden="true">
                        <span class="art-orbit orbit-one"></span>
                        <span class="art-orbit orbit-two"></span>
                        <span class="art-center">N</span>
                        <span class="art-node node-one">CO</span>
                        <span class="art-node node-two">IN</span>
                        <span class="art-node node-three">VE</span>
                        <span class="art-node node-four">CT</span>
                    </div>
                </section>

                <section class="module-section" aria-labelledby="modules-title">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">PANEL DE CONTROL</p>
                            <h2 id="modules-title">Tus módulos</h2>
                            <p class="section-subtitle">Cada área podrá compartir información con las demás.</p>
                        </div>
                        <label class="module-search">
                            <span aria-hidden="true">⌕</span>
                            <span class="visually-hidden">Buscar módulo</span>
                            <input type="search" placeholder="Buscar módulo..." autocomplete="off" data-module-search>
                            <kbd>/</kbd>
                        </label>
                    </div>

                    <div class="module-grid" data-module-grid>
                        <?php foreach ($modules as $key => $module): ?>
                            <a class="module-card" href="modules/<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>/index.php" data-module-card data-module-name="<?= htmlspecialchars(mb_strtolower($module['name']), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="module-card-top">
                                    <span class="module-icon icon-<?= htmlspecialchars($module['color'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($module['initials'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="module-status"><span></span> En desarrollo</span>
                                </div>
                                <div class="module-card-copy">
                                    <div class="module-title-row">
                                        <h3><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <span class="card-arrow" aria-hidden="true">↗</span>
                                    </div>
                                    <p><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="module-card-footer">
                                    <span class="module-category"><?= htmlspecialchars($module['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="module-open">Abrir módulo <span aria-hidden="true">→</span></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <p class="empty-search" data-empty-search hidden>No se encontraron módulos con ese nombre.</p>
                    </div>
                </section>

                <footer class="page-footer">
                    <span>Un sistema que crece contigo.</span>
                    <span><?= count($modules) ?> módulos · En construcción</span>
                </footer>
            </div>
        </main>
    </div>
    <div class="sidebar-backdrop" data-sidebar-backdrop></div>
</body>
</html>

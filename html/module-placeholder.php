<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title><?= $isConfiguration ? 'Configuración | ' : '' ?><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?> | Nexo</title>
    <link rel="stylesheet" href="../../css/app.css">
    <script src="../../js/app.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand" href="../../index.php" aria-label="Nexo, inicio">
                <span class="brand-mark">N</span>
                <span class="brand-name">nexo<span>.</span><small>GESTIÓN EMPRESARIAL</small></span>
            </a>
            <p class="sidebar-label">ESPACIO DE TRABAJO</p>
            <a class="sidebar-link" href="../../index.php"><span class="sidebar-icon" aria-hidden="true">⌂</span><span>Inicio</span></a>
            <p class="sidebar-label sidebar-label-modules">MÓDULOS</p>
            <nav class="sidebar-modules" aria-label="Módulos">
                <?php foreach ($modules as $key => $sidebarModule): ?>
                    <a class="sidebar-link <?= $key === $moduleKey ? 'sidebar-link-active' : '' ?>" href="../<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>/index.php">
                        <span class="sidebar-dot dot-<?= htmlspecialchars($sidebarModule['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                        <span><?= htmlspecialchars($sidebarModule['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($key === $moduleKey): ?><span class="sidebar-arrow" aria-hidden="true">›</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="workspace-card"><span class="workspace-icon" aria-hidden="true">✦</span><div><strong>Un sistema conectado</strong><p>Tus áreas, en un mismo lugar.</p></div></div>
                <p class="sidebar-version">NEXO · ESPACIO DE TRABAJO</p>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false" data-menu-toggle>☰</button>
                <div class="breadcrumb">
                    <a href="../../index.php">Espacio de trabajo</a><span aria-hidden="true">/</span>
                    <?php if ($isConfiguration): ?>
                        <a href="index.php"><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></a><span aria-hidden="true">/</span><strong>Configuración</strong>
                    <?php else: ?>
                        <strong><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($moduleKey === 'ventas'): ?><span aria-hidden="true">/</span><span>Órdenes</span><span aria-hidden="true">/</span><span>Cotizaciones</span><?php endif; ?>
                        <?php if ($moduleKey === 'inventario' && ($modulePageData['area'] ?? '') === 'operations'): ?><span aria-hidden="true">/</span><span>Operaciones</span><span aria-hidden="true">/</span><span><?= htmlspecialchars($modulePageData['definition']['label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="topbar-right">
                    <span class="connection-pill <?= $databaseStatus['connected'] ? 'connection-online' : 'connection-offline' ?>"><span class="connection-dot"></span><?= $databaseStatus['connected'] ? 'Base de datos conectada' : 'Base de datos desconectada' ?></span>
                    <span class="avatar" aria-label="Usuario">U</span>
                </div>
            </header>

            <div class="page-content module-page">
                <a class="back-link" href="../../index.php">← Volver al inicio</a>
                <nav class="module-navigation" aria-label="Secciones de <?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($moduleKey === 'ventas'): ?>
                        <details class="module-menu"><summary class="module-navigation-link <?= ! $isConfiguration ? 'module-navigation-active' : '' ?>">Órdenes <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="index.php" <?= ! $isConfiguration ? 'aria-current="page"' : '' ?>>Cotizaciones</a></div></details>
                    <?php else: ?>
                        <?php $isOverview = ! $isConfiguration && ($modulePageData['area'] ?? '') !== 'operations'; ?>
                        <a class="module-navigation-link <?= $isOverview ? 'module-navigation-active' : '' ?>" href="index.php" <?= $isOverview ? 'aria-current="page"' : '' ?>><?= $moduleKey === 'contactos' ? 'Contactos' : ($moduleKey === 'inventario' ? 'Productos' : 'Vista general') ?></a>
                    <?php endif; ?>
                    <?php if ($moduleKey === 'inventario'): ?>
                        <details class="module-menu"><summary class="module-navigation-link <?= ($modulePageData['area'] ?? '') === 'operations' ? 'module-navigation-active' : '' ?>">Operaciones <span aria-hidden="true">▾</span></summary><div class="module-menu-options">
                            <?php foreach (['delivery' => 'Entregas', 'receipt' => 'Recepciones', 'internal' => 'Interno'] as $operationType => $operationLabel): ?><a href="operaciones.php?type=<?= $operationType ?>" <?= ($modulePageData['area'] ?? '') === 'operations' && ($modulePageData['type'] ?? '') === $operationType ? 'aria-current="page"' : '' ?>><?= $operationLabel ?></a><?php endforeach; ?>
                        </div></details>
                    <?php endif; ?>
                    <a class="module-navigation-link <?= $isConfiguration ? 'module-navigation-active' : '' ?>" href="configuracion.php" <?= $isConfiguration ? 'aria-current="page"' : '' ?>><span aria-hidden="true">⚙</span> Configuración</a>
                </nav>
                <?php if ($moduleKey === 'contactos' && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/contacts/page.php'; ?>
                <?php elseif ($moduleKey === 'inventario' && $modulePageData !== null): ?>
                    <?php require __DIR__ . ($isConfiguration ? '/inventory/configuration.php' : (($modulePageData['area'] ?? '') === 'operations' ? '/inventory/operations.php' : '/inventory/products.php')); ?>
                <?php elseif ($moduleKey === 'ventas' && ! $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/sales/page.php'; ?>
                <?php elseif ($moduleKey === 'contabilidad' && $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/accounting/configuration.php'; ?>
                <?php elseif ($isConfiguration && in_array($moduleKey, ['compras', 'ventas'], true) && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/taxes/configuration.php'; ?>
                <?php elseif ($isConfiguration): ?>
                    <?php require __DIR__ . '/module-configuration.php'; ?>
                <?php else: ?>
                <section class="module-hero">
                    <span class="module-icon icon-<?= htmlspecialchars($module['color'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($module['initials'], ENT_QUOTES, 'UTF-8') ?></span>
                    <p class="eyebrow"><?= htmlspecialchars($module['category'], ENT_QUOTES, 'UTF-8') ?></p>
                    <h1><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="welcome-description"><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <span class="module-status"><span></span> Módulo en desarrollo</span>
                </section>
                <section class="module-plan">
                    <div><p class="eyebrow">PRÓXIMAMENTE</p><h2>Un espacio listo para construir</h2><p class="section-subtitle">Esta sección es el punto de partida. Sus funciones se irán agregando sin mezclar su código con otros módulos.</p></div>
                    <ul>
                        <?php foreach ($module['features'] as $feature): ?>
                            <li><span aria-hidden="true">✦</span><?= htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div class="sidebar-backdrop" data-sidebar-backdrop></div>
</body>
</html>

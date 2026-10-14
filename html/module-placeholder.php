<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title><?= $isConfiguration ? 'Configuración | ' : '' ?><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?> | Nexo</title>
    <link rel="stylesheet" href="../../css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <script src="../../js/app.js?v=<?= filemtime(dirname(__DIR__) . '/js/app.js') ?>" defer></script>
</head>
<body>
    <div class="app-shell">
        <?php $homeHref = '../../index.php'; $isHomePage = false; require __DIR__ . '/partials/top-navigation.php'; ?>
        <main class="main-area">
            <div class="page-content module-page">
                <a class="back-link" href="../../index.php">← Volver al inicio</a>
                <?php if ($moduleKey !== 'dashboard'): ?><nav class="module-navigation" aria-label="Secciones de <?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($moduleKey === 'ajustes'): ?>
                        <a class="module-navigation-link <?= ($modulePageData['area']??'')==='settings'?'module-navigation-active':'' ?>" href="index.php" <?= ($modulePageData['area']??'')==='settings'?'aria-current="page"':'' ?>>Ajustes generales</a>
                        <a class="module-navigation-link <?= ($modulePageData['area']??'')==='company'?'module-navigation-active':'' ?>" href="empresa.php" <?= ($modulePageData['area']??'')==='company'?'aria-current="page"':'' ?>>Empresa</a>
                        <a class="module-navigation-link <?= ($modulePageData['area']??'')==='groups'?'module-navigation-active':'' ?>" href="grupos.php" <?= ($modulePageData['area']??'')==='groups'?'aria-current="page"':'' ?>>Grupos de acceso</a>
                        <a class="module-navigation-link <?= ($modulePageData['area']??'')==='users'?'module-navigation-active':'' ?>" href="usuarios.php" <?= ($modulePageData['area']??'')==='users'?'aria-current="page"':'' ?>>Usuarios</a>
                    <?php elseif (in_array($moduleKey, ['ventas', 'compras'], true)): ?>
                        <?php $isOrderArea = !$isConfiguration && !in_array($modulePageData['area']??'', ['commissions','commission_payments'], true); ?>
                        <details class="module-menu"><summary class="module-navigation-link <?= $isOrderArea ? 'module-navigation-active' : '' ?>">Órdenes <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="index.php" <?= $isOrderArea ? 'aria-current="page"' : '' ?>><?= $moduleKey === 'ventas' ? 'Cotizaciones' : 'Solicitudes de cotización' ?></a></div></details>
                        <?php if($moduleKey==='ventas'): ?><details class="module-menu"><summary class="module-navigation-link <?= in_array($modulePageData['area']??'', ['commissions','commission_payments'], true)?'module-navigation-active':'' ?>">Comisiones <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="comisiones.php" <?= ($modulePageData['area']??'')==='commissions'?'aria-current="page"':'' ?>>Comisiones</a><a href="pagos-comisiones.php" <?= ($modulePageData['area']??'')==='commission_payments'?'aria-current="page"':'' ?>>Pagos de comisiones</a></div></details><?php endif; ?>
                    <?php elseif ($moduleKey === 'contabilidad'): ?>
                        <?php foreach (['customer' => 'Clientes', 'supplier' => 'Proveedores'] as $invoiceType => $invoiceLabel): ?><details class="module-menu"><summary class="module-navigation-link <?= ! $isConfiguration && ($modulePageData['type'] ?? '') === $invoiceType ? 'module-navigation-active' : '' ?>"><?= $invoiceLabel ?> <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="index.php?type=<?= $invoiceType ?>">Facturas</a><a href="pagos.php?type=<?= $invoiceType ?>">Pagos</a></div></details><?php endforeach; ?>
                        <details class="module-menu"><summary class="module-navigation-link <?= ($modulePageData['area'] ?? '') === 'cash_flow' ? 'module-navigation-active' : '' ?>">Informes <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="ingresos-egresos.php" <?= ($modulePageData['area'] ?? '') === 'cash_flow' ? 'aria-current="page"' : '' ?>>Ingresos y egresos</a></div></details>
                        <details class="module-menu"><summary class="module-navigation-link <?= ($modulePageData['area']??'')==='treasury'?'module-navigation-active':'' ?>">Tesorería <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="tesoreria.php">Movimientos de dinero</a><a href="tesoreria.php?kind=transfer">Transferencias internas</a><a href="tesoreria.php?kind=opening">Saldos iniciales</a><a href="tesoreria.php?kind=income">Otros ingresos</a><a href="tesoreria.php?kind=expense">Otros egresos</a></div></details>
                    <?php else: ?>
                        <?php $isOverview = ! $isConfiguration && ! in_array($modulePageData['area'] ?? '', ['operations', 'reports'], true); ?>
                        <a class="module-navigation-link <?= $isOverview ? 'module-navigation-active' : '' ?>" href="index.php" <?= $isOverview ? 'aria-current="page"' : '' ?>><?= $moduleKey === 'contactos' ? 'Contactos' : ($moduleKey === 'inventario' ? 'Productos' : 'Vista general') ?></a>
                    <?php endif; ?>
                    <?php if ($moduleKey === 'inventario'): ?>
                        <details class="module-menu"><summary class="module-navigation-link <?= ($modulePageData['area'] ?? '') === 'operations' ? 'module-navigation-active' : '' ?>">Operaciones <span aria-hidden="true">▾</span></summary><div class="module-menu-options">
                            <?php foreach (['delivery' => 'Entregas', 'receipt' => 'Recepciones', 'internal' => 'Interno'] as $operationType => $operationLabel): ?><a href="operaciones.php?type=<?= $operationType ?>" <?= ($modulePageData['area'] ?? '') === 'operations' && ($modulePageData['type'] ?? '') === $operationType ? 'aria-current="page"' : '' ?>><?= $operationLabel ?></a><?php endforeach; ?>
                        </div></details>
                        <details class="module-menu"><summary class="module-navigation-link <?= ($modulePageData['area'] ?? '') === 'reports' ? 'module-navigation-active' : '' ?>">Informes <span aria-hidden="true">▾</span></summary><div class="module-menu-options"><a href="informes.php?view=movements">Historial de movimientos</a><a href="informes.php?view=stock">Existencias por ubicación</a></div></details>
                    <?php endif; ?>
                    <?php if (!in_array($moduleKey,['dashboard','ajustes'],true)): ?><a class="module-navigation-link <?= $isConfiguration ? 'module-navigation-active' : '' ?>" href="configuracion.php" <?= $isConfiguration ? 'aria-current="page"' : '' ?>><span aria-hidden="true">⚙</span> Configuración</a><?php endif; ?>
                </nav><?php endif; ?>
                <?php if ($moduleKey === 'ajustes' && $modulePageData !== null): ?>
                    <?php require __DIR__.'/settings/page.php'; ?>
                <?php elseif ($moduleKey === 'dashboard' && ! $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/dashboard.php'; ?>
                <?php elseif ($moduleKey === 'contactos' && $modulePageData !== null): ?>
                    <?php require __DIR__ . '/contacts/page.php'; ?>
                <?php elseif ($moduleKey === 'inventario' && $modulePageData !== null): ?>
                    <?php require __DIR__ . ($isConfiguration ? '/inventory/configuration.php' : (match ($modulePageData['area'] ?? '') { 'operations' => '/inventory/operations.php', 'reports' => '/inventory/reports.php', default => '/inventory/products.php' })); ?>
                <?php elseif ($moduleKey === 'ventas' && ! $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . (match ($modulePageData['area']??'') { 'commissions'=>'/sales/commissions.php', 'commission_payments'=>'/sales/commission-payments.php', default=>'/sales/page.php' }); ?>
                <?php elseif ($moduleKey === 'contabilidad' && $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . (match ($modulePageData['area'] ?? '') { 'currencies' => '/accounting/currencies.php', 'payment_configuration' => '/accounting/payment-configuration.php', default => '/accounting/configuration.php' }); ?>
                <?php elseif ($moduleKey === 'contabilidad' && ! $isConfiguration && $modulePageData !== null): ?>
                    <?php require __DIR__ . (match ($modulePageData['area']??'') { 'treasury'=>'/accounting/treasury.php', 'cash_flow'=>'/accounting/cash-flow.php', 'payments'=>'/accounting/payments.php', default=>'/accounting/invoices.php' }); ?>
                <?php elseif ($moduleKey === 'compras' && ! $isConfiguration && ($modulePageData['area'] ?? '') === 'purchase_order'): ?>
                    <?php require __DIR__ . '/purchases/page.php'; ?>
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
</body>
</html>

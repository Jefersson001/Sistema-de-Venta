<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title>Inicio | Nexo</title>
    <link rel="stylesheet" href="css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <script src="js/app.js?v=<?= filemtime(dirname(__DIR__) . '/js/app.js') ?>" defer></script>
</head>
<body>
    <div class="app-shell">
        <main class="main-area">
            <header class="topbar">
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
                        <h1>Tu negocio, <span>al día.</span></h1>
                        <p class="welcome-description">Consulta tus pendientes y accede a las áreas de tu negocio desde un mismo lugar.</p>
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

                <section class="quick-actions" aria-labelledby="actions-title">
                    <div>
                        <h2 id="actions-title">Acciones rápidas</h2>
                        <p class="section-subtitle" id="actions-help">Crea una venta, una compra o un contacto.</p>
                    </div>
                    <div class="action-buttons" aria-describedby="actions-help">
                        <a class="action-button action-primary" href="modules/ventas/index.php?new=1">Nueva venta <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="modules/compras/index.php?new=1">Registrar compra <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="modules/contactos/index.php?new=1">Crear contacto <span aria-hidden="true">→</span></a>
                    </div>
                </section>

                <section class="business-overview" aria-labelledby="overview-title">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">RESUMEN DEL NEGOCIO</p>
                            <h2 id="overview-title">Pendientes y alertas</h2><?php if($dashboard['available']): ?><p class="section-subtitle">Principal: <?= htmlspecialchars($dashboard['settings']['primary_code'],ENT_QUOTES,'UTF-8') ?> · Alterna: <?= htmlspecialchars($dashboard['settings']['alternate_code'],ENT_QUOTES,'UTF-8') ?>. Los saldos se muestran separados por moneda.</p><?php endif; ?>
                            <p class="section-subtitle"><?= $databaseStatus['connected']
                                ? ($dashboard['available']?'Información calculada a partir de órdenes, movimientos, facturas y pagos.':'No se pudo cargar el resumen. Verifica la instalación de los módulos.')
                                : 'No se pueden consultar los indicadores mientras la base de datos esté desconectada.' ?></p>
                        </div>
                    </div>
                    <div class="overview-grid">
                        <article class="overview-card">
                            <span class="overview-label">Ventas por completar</span>
                            <p class="overview-value"><?= $dashboard['available'] ? $dashboard['sales'] : '—' ?></p>
                            <p>Consulta aquí los pedidos que aún necesitan atención.</p>
                            <a class="overview-link" href="modules/ventas/index.php?status=sale&amp;pending=1">Ir a Ventas <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Compras por completar</span>
                            <p class="overview-value"><?= $dashboard['available'] ? $dashboard['purchases'] : '—' ?></p>
                            <p>Órdenes con cantidades pendientes de recibir o facturar.</p>
                            <a class="overview-link" href="modules/compras/index.php?status=purchase&amp;pending=1">Ir a Compras <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Cobros pendientes</span>
                            <p class="overview-value"><?= $dashboard['available'] ? htmlspecialchars(dashboardMoney($dashboard['receivables']),ENT_QUOTES,'UTF-8') : '—' ?></p>
                            <p>Saldo de facturas registradas, descontando pagos confirmados y notas de crédito.</p>
                            <a class="overview-link" href="modules/contabilidad/index.php?type=customer&amp;unpaid=1">Ir a Contabilidad <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card"><span class="overview-label">Pagos a proveedores pendientes</span><p class="overview-value"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['payables']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="modules/contabilidad/index.php?type=supplier&amp;unpaid=1">Consultar facturas de proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Facturas vencidas</span><p class="overview-value"><?= $dashboard['available']?$dashboard['overdue']:'—' ?></p><p>Clientes y proveedores con saldo y vencimiento anterior a hoy.</p><a class="overview-link" href="modules/contabilidad/index.php?type=customer&amp;overdue=1">Clientes →</a> <a class="overview-link" href="modules/contabilidad/index.php?type=supplier&amp;overdue=1">Proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Pagos sin aplicar</span><p class="overview-value"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['unallocated']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="modules/contabilidad/pagos.php?type=customer&amp;available=1">Clientes →</a> <a class="overview-link" href="modules/contabilidad/pagos.php?type=supplier&amp;available=1">Proveedores →</a></article>
                    </div>
                    <div class="inventory-alert">
                        <span class="inventory-alert-icon" aria-hidden="true">!</span>
                        <div>
                            <h3>Alertas de inventario</h3>
                            <?php if($dashboard['available']): ?><ul><?php foreach($dashboard['alerts'] as $alert): ?><li><a class="overview-link" href="modules/inventario/index.php?id=<?= (int)$alert['id'] ?>"><?= htmlspecialchars($alert['name'],ENT_QUOTES,'UTF-8') ?></a> · Disponible <?= htmlspecialchars($alert['available'],ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?>
                            <p><?= $databaseStatus['connected']
                                ? ($dashboard['available']?$dashboard['alert_count'].' productos sin cantidad disponible después de reservas.':'No se pudo consultar la disponibilidad.')
                                : 'No se puede comprobar el inventario sin conexión a la base de datos.' ?></p>
                        </div>
                        <a class="overview-link" href="modules/inventario/index.php">Ir a Inventario <span aria-hidden="true">→</span></a>
                    </div>
                </section>

                <?php if($dashboard['available']): ?><section class="business-overview"><h2>Tareas pendientes</h2><div class="overview-grid"><?php foreach($dashboard['tasks'] as $task): ?><article class="overview-card"><a class="overview-link" href="<?= htmlspecialchars($task['href'],ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($task['label'],ENT_QUOTES,'UTF-8') ?></a><p><?= htmlspecialchars($task['detail'],ENT_QUOTES,'UTF-8') ?></p></article><?php endforeach; ?><?php if(!$dashboard['tasks']): ?><p>No hay operaciones pendientes ni facturas vencidas con saldo.</p><?php endif; ?></div></section><?php endif; ?>
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
                                    <span class="module-status"><span></span> <?= $key === 'contactos' ? 'Disponible' : 'En desarrollo' ?></span>
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
    <?php $homeHref = 'index.php'; $isHomePage = true; require __DIR__ . '/partials/bottom-navigation.php'; ?>
</body>
</html>

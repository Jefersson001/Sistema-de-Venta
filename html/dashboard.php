<?php $dashboard = $modulePageData['dashboard']; ?>
                <?php if (! $databaseStatus['connected']): ?>
                    <section class="connection-warning" role="status">
                        <span class="warning-icon" aria-hidden="true">!</span>
                        <div>
                            <strong>No se pudo conectar a <?= htmlspecialchars($databaseStatus['database'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <p>Los indicadores del Dashboard necesitan conexión a la base de datos.</p>
                            <details><summary>Ver detalle técnico</summary><code><?= htmlspecialchars($databaseStatus['error'] ?? 'Error desconocido.', ENT_QUOTES, 'UTF-8') ?></code></details>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="quick-actions" aria-labelledby="actions-title">
                    <div>
                        <h2 id="actions-title">Acciones rápidas</h2>
                    </div>
                    <div class="action-buttons">
                        <a class="action-button" href="../contactos/index.php">Contactos <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="../inventario/index.php">Productos <span aria-hidden="true">→</span></a>
                        <a class="action-button action-primary" href="../ventas/index.php?status=all">Ventas <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="../contabilidad/index.php?type=customer&amp;status=all">Facturas <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="../contabilidad/pagos.php?type=customer&amp;status=all">Pagos <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="../inventario/operaciones.php?type=receipt">Recepciones <span aria-hidden="true">→</span></a>
                        <a class="action-button" href="../inventario/operaciones.php?type=internal">Interno <span aria-hidden="true">→</span></a>
                    </div>
                </section>

                <div class="dashboard-notebook" data-dashboard data-notebook>
                    <nav class="notebook-tabs dashboard-tabs" data-notebook-tabs aria-label="Secciones del dashboard">
                        <a id="dashboard-tab-income" href="#dashboard-page-income">Ingresos y egresos</a>
                        <a id="dashboard-tab-overview" href="#dashboard-page-overview">Resumen del negocio</a>
                        <a id="dashboard-tab-alerts" href="#dashboard-page-alerts">Alertas de inventario</a>
                        <a id="dashboard-tab-tasks" href="#dashboard-page-tasks">Tareas pendientes</a>
                        <a id="dashboard-tab-commissions" href="#dashboard-page-commissions">Comisiones</a>
                    </nav>
                    <p class="section-subtitle dashboard-updated" data-dashboard-updated role="status"><?= $dashboard['available'] && $modulePageData['cashFlow'] ? 'Actualizado '.$modulePageData['cashFlow']['updated_time'].' · Se actualiza cada 30 s' : 'Esperando datos del dashboard.' ?></p>
                    <p class="cash-flow-error" data-dashboard-error role="alert" <?= !$modulePageData['cashFlowErrors'] ? 'hidden' : '' ?>><?= htmlspecialchars(implode(' ', $modulePageData['cashFlowErrors']), ENT_QUOTES, 'UTF-8') ?></p>
                    <section class="notebook-page" id="dashboard-page-income" data-notebook-page aria-labelledby="dashboard-tab-income">
                <?php
                $cashFlow = $modulePageData['cashFlow']; $cashFlowRange = $modulePageData['cashFlowRange'];
                $cashFlowErrors = $modulePageData['cashFlowErrors']; $cashFlowLive = true;
                $cashFlowEndpoint = 'resumen.php';
                require __DIR__ . '/partials/cash-flow.php';
                ?>
                    </section>
                    <section class="notebook-page" id="dashboard-page-overview" data-notebook-page aria-labelledby="dashboard-tab-overview">
                <section class="business-overview" aria-labelledby="overview-title">
                    <div class="section-heading">
                        <h2 id="overview-title">Resumen del negocio</h2>
                    </div>
                    <div class="overview-grid">
                        <article class="overview-card">
                            <span class="overview-label">Ventas por completar</span>
                            <p class="overview-value" data-dashboard-metric="sales"><?= $dashboard['available'] ? $dashboard['sales'] : '—' ?></p>
                            <p>Consulta aquí los pedidos que aún necesitan atención.</p>
                            <a class="overview-link" href="../ventas/index.php?status=sale&amp;pending=1">Ir a Ventas <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Compras por completar</span>
                            <p class="overview-value" data-dashboard-metric="purchases"><?= $dashboard['available'] ? $dashboard['purchases'] : '—' ?></p>
                            <p>Órdenes con cantidades pendientes de recibir o facturar.</p>
                            <a class="overview-link" href="../compras/index.php?status=purchase&amp;pending=1">Ir a Compras <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Cobros pendientes</span>
                            <p class="overview-value" data-dashboard-metric="receivables"><?= $dashboard['available'] ? htmlspecialchars(dashboardMoney($dashboard['receivables']),ENT_QUOTES,'UTF-8') : '—' ?></p>
                            <p>Saldo de facturas registradas, descontando pagos confirmados y notas de crédito.</p>
                            <a class="overview-link" href="../contabilidad/index.php?type=customer&amp;unpaid=1">Ir a Contabilidad <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card"><span class="overview-label">Pagos a proveedores pendientes</span><p class="overview-value" data-dashboard-metric="payables"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['payables']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="../contabilidad/index.php?type=supplier&amp;unpaid=1">Consultar facturas de proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Facturas vencidas</span><p class="overview-value" data-dashboard-metric="overdue"><?= $dashboard['available']?$dashboard['overdue']:'—' ?></p><p>Clientes y proveedores con saldo y vencimiento anterior a hoy.</p><a class="overview-link" href="../contabilidad/index.php?type=customer&amp;overdue=1">Clientes →</a> <a class="overview-link" href="../contabilidad/index.php?type=supplier&amp;overdue=1">Proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Pagos sin aplicar</span><p class="overview-value" data-dashboard-metric="unallocated"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['unallocated']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="../contabilidad/pagos.php?type=customer&amp;available=1">Clientes →</a> <a class="overview-link" href="../contabilidad/pagos.php?type=supplier&amp;available=1">Proveedores →</a></article>
                    </div>
                </section>
                    </section>
                    <section class="notebook-page" id="dashboard-page-alerts" data-notebook-page aria-labelledby="dashboard-tab-alerts">
                    <div class="section-heading"><h2>Alertas de inventario</h2></div>
                    <div class="inventory-alert">
                        <span class="inventory-alert-icon" aria-hidden="true">!</span>
                        <div>
                            <h3>Productos sin disponibilidad</h3>
                            <ul data-dashboard-alerts><?php if($dashboard['available']): ?><?php foreach($dashboard['alerts'] as $alert): ?><li><a class="overview-link" href="../inventario/index.php?id=<?= (int)$alert['id'] ?>"><?= htmlspecialchars($alert['name'],ENT_QUOTES,'UTF-8') ?></a> · Disponible <?= htmlspecialchars($alert['available'],ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?><?php endif; ?></ul>
                            <p data-dashboard-alert-count><?= $databaseStatus['connected']
                                ? ($dashboard['available']?$dashboard['alert_count'].' productos sin cantidad disponible después de reservas.':'No se pudo consultar la disponibilidad.')
                                : 'No se puede comprobar el inventario sin conexión a la base de datos.' ?></p>
                        </div>
                        <a class="overview-link" href="../inventario/index.php">Ir a Inventario <span aria-hidden="true">→</span></a>
                    </div>
                    </section>
                    <section class="notebook-page" id="dashboard-page-tasks" data-notebook-page aria-labelledby="dashboard-tab-tasks">
                        <div class="section-heading"><h2>Tareas pendientes</h2></div>
                        <div class="overview-grid" data-dashboard-tasks><?php if($dashboard['available']): ?><?php foreach($dashboard['tasks'] as $task): ?><article class="overview-card"><a class="overview-link" href="<?= htmlspecialchars('../../' . $task['href'],ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($task['label'],ENT_QUOTES,'UTF-8') ?></a><p><?= htmlspecialchars($task['detail'],ENT_QUOTES,'UTF-8') ?></p></article><?php endforeach; ?><?php if(!$dashboard['tasks']): ?><p>No hay operaciones pendientes ni facturas vencidas con saldo.</p><?php endif; ?><?php else: ?><p>No se pudieron consultar las tareas pendientes.</p><?php endif; ?></div>
                    </section>
                    <section class="notebook-page" id="dashboard-page-commissions" data-notebook-page aria-labelledby="dashboard-tab-commissions">
                        <div class="section-heading"><div><h2>Comisiones por vendedor</h2><p class="section-subtitle">Fecha de factura · <span data-dashboard-commission-range><?= htmlspecialchars($cashFlowRange['label'],ENT_QUOTES,'UTF-8') ?></span></p></div><a class="overview-link" data-dashboard-commission-history href="../ventas/comisiones.php?<?= htmlspecialchars(cashFlowQuery($cashFlowRange),ENT_QUOTES,'UTF-8') ?>">Ver detalle y filtros →</a></div>
                        <p class="section-subtitle">Las comisiones se configuran en Producto → Ventas, como importe fijo o porcentaje. Solo los cobros confirmados habilitan su pago; el pago al vendedor se registra como egreso.</p>
                        <?php $commissionGroups=$modulePageData['commissionGroups'];$commissionRange=$cashFlowRange;$commissionPrefix='../ventas/';$commissionLive=true;require __DIR__.'/sales/commission-summary.php'; ?>
                    </section>
                </div>
                <script src="../../js/dashboard.js?v=<?= filemtime(dirname(__DIR__).'/js/dashboard.js') ?>" defer></script>
                <script src="../../js/cash-flow.js?v=<?= filemtime(dirname(__DIR__).'/js/cash-flow.js') ?>" defer></script>
                <footer class="page-footer">
                    <span>Un sistema que crece contigo.</span>
                    <span><?= count($modules) ?> módulos · En construcción</span>
                </footer>

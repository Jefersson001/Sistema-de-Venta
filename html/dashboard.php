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

                <section class="business-overview" aria-labelledby="overview-title">
                    <div class="section-heading">
                        <h2 id="overview-title">Resumen del negocio</h2>
                    </div>
                    <div class="overview-grid">
                        <article class="overview-card">
                            <span class="overview-label">Ventas por completar</span>
                            <p class="overview-value"><?= $dashboard['available'] ? $dashboard['sales'] : '—' ?></p>
                            <p>Consulta aquí los pedidos que aún necesitan atención.</p>
                            <a class="overview-link" href="../ventas/index.php?status=sale&amp;pending=1">Ir a Ventas <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Compras por completar</span>
                            <p class="overview-value"><?= $dashboard['available'] ? $dashboard['purchases'] : '—' ?></p>
                            <p>Órdenes con cantidades pendientes de recibir o facturar.</p>
                            <a class="overview-link" href="../compras/index.php?status=purchase&amp;pending=1">Ir a Compras <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card">
                            <span class="overview-label">Cobros pendientes</span>
                            <p class="overview-value"><?= $dashboard['available'] ? htmlspecialchars(dashboardMoney($dashboard['receivables']),ENT_QUOTES,'UTF-8') : '—' ?></p>
                            <p>Saldo de facturas registradas, descontando pagos confirmados y notas de crédito.</p>
                            <a class="overview-link" href="../contabilidad/index.php?type=customer&amp;unpaid=1">Ir a Contabilidad <span aria-hidden="true">→</span></a>
                        </article>
                        <article class="overview-card"><span class="overview-label">Pagos a proveedores pendientes</span><p class="overview-value"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['payables']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="../contabilidad/index.php?type=supplier&amp;unpaid=1">Consultar facturas de proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Facturas vencidas</span><p class="overview-value"><?= $dashboard['available']?$dashboard['overdue']:'—' ?></p><p>Clientes y proveedores con saldo y vencimiento anterior a hoy.</p><a class="overview-link" href="../contabilidad/index.php?type=customer&amp;overdue=1">Clientes →</a> <a class="overview-link" href="../contabilidad/index.php?type=supplier&amp;overdue=1">Proveedores →</a></article>
                        <article class="overview-card"><span class="overview-label">Pagos sin aplicar</span><p class="overview-value"><?= $dashboard['available']?htmlspecialchars(dashboardMoney($dashboard['unallocated']),ENT_QUOTES,'UTF-8'):'—' ?></p><a class="overview-link" href="../contabilidad/pagos.php?type=customer&amp;available=1">Clientes →</a> <a class="overview-link" href="../contabilidad/pagos.php?type=supplier&amp;available=1">Proveedores →</a></article>
                    </div>
                    <div class="inventory-alert">
                        <span class="inventory-alert-icon" aria-hidden="true">!</span>
                        <div>
                            <h3>Alertas de inventario</h3>
                            <?php if($dashboard['available']): ?><ul><?php foreach($dashboard['alerts'] as $alert): ?><li><a class="overview-link" href="../inventario/index.php?id=<?= (int)$alert['id'] ?>"><?= htmlspecialchars($alert['name'],ENT_QUOTES,'UTF-8') ?></a> · Disponible <?= htmlspecialchars($alert['available'],ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?>
                            <p><?= $databaseStatus['connected']
                                ? ($dashboard['available']?$dashboard['alert_count'].' productos sin cantidad disponible después de reservas.':'No se pudo consultar la disponibilidad.')
                                : 'No se puede comprobar el inventario sin conexión a la base de datos.' ?></p>
                        </div>
                        <a class="overview-link" href="../inventario/index.php">Ir a Inventario <span aria-hidden="true">→</span></a>
                    </div>
                </section>

                <?php if($dashboard['available']): ?><section class="business-overview"><h2>Tareas pendientes</h2><div class="overview-grid"><?php foreach($dashboard['tasks'] as $task): ?><article class="overview-card"><a class="overview-link" href="<?= htmlspecialchars('../../' . $task['href'],ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($task['label'],ENT_QUOTES,'UTF-8') ?></a><p><?= htmlspecialchars($task['detail'],ENT_QUOTES,'UTF-8') ?></p></article><?php endforeach; ?><?php if(!$dashboard['tasks']): ?><p>No hay operaciones pendientes ni facturas vencidas con saldo.</p><?php endif; ?></div></section><?php endif; ?>
                <footer class="page-footer">
                    <span>Un sistema que crece contigo.</span>
                    <span><?= count($modules) ?> módulos · En construcción</span>
                </footer>

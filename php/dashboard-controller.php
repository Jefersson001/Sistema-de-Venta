<?php
declare(strict_types=1);

require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/dashboard-repository.php';
require_once __DIR__ . '/cash-flow-controller.php';

function renderDashboard(): void
{
    $databaseStatus = getDatabaseStatus();
    $dashboard = ['available' => false];
    $cashFlowPage = cashFlowPageState($_GET);
    if ($databaseStatus['connected']) {
        try {
            $db = createDatabaseConnection();
            $dashboard = loadDashboard($db);
            if (!$cashFlowPage['cashFlowErrors']) $cashFlowPage['cashFlow'] = loadCashFlowSummary($db, $cashFlowPage['cashFlowRange']);
        } catch (PDOException $exception) {
            error_log('Dashboard: ' . $exception->getMessage());
            $cashFlowPage['cashFlowErrors'][] = 'No se pudieron consultar los ingresos y egresos. Intenta de nuevo.';
        }
    } else $cashFlowPage['cashFlowErrors'][] = 'No se pudo conectar a la base de datos.';

    renderModulePage('dashboard', false, [
        'area' => 'dashboard',
        'databaseStatus' => $databaseStatus,
        'dashboard' => $dashboard,
    ] + $cashFlowPage);
}

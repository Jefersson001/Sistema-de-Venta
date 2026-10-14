<?php
declare(strict_types=1);

require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/dashboard-repository.php';
require_once __DIR__ . '/cash-flow-controller.php';
require_once __DIR__.'/commission-repository.php';

function renderDashboard(): void
{
    $databaseStatus = getDatabaseStatus();
    $dashboard = ['available' => false];
    $cashFlowPage = cashFlowPageState($_GET);
    $cashFlowPage['commissionGroups']=[];
    if ($databaseStatus['connected']) {
        try {
            $db = createDatabaseConnection();
            $dashboard = loadDashboard($db);
            if (!$cashFlowPage['cashFlowErrors']) {
                $cashFlowPage['cashFlow'] = permissionCashFlowSummary($db, $cashFlowPage['cashFlowRange']);
                $cashFlowPage['commissionGroups']=permissionDashboardCommissions($db,$cashFlowPage['cashFlowRange']);
            }
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

function renderDashboardSnapshot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        echo json_encode(['error'=>'La consulta solo admite GET.']);
        return;
    }
    $db = null;
    try {
        $range = cashFlowRange($_GET);
        $db = createDatabaseConnection();
        $db->beginTransaction();
        $summary = permissionCashFlowSummary($db, $range);
        $dashboard = loadDashboard($db);
        $dashboard['metrics'] = [];
        foreach (['sales','purchases','overdue'] as $key) {if(in_array($key,$dashboard['allowed_metrics'],true))$dashboard['metrics'][$key]=(string)$dashboard[$key];else unset($dashboard[$key]);}
        foreach (['receivables','payables','unallocated'] as $key) {if(in_array($key,$dashboard['allowed_metrics'],true))$dashboard['metrics'][$key]=dashboardMoney($dashboard[$key]);else unset($dashboard[$key]);}
        $summary['dashboard'] = $dashboard;
        $summary['permission_signature']=hash('sha256',json_encode(permissionMap()));
        $summary['commissions']=permissionDashboardCommissions($db,$range);
        $db->commit();
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode(['error'=>$exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        error_log('Actualización del Dashboard: '.$exception->getMessage());
        http_response_code(503);
        echo json_encode(['error'=>'No se pudo actualizar el dashboard. Se conservan los últimos datos disponibles.']);
    }
}

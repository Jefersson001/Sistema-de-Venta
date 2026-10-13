<?php
declare(strict_types=1);
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/cash-flow-repository.php';

function cashFlowPageState(array $input): array
{
    $range = cashFlowRange([]); $errors = [];
    try { $range = cashFlowRange($input); }
    catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
        $range['period'] = is_string($input['period'] ?? null) && isset(cashFlowPeriods()[$input['period']]) ? $input['period'] : 'today';
        foreach (['date_from','date_to'] as $key) {
            try { $range[$key] = cashFlowDate($input[$key] ?? $range[$key]); }
            catch (InvalidArgumentException) { $range[$key] = ''; }
        }
    }
    return ['cashFlowRange'=>$range, 'cashFlowErrors'=>$errors, 'cashFlow'=>null];
}

function renderCashFlowReport(): void
{
    $page = cashFlowPageState($_GET) + ['area'=>'cash_flow', 'history'=>['rows'=>[], 'total'=>0, 'pages'=>1, 'currentPage'=>1]];
    $page['databaseStatus'] = getDatabaseStatus();
    if (!$page['databaseStatus']['connected']) $page['cashFlowErrors'][] = 'No se pudo conectar a la base de datos.';
    elseif (!$page['cashFlowErrors']) {
        try {
            $db = createDatabaseConnection();
            $page['cashFlow'] = loadCashFlowSummary($db, $page['cashFlowRange']);
            $page['history'] = loadCashFlowHistory($db, $page['cashFlowRange'], $_GET['page'] ?? 1);
        } catch (PDOException $exception) {
            error_log('Ingresos y egresos: '.$exception->getMessage());
            $page['cashFlowErrors'][] = 'No se pudieron consultar los ingresos y egresos. Intenta de nuevo.';
            $page['cashFlow'] = null;
        }
    }
    renderModulePage('contabilidad', false, $page);
}

function renderCashFlowSnapshot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET'); http_response_code(405); echo json_encode(['error'=>'La consulta solo admite GET.']); return;
    }
    try {
        $range = cashFlowRange($_GET);
        echo json_encode(loadCashFlowSummary(createDatabaseConnection(), $range), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422); echo json_encode(['error'=>$exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Resumen de ingresos y egresos: '.$exception->getMessage());
        http_response_code(503); echo json_encode(['error'=>'No se pudo actualizar el resumen. Intenta de nuevo.']);
    }
}

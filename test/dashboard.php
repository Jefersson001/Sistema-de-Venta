<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/dashboard-repository.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';
require_once dirname(__DIR__).'/php/connection.php';

function dashboardCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function dashboardRequest(string $query = '', bool $post = false): array
{
    $request = curl_init('http://localhost/project/modules/dashboard/resumen.php'.$query);
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_HEADER=>true]);
    if ($post) curl_setopt($request, CURLOPT_POSTFIELDS, '');
    $response = curl_exec($request);
    dashboardCheck(is_string($response), 'Falló la consulta del dashboard.');
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
    curl_close($request);
    $headers = substr($response, 0, $headerSize);
    dashboardCheck(str_contains(strtolower($headers), 'cache-control: no-store'), 'La respuesta puede quedar en caché.');
    return [$status, json_decode(substr($response, $headerSize), true, 512, JSON_THROW_ON_ERROR)];
}

$db = createDatabaseConnection();
$expected = loadDashboard($db);
foreach (['today','last7','month'] as $period) {
    [$status, $snapshot] = dashboardRequest('?period='.$period);
    dashboardCheck($status === 200, 'No se puede actualizar '.$period);
    dashboardCheck($snapshot['currencies'] === loadCashFlowSummary($db, cashFlowRange(['period'=>$period]))['currencies'], 'Los movimientos no coinciden con la base de datos.');
    $actual = $snapshot['dashboard'];
    foreach (['sales','purchases','overdue'] as $key) dashboardCheck($actual['metrics'][$key] === (string)$expected[$key], 'Indicador incorrecto: '.$key);
    foreach (['receivables','payables','unallocated'] as $key) dashboardCheck($actual['metrics'][$key] === dashboardMoney($expected[$key]), 'Saldo incorrecto: '.$key);
    foreach (['alerts','alert_count','tasks'] as $key) dashboardCheck($actual[$key] === $expected[$key], 'Pendientes incorrectos: '.$key);
}
[$status] = dashboardRequest('?period=custom&date_from=2026-10-10&date_to=2026-10-01');
dashboardCheck($status === 422, 'Se aceptó un rango invertido.');
[$status] = dashboardRequest('?period[]=today');
dashboardCheck($status === 422, 'Se aceptó un período inválido.');
[$status] = dashboardRequest('', true);
dashboardCheck($status === 405, 'El endpoint admite escrituras.');
$html = file_get_contents('http://localhost/project/modules/dashboard/index.php');
dashboardCheck(is_string($html) && !preg_match('/Warning:|Fatal error|Notice:/', $html), 'La vista contiene errores PHP.');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
$xpath = new DOMXPath($dom);
dashboardCheck($xpath->query('//*[@data-dashboard]//*[@data-notebook-page]')->length === 4, 'Faltan pestañas del dashboard.');
dashboardCheck($xpath->query('//*[@data-dashboard-metric]')->length === 6, 'Faltan indicadores actualizables.');
dashboardCheck($xpath->query('//*[@data-cash-flow-account-grid]')->length === 0, 'Volvieron las cuentas al dashboard.');
dashboardCheck($xpath->query('//*[@data-cash-flow-endpoint="resumen.php"]')->length === 1, 'La actualización no consulta el dashboard completo.');
echo "OK: pestañas y snapshot del dashboard coinciden con los datos reales; rangos y métodos HTTP validados.\n";

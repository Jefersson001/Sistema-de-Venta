<?php
declare(strict_types=1);
require_once __DIR__ . '/currency-repository.php';
require_once __DIR__ . '/workflow-repository.php';

function cashFlowPeriods(): array
{
    return ['today'=>'Hoy', 'last7'=>'Últimos 7 días', 'month'=>'Este mes', 'custom'=>'Personalizado'];
}

function cashFlowDate(mixed $value): string
{
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-31') {
        throw new InvalidArgumentException('Selecciona fechas válidas para el período.');
    }
    return $value;
}

function cashFlowRange(array $input, ?string $today = null): array
{
    $today = cashFlowDate($today ?? commerceToday());
    $period = $input['period'] ?? 'today';
    if (!is_string($period) || !isset(cashFlowPeriods()[$period])) throw new InvalidArgumentException('Selecciona un período válido.');
    $date = new DateTimeImmutable($today, new DateTimeZone('America/Caracas'));
    $from = match ($period) {
        'last7' => $date->modify('-6 days')->format('Y-m-d'),
        'month' => $date->format('Y-m-01'),
        'custom' => cashFlowDate($input['date_from'] ?? null),
        default => $today,
    };
    $to = $period === 'custom' ? cashFlowDate($input['date_to'] ?? null) : $today;
    if ($from > $to) throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
    $format = static fn(string $value): string => (new DateTimeImmutable($value))->format('d/m/Y');
    return ['period'=>$period, 'date_from'=>$from, 'date_to'=>$to, 'label'=>$from===$to ? $format($from) : $format($from).' — '.$format($to)];
}

function cashFlowQuery(array $range, ?int $accountId = null): string
{
    return http_build_query(['period'=>$range['period']] + ($range['period']==='custom' ? ['date_from'=>$range['date_from'], 'date_to'=>$range['date_to']] : []) + ($accountId ? ['account_id'=>$accountId] : []));
}

function cashFlowZeroAmounts(array $codes): array
{
    $amounts = [];
    foreach ($codes as $code) $amounts[$code] = ['code'=>$code, 'income'=>'0.00', 'expense'=>'0.00', 'net'=>'0.00', 'income_count'=>0, 'expense_count'=>0];
    return $amounts;
}

function cashFlowAmounts(array $row): array
{
    $income = bcadd($row['income'], '0', 2); $expense = bcadd($row['expense'], '0', 2);
    return ['code'=>$row['currency_code'], 'income'=>$income, 'expense'=>$expense, 'net'=>bcsub($income,$expense,2),
        'income_count'=>(int)$row['income_count'], 'expense_count'=>(int)$row['expense_count']];
}

function loadCashFlowSummary(PDO $db, array $range, ?int $accountId = null): array
{
    // Count the full payment exactly once, regardless of its invoice allocations.
    $query = $db->prepare("SELECT currency_code,
        SUM(CASE WHEN direction='inbound' THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS expense,
        SUM(direction='inbound') AS income_count, SUM(direction='outbound') AS expense_count
        FROM accounting_payments WHERE status='settled' AND payment_date BETWEEN ? AND ?".($accountId ? ' AND account_id=?' : '').' GROUP BY currency_code');
    $params = [$range['date_from'], $range['date_to']]; if ($accountId) $params[] = $accountId;
    $query->execute($params);
    $codes = accountingCurrencyCodes(accountingCurrencies($db));
    if ($accountId) {
        $account = $db->prepare('SELECT currency_code FROM accounting_payment_accounts WHERE id=?'); $account->execute([$accountId]);
        if ($currency = $account->fetchColumn()) $codes = [$currency];
    }
    $amounts = cashFlowZeroAmounts($codes);
    $count = 0;
    foreach ($query->fetchAll() as $row) {
        $amounts[$row['currency_code']] = cashFlowAmounts($row);
        $count += (int)$row['income_count'] + (int)$row['expense_count'];
    }
    $updated = new DateTimeImmutable('now', new DateTimeZone('America/Caracas'));
    return ['range'=>$range, 'currencies'=>array_values($amounts), 'count'=>$count, 'updated_at'=>$updated->format(DATE_ATOM), 'updated_time'=>$updated->format('H:i:s')];
}

function loadCashFlowAccounts(PDO $db, array $range): array
{
    $codes = accountingCurrencyCodes(accountingCurrencies($db));
    $accounts = [];
    foreach ($db->query('SELECT * FROM accounting_payment_accounts ORDER BY name,id')->fetchAll() as $account) {
        $account['currencies'] = cashFlowZeroAmounts($account['currency_code'] ? [$account['currency_code']] : $codes);
        $account['count'] = 0; $accounts[(int)$account['id']] = $account;
    }
    $query = $db->prepare("SELECT account_id,currency_code,
        SUM(CASE WHEN direction='inbound' THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS expense,
        SUM(direction='inbound') AS income_count,SUM(direction='outbound') AS expense_count
        FROM accounting_payments WHERE status='settled' AND payment_date BETWEEN ? AND ? GROUP BY account_id,currency_code");
    $query->execute([$range['date_from'],$range['date_to']]);
    foreach ($query->fetchAll() as $row) {
        $accounts[(int)$row['account_id']]['currencies'][$row['currency_code']] = cashFlowAmounts($row);
        $accounts[(int)$row['account_id']]['count'] += (int)$row['income_count']+(int)$row['expense_count'];
    }
    foreach ($accounts as &$account) $account['currencies'] = array_values($account['currencies']);
    unset($account);
    return array_values($accounts);
}

function loadCashFlowHistory(PDO $db, array $range, mixed $requestedPage, ?int $accountId = null): array
{
    $params = [$range['date_from'], $range['date_to']];
    $where = " WHERE p.status='settled' AND p.payment_date BETWEEN ? AND ?";
    if ($accountId) { $where .= ' AND p.account_id=?'; $params[] = $accountId; }
    $query = $db->prepare('SELECT COUNT(*) FROM accounting_payments p'.$where); $query->execute($params);
    $total = (int)$query->fetchColumn(); $pages = max(1, (int)ceil($total/20));
    $page = is_string($requestedPage) || is_int($requestedPage) ? filter_var($requestedPage, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
    $page = min($page ?: 1, $pages);
    $query = $db->prepare('SELECT p.*,c.name AS contact_name,a.name AS account_name,m.name AS method_name
        FROM accounting_payments p JOIN contacts c ON c.id=p.contact_id
        JOIN accounting_payment_accounts a ON a.id=p.account_id JOIN accounting_payment_methods m ON m.id=p.method_id'
        .$where.' ORDER BY p.payment_date DESC,p.id DESC LIMIT 20 OFFSET '.(($page-1)*20));
    $query->execute($params);
    return ['rows'=>$query->fetchAll(), 'total'=>$total, 'pages'=>$pages, 'currentPage'=>$page];
}

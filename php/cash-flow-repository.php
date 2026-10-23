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
    $amounts=[];
    foreach($codes as $code)$amounts[$code]=['code'=>$code,'income'=>'0.00','expense'=>'0.00','net'=>'0.00','income_count'=>0,'expense_count'=>0,
        'balance_start'=>'0.00','balance_end'=>'0.00','transfer_in'=>'0.00','transfer_out'=>'0.00','opening_changes'=>'0.00','methods'=>[]];
    return $amounts;
}
function cashFlowLedgerTotals(PDO $db,array $range,?int $accountId=null): array
{
    $external="category IN ('payment','movement','reversal')";
    $sql="SELECT account_id,currency_code,
        SUM(CASE WHEN $external AND movement_date>=? AND amount>0 THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN $external AND movement_date>=? AND amount<0 THEN -amount ELSE 0 END) AS expense,
        SUM($external AND movement_date>=? AND amount>0) AS income_count,
        SUM($external AND movement_date>=? AND amount<0) AS expense_count,
        SUM(CASE WHEN movement_date<? OR (category='opening' AND movement_date=?) THEN amount ELSE 0 END) AS balance_start,
        SUM(amount) AS balance_end,
        SUM(CASE WHEN category='transfer' AND movement_date>=? AND amount>0 THEN amount ELSE 0 END) AS transfer_in,
        SUM(CASE WHEN category='transfer' AND movement_date>=? AND amount<0 THEN -amount ELSE 0 END) AS transfer_out,
        SUM(CASE WHEN category='opening' AND movement_date>? THEN amount ELSE 0 END) AS opening_changes,
        SUM(movement_date>=?) AS movement_count
        FROM accounting_treasury_entries WHERE movement_date<=?".($accountId?' AND account_id=?':'').' GROUP BY account_id,currency_code';
    $params=array_fill(0,10,$range['date_from']);$params[]=$range['date_to'];if($accountId)$params[]=$accountId;
    $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll();
}
function cashFlowMerge(array &$amounts,array $row): void
{
    $code=$row['currency_code'];$amounts[$code]??=cashFlowZeroAmounts([$code])[$code];
    foreach(['income','expense','balance_start','balance_end','transfer_in','transfer_out','opening_changes'] as $key)$amounts[$code][$key]=bcadd($amounts[$code][$key],$row[$key],2);
    foreach(['income_count','expense_count'] as $key)$amounts[$code][$key]+=(int)$row[$key];
    $amounts[$code]['net']=bcsub($amounts[$code]['income'],$amounts[$code]['expense'],2);
}
/** Same external movements and dates as the income/expense totals, by account and currency. */
function loadCashFlowMethods(PDO $db,array $range,?int $accountId=null): array
{
    $sql="SELECT e.account_id,e.currency_code,e.method_id,COALESCE(m.name,'Sin medio de pago') AS name,
        SUM(CASE WHEN e.amount>0 THEN e.amount ELSE 0 END) AS income,
        SUM(CASE WHEN e.amount<0 THEN -e.amount ELSE 0 END) AS expense
        FROM accounting_treasury_entries e LEFT JOIN accounting_payment_methods m ON m.id=e.method_id
        WHERE e.movement_date BETWEEN ? AND ? AND e.category IN ('payment','movement','reversal')";
    $params=[$range['date_from'],$range['date_to']];
    if($accountId){$sql.=' AND e.account_id=?';$params[]=$accountId;}
    $sql.=' GROUP BY e.account_id,e.currency_code,e.method_id,m.name HAVING SUM(ABS(e.amount))>0 ORDER BY m.name,e.method_id,e.currency_code';
    $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll();
}
function loadCashFlowSummary(PDO $db,array $range,?int $accountId=null): array
{
    $codes=accountingCurrencyCodes(accountingCurrencies($db));
    if($accountId){$q=$db->prepare('SELECT currency_code FROM accounting_payment_accounts WHERE id=?');$q->execute([$accountId]);if($code=$q->fetchColumn())$codes=[$code];}
    $amounts=cashFlowZeroAmounts($codes);$count=0;
    foreach(cashFlowLedgerTotals($db,$range,$accountId) as $row){cashFlowMerge($amounts,$row);$count+=(int)$row['movement_count'];}
    if($accountId)foreach(loadCashFlowMethods($db,$range,$accountId) as $method)$amounts[$method['currency_code']]['methods'][]=$method;
    $updated=new DateTimeImmutable('now',new DateTimeZone('America/Caracas'));
    return ['range'=>$range,'currencies'=>array_values($amounts),'count'=>$count,'updated_at'=>$updated->format(DATE_ATOM),'updated_time'=>$updated->format('H:i:s')];
}
function loadCashFlowAccounts(PDO $db,array $range): array
{
    $codes=accountingCurrencyCodes(accountingCurrencies($db));$accounts=[];
    foreach($db->query('SELECT * FROM accounting_payment_accounts ORDER BY name,id')->fetchAll() as $a){$a['currencies']=cashFlowZeroAmounts($a['currency_code']?[$a['currency_code']]:$codes);$a['count']=0;$accounts[(int)$a['id']]=$a;}
    foreach(cashFlowLedgerTotals($db,$range) as $row){cashFlowMerge($accounts[(int)$row['account_id']]['currencies'],$row);$accounts[(int)$row['account_id']]['count']+=(int)$row['movement_count'];}
    foreach(loadCashFlowMethods($db,$range) as $method)$accounts[(int)$method['account_id']]['currencies'][$method['currency_code']]['methods'][]=$method;
    foreach($accounts as &$a)$a['currencies']=array_values($a['currencies']);unset($a);return array_values($accounts);
}
function loadCashFlowHistory(PDO $db,array $range,mixed $requestedPage,?int $accountId=null): array
{
    $params=[$range['date_from'],$range['date_to']];$where=' WHERE e.movement_date BETWEEN ? AND ?';
    if($accountId){$where.=' AND e.account_id=?';$params[]=$accountId;}
    $q=$db->prepare('SELECT COUNT(*) FROM accounting_treasury_entries e'.$where);$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/20));
    $page=is_string($requestedPage)||is_int($requestedPage)?filter_var($requestedPage,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;$page=min($page?:1,$pages);
    $q=$db->prepare("SELECT e.*,e.movement_date AS payment_date,ABS(e.amount) AS display_amount,IF(e.amount>=0,'inbound','outbound') AS direction,
        p.partner_type,COALESCE(p.name,d.name) AS name,c.name AS contact_name,a.name AS account_name,m.name AS method_name,k.name AS concept_name,
        'settled' AS status FROM accounting_treasury_entries e JOIN accounting_payment_accounts a ON a.id=e.account_id
        LEFT JOIN accounting_payments p ON p.id=e.payment_id LEFT JOIN accounting_treasury_documents d ON d.id=e.document_id
        LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN accounting_payment_methods m ON m.id=e.method_id
        LEFT JOIN accounting_cash_concepts k ON k.id=e.concept_id".$where.' ORDER BY e.movement_date DESC,e.id DESC LIMIT 20 OFFSET '.(($page-1)*20));$q->execute($params);
    return ['rows'=>$q->fetchAll(),'total'=>$total,'pages'=>$pages,'currentPage'=>$page];
}

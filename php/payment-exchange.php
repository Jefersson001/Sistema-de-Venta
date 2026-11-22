<?php
declare(strict_types=1);
require_once __DIR__.'/currency-repository.php';

function invoiceHasUsdDebt(array $invoice): bool {
    return ($invoice['currency_code']??null)==='VES' && isset($invoice['usd_reference_amount']) && bccomp($invoice['usd_reference_amount'],'0',2)>0;
}

/** Proportions keep the original VES document intact, including final-cent rounding. */
function paymentProportion(string $part,string $whole,string $total): string {
    if(bccomp($whole,'0',2)<=0)return '0.00';
    return decimalRound(bcdiv(bcmul($part,$total,12),$whole,12));
}

function invoiceFreezeUsdDebt(PDO $db,array $invoice): void {
    $reference=null;
    if($invoice['currency_code']==='VES' && !empty($invoice['exchange_rate'])){
        $pair=['primary_code'=>$invoice['currency_primary_code'],'alternate_code'=>$invoice['currency_alternate_code'],'exchange_rate'=>$invoice['exchange_rate']];
        if(in_array('USD',accountingCurrencyCodes($pair),true)){
            $reference=accountingConvert($invoice['total_amount'],'VES','USD',$pair);
            if(!empty($invoice['reversal_of_id'])){
                $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice['reversal_of_id']]);$original=$q->fetch();
                if($original&&invoiceHasUsdDebt($original))$reference=paymentProportion($invoice['total_amount'],$original['total_amount'],$original['usd_reference_amount']);
            }
        }
    }
    $db->prepare('UPDATE accounting_invoices SET usd_reference_amount=? WHERE id=?')->execute([$reference,$invoice['id']]);
}

/** Debt is expressed in USD for indexed VES invoices; historical native amounts remain available. */
function paymentDebtPosition(PDO $db,array $invoice,?array $details=null): array {
    $details??=invoicePaymentDetails($db,(int)$invoice['id']);
    $nativeTotal=paymentInvoiceAmount($db,$invoice);$indexed=invoiceHasUsdDebt($invoice);
    $total=$indexed?paymentProportion($nativeTotal,$invoice['total_amount'],$invoice['usd_reference_amount']):$nativeTotal;
    $settled='0.00';$pending='0.00';
    foreach($details['payments'] as $row){
        if(!in_array($row['status'],['pending','settled'],true))continue;
        $part=$indexed?($row['usd_reference_amount']??paymentProportion($row['amount'],$invoice['total_amount'],$invoice['usd_reference_amount'])):$row['amount'];
        if($row['status']==='settled')$settled=bcadd($settled,$part,2);else $pending=bcadd($pending,$part,2);
    }
    return ['indexed'=>$indexed,'currency'=>$indexed?'USD':$invoice['currency_code'],'total'=>$total,'settled'=>$settled,'pending'=>$pending,
        'unpaid'=>bcsub($total,$settled,2),'balance'=>bcsub(bcsub($total,$settled,2),$pending,2)];
}

function paymentApplicableToInvoice(PDO $db,string $available,array $invoice,array $payment,?array $details=null): string {
    $debt=paymentDebtPosition($db,$invoice,$details);
    return paymentApplicableAmount($available,$debt['balance'],$payment['currency_code'],$debt['currency'],$payment);
}

/** Operational comparison with the invoice snapshot, not a general-ledger posting. */
function paymentExchangeSnapshot(array $invoice,array $payment,string $invoiceAmount,string $paymentAmount): array {
    $empty=array_fill_keys(['fx_currency_code','fx_invoice_rate','fx_payment_rate','fx_historical_amount','fx_settlement_amount','fx_difference'],null);
    $from=$invoice['currency_code'];$to=$payment['currency_code'];
    $historical=['primary_code'=>$invoice['currency_primary_code'],'alternate_code'=>$invoice['currency_alternate_code'],'exchange_rate'=>$invoice['exchange_rate']];
    $pairRate=static function(string $from,string $to,array $pair):?string {
        if(empty($pair['exchange_rate'])||bccomp((string)$pair['exchange_rate'],'0',10)<=0)return null;
        if($from===$pair['primary_code']&&$to===$pair['alternate_code'])return bcadd((string)$pair['exchange_rate'],'0',10);
        if($from===$pair['alternate_code']&&$to===$pair['primary_code'])return bcdiv('1',(string)$pair['exchange_rate'],10);
        return null;
    };
    if(invoiceHasUsdDebt($invoice)){
        $invoiceRate=$pairRate('USD','VES',$historical);$paymentRate=$pairRate('USD','VES',$payment);
        if($invoiceRate===null||$paymentRate===null)return $empty;
        $actual=accountingConvert($paymentAmount,$to,'VES',$payment);
        return ['fx_currency_code'=>'VES','fx_invoice_rate'=>$invoiceRate,'fx_payment_rate'=>$paymentRate,'fx_historical_amount'=>$invoiceAmount,
            'fx_settlement_amount'=>$actual,'fx_difference'=>bcsub($actual,$invoiceAmount,2)];
    }
    if($from===$to)return $empty;
    $invoiceRate=$pairRate($from,$to,$historical);$paymentRate=$pairRate($from,$to,$payment);if($invoiceRate===null||$paymentRate===null)return $empty;
    $original=accountingConvert($invoiceAmount,$from,$to,$historical);
    return ['fx_currency_code'=>$to,'fx_invoice_rate'=>$invoiceRate,'fx_payment_rate'=>$paymentRate,'fx_historical_amount'=>$original,
        'fx_settlement_amount'=>bcadd($paymentAmount,'0',2),'fx_difference'=>bcsub($paymentAmount,$original,2)];
}

function paymentStoreExchangeSnapshot(PDO $db,int $allocation,array $snapshot): void {
    $columns=array_keys($snapshot);$db->prepare('UPDATE accounting_invoice_payments SET '.implode(',',array_map(static fn($key)=>$key.'=?',$columns)).' WHERE id=?')->execute([...array_values($snapshot),$allocation]);
}

/** Totals include confirmed allocations only; pending/cancelled amounts remain separate. */
function paymentExchangeTotals(array $allocations): array {
    $totals=[];foreach($allocations as $row){if(empty($row['fx_currency_code'])||$row['fx_difference']===null||$row['status']!=='settled')continue;$code=$row['fx_currency_code'];$totals[$code]??='0.00';$totals[$code]=bcadd($totals[$code],$row['fx_difference'],2);}return $totals;
}

function renderPaymentRateLookup(PDO $db,array $pair): void {
    authPrivacyHeaders();header('Content-Type: application/json; charset=UTF-8');
    $date=$_GET['payment_rate_date']??'';$parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
    if(!$parsed||$parsed->format('Y-m-d')!==$date){http_response_code(422);echo json_encode(['error'=>'Selecciona una fecha de pago válida.']);return;}
    $from=$pair['primary_code'];$to=$pair['alternate_code'];$snapshot=currencyRateSnapshot($db,$from,$to,$date,companyActiveId());
    echo json_encode(['exchange_rate'=>$snapshot['rate']??null,'rate_date'=>$snapshot['rate_date']??null,'source'=>$snapshot['source']??null,'primary_code'=>$from,'alternate_code'=>$to,'scope'=>companyScopeToken()],JSON_THROW_ON_ERROR);
}

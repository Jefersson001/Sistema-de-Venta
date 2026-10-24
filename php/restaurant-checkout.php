<?php
declare(strict_types=1);

function restaurantCheckoutInput(array $input): array
{
    if(array_key_exists('payments_json',$input)){
        if(isset($input['payments'])||!is_string($input['payments_json'])||strlen($input['payments_json'])>1000000)throw new InvalidArgumentException('El reparto de pagos no es válido.');
        try{$rows=json_decode($input['payments_json'],true,16,JSON_THROW_ON_ERROR);}
        catch(JsonException){throw new InvalidArgumentException('No se pudo leer el reparto de pagos.');}
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>20)throw new InvalidArgumentException('Registra entre 1 y 20 pagos.');
        $input['payments']=$rows;unset($input['payments_json']);
    }
    return $input;
}

/** Normalize the division in the server; money and quantities never come from browser totals. */
function restaurantPaymentPlan(array $cart,array $input): array
{
    $mode=$input['split_mode']??'full';
    if(!in_array($mode,['full','amount','products'],true))throw new InvalidArgumentException('Selecciona cómo repartir la cuenta.');
    $rows=$input['payments']??[['method_id'=>$input['method_id']??null,'account_id'=>$input['account_id']??null,
        'amount'=>$cart['total'],'received_amount'=>$input['received_amount']??$cart['total']]];
    if(!is_array($rows)||count($rows)<1||count($rows)>20)throw new InvalidArgumentException('Registra entre 1 y 20 pagos.');
    $items=array_column($cart['items'],null,'id');$quantities=array_fill_keys(array_keys($items),'0.000');$plan=[];$sum='0.00';
    foreach(array_values($rows) as $row){
        if(!is_array($row))throw new InvalidArgumentException('Revisa los datos de cada pago.');
        $amount=salesDecimal($row['amount']??'',16,2);$picked=[];
        if($mode==='products'){
            $selection=$row['items']??[];
            if(!is_array($selection)||count($selection)>100)throw new InvalidArgumentException('Revisa los productos de cada persona.');
            $amount='0.00';
            foreach($selection as $itemId=>$value){
                if(!isset($items[$itemId]))throw new InvalidArgumentException('Uno de los productos ya no pertenece a esta mesa.');
                $qty=salesDecimal($value,9,3);
                if($qty===null||bccomp($qty,'0',3)<0)throw new InvalidArgumentException('Las cantidades a repartir deben ser positivas o cero.');
                if(bccomp($qty,'0',3)===0)continue;
                $item=$items[$itemId];$previous=$quantities[$itemId];$next=bcadd($previous,$qty,3);
                if(bccomp($next,$item['quantity'],3)>0)throw new InvalidArgumentException('Se repartieron más unidades de '.$item['product_name'].' que las cargadas.');
                // Cumulative rounding assigns every cent exactly once, even for fractional quantities.
                $before=commerceAmounts($previous,$item['unit_price'],$item['tax_rate']);
                $after=commerceAmounts($next,$item['unit_price'],$item['tax_rate']);
                $amount=bcadd($amount,bcsub($after['total_amount'],$before['total_amount'],2),2);
                $quantities[$itemId]=$next;$picked[$itemId]=$qty;
            }
        }
        if($amount===null||bccomp($amount,'0',2)<0)throw new InvalidArgumentException('Indica un importe válido en cada pago.');
        $received=$row['received_amount']??'';
        $received=$received===''?$amount:salesDecimal($received,16,2);
        if($received===null||bccomp($received,$amount,2)<0)throw new InvalidArgumentException('El importe recibido no puede ser menor que la parte a pagar.');
        if(bccomp($amount,'0',2)===0){
            if(bccomp($cart['total'],'0',2)!==0||bccomp($received,'0',2)!==0)throw new InvalidArgumentException('Cada pago debe cubrir un importe mayor que cero.');
            continue;
        }
        $plan[]=['method_id'=>restaurantInt($row['method_id']??null),'account_id'=>restaurantInt($row['account_id']??null),
            'amount'=>$amount,'received_amount'=>$received,'change_amount'=>bcsub($received,$amount,2),
            'label'=>restaurantText($row['label']??'',80,false),'items'=>$picked];
        $sum=bcadd($sum,$amount,2);
    }
    if($mode==='products')foreach($items as $itemId=>$item)if(bccomp($quantities[$itemId],$item['quantity'],3)!==0)throw new InvalidArgumentException('Falta repartir todos los productos de la mesa.');
    if(bccomp($sum,$cart['total'],2)!==0)throw new InvalidArgumentException('La suma de los pagos debe coincidir con el total de la mesa.');
    return $plan;
}

function restaurantCollectPayments(PDO $db,int $accountId,array $account,int $invoice,array $cart,int $customer,array $input): array
{
    $plan=restaurantPaymentPlan($cart,$input);$paymentIds=[];
    // Always lock cash accounts in ascending order before creating payments.
    $accounts=array_values(array_unique(array_column($plan,'account_id')));sort($accounts,SORT_NUMERIC);
    if($accounts)treasuryLockAccounts($db,$accounts);
    foreach($plan as $index=>$row){
        $payment=validateAccountingPayment($db,['partner_type'=>'customer','direction'=>'inbound','contact_id'=>$customer,
            'amount'=>$row['amount'],'received_amount'=>$row['received_amount'],'change_amount'=>$row['change_amount'],
            'payment_date'=>commerceToday(),'currency_code'=>$cart['currency'],'account_id'=>$row['account_id'],'method_id'=>$row['method_id'],
            'reference'=>$account['name'].($row['label']!==''?' · '.$row['label']:'')]);
        $paymentId=insertAccountingPayment($db,$payment,'pending',hash('sha256','restaurant:'.$accountId.':'.$invoice.':'.$index));
        $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);
        paymentApplyLocked($db,loadAccountingPayment($db,$paymentId),$q->fetch(),$row['amount']);
        transitionAccountingPayment($db,$paymentId,1,'settle_payment');$paymentIds[]=$paymentId;
    }
    return ['split_mode'=>$input['split_mode']??'full','payments'=>$plan,'payment_ids'=>$paymentIds];
}

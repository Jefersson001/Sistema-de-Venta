<?php
declare(strict_types=1);

function sellerCommissionBalances(PDO $db,int $sellerId): array
{
    $groups=[];
    foreach(loadSalesCommissions($db,null,$sellerId) as $row){
        $code=$row['currency_code'];$groups[$code]??=['currency_code'=>$code,'generated'=>'0.00','paid'=>'0.00','pending'=>'0.00','advance'=>'0.00','first_date'=>$row['invoice_date']];
        $groups[$code]['generated']=bcadd($groups[$code]['generated'],$row['status']==='posted'?$row['commission_generated']:'0.00',2);
        $groups[$code]['paid']=bcadd($groups[$code]['paid'],$row['commission_paid'],2);
        $groups[$code]['advance']=bcadd($groups[$code]['advance'],$row['commission_advance'],2);
    }
    foreach($groups as &$group){$net=bcsub($group['generated'],$group['paid'],2);$group['pending']=bccomp($net,'0',2)>0?$net:'0.00';$group['recoverable']=bccomp($net,'0',2)<0?bcsub('0',$net,2):'0.00';}unset($group);ksort($groups);return $groups;
}

/** One real cash movement, distributed oldest first, never beyond net generated commissions. */
function paySellerCommission(PDO $db,int $sellerId,string $commissionCurrency,array $input,string $creationKey,?int $invoiceId=null): int
{
    workflowBegin($db);
    try{
        if(!preg_match('/^[a-f0-9]{32,64}$/D',$creationKey))throw new InvalidArgumentException('La solicitud de pago no es válida.');
        if(permissionRuntime()){
            permissionRequire('ventas.commissions','pay',$invoiceId);
            permissionRequire('contabilidad.expense','create');
            permissionRequire('contabilidad.expense','confirm');
            if(permissionScope('ventas.commissions','pay')==='own'&&!in_array($sellerId,permissionContacts(),true))throw new InvalidArgumentException('No puedes pagar a este vendedor.',403);
        }
        $sellerSql='SELECT id FROM contacts WHERE id=?'.($db instanceof CompanyConnection?' AND company_id='.(int)$db->activeCompanyId():'').' FOR UPDATE';
        $q=$db->prepare($sellerSql);$q->execute([$sellerId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona un vendedor de la empresa activa.');
        $q=$db->prepare('SELECT * FROM accounting_treasury_documents WHERE creation_key=?');$q->execute([$creationKey]);
        if($old=$q->fetch()){
            $parts=commissionPaymentAllocations($db,(int)$old['id']);$total='0.00';foreach($parts as $part)$total=bcadd($total,$part['amount'],2);
            $requested=salesDecimal($input['amount']??null,16,2);
            $sameScope=$invoiceId?count($parts)===1&&(int)$parts[0]['invoice_id']===$invoiceId&&$parts[0]['payment_scope']==='invoice':($parts[0]['payment_scope']??null)==='seller';
            if(!$sameScope||$old['currency_code']!==($input['currency_code']??$commissionCurrency)
                ||(isset($input['exchange_rate'])&&bccomp($old['exchange_rate'],accountingExchangeRate($input['exchange_rate']),8)!==0)
                ||$old['reference']!==(is_string($input['reference']??null)?trim($input['reference']):''))throw new InvalidArgumentException('Esta solicitud ya se utilizó con otros datos.');
            if((int)$old['contact_id']!==$sellerId||!$parts||$parts[0]['currency_code']!==$commissionCurrency||$requested===null||bccomp($requested,$total,2)!==0||$old['movement_date']!==($input['movement_date']??null)||(int)$old['account_id']!==(int)($input['account_id']??0)||(int)$old['method_id']!==(int)($input['method_id']??0))throw new InvalidArgumentException('Esta solicitud ya se utilizó con otros datos.');
            $db->commit();return(int)$old['id'];
        }
        $rows=loadSalesCommissions($db,null,$sellerId);$ids=array_column($rows,'id');sort($ids,SORT_NUMERIC);
        foreach($ids as $id){$q=$db->prepare('SELECT id FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$id]);}
        $rows=loadSalesCommissions($db,null,$sellerId);$balance=sellerCommissionBalances($db,$sellerId)[$commissionCurrency]??null;
        $amount=salesDecimal($input['amount']??null,16,2);
        if(!$balance||$amount===null||bccomp($amount,'0',2)<=0||bccomp($amount,$balance['pending'],2)>0)throw new InvalidArgumentException('El importe no puede superar el saldo acumulado generado: '.($balance['pending']??'0.00').' '.$commissionCurrency.'.');
        $date=treasuryDate($input['movement_date']??null);if($date>commerceToday())throw new InvalidArgumentException('La fecha del pago no puede ser futura.');
        $remaining=$amount;$allocations=[];
        foreach($rows as $row){
            if($row['currency_code']!==$commissionCurrency||$row['status']!=='posted'||$row['invoice_date']>$date||($invoiceId&&(int)$row['id']!==$invoiceId)||bccomp($row['commission_pending'],'0',2)<=0)continue;
            $part=bccomp($row['commission_pending'],$remaining,2)<0?$row['commission_pending']:$remaining;
            $allocations[]=['invoice_id'=>(int)$row['id'],'amount'=>$part];$remaining=bcsub($remaining,$part,2);if(bccomp($remaining,'0',2)===0)break;
        }
        if(bccomp($remaining,'0',2)>0)throw new InvalidArgumentException('El importe supera la comisión disponible en la fecha o factura seleccionada.');
        $settings=accountingCurrencies($db,$date);$settings['exchange_rate']=accountingExchangeRate($input['exchange_rate']??$settings['exchange_rate']);
        $currency=$input['currency_code']??$commissionCurrency;if(!is_string($currency))throw new InvalidArgumentException('Selecciona una moneda válida.');
        $money=accountingConvert($amount,$commissionCurrency,$currency,$settings);
        if(salesDecimal($money,16,2)===null||bccomp($money,'0',2)<=0)throw new InvalidArgumentException('El importe convertido no es válido.');
        $concept=$db->query("SELECT id FROM accounting_cash_concepts WHERE system_key='sales_commission'")->fetchColumn();
        $reference=is_string($input['reference']??null)?trim($input['reference']):'';
        $data=validateTreasuryDocument($db,['kind'=>'expense','account_id'=>$input['account_id']??null,'currency_code'=>$currency,'amount'=>$money,'movement_date'=>$date,'exchange_rate'=>$settings['exchange_rate'],
            'concept_id'=>(string)$concept,'contact_id'=>(string)$sellerId,'method_id'=>$input['method_id']??null,'reference'=>$reference],null,true);
        if(bccomp(treasuryBalance($db,(int)$data['account_id'],$currency,$date),$money,2)<0)throw new InvalidArgumentException('El saldo de la cuenta de egreso no alcanza para este pago.');
        $id=treasuryInsertDocument($db,$data+['status'=>'done','creation_key'=>$creationKey]);
        treasuryEntry($db,['account_id'=>$data['account_id'],'currency_code'=>$currency,'movement_date'=>$date,'amount'=>bcsub('0',$money,2),'category'=>'movement','document_id'=>$id,'concept_id'=>$data['concept_id'],'contact_id'=>$sellerId,'method_id'=>$data['method_id']]);
        $q=$db->prepare('INSERT INTO sales_commission_payments(invoice_id,treasury_document_id,amount,payment_scope) VALUES(?,?,?,?)');
        foreach($allocations as $allocation)$q->execute([$allocation['invoice_id'],$id,$allocation['amount'],$invoiceId?'invoice':'seller']);
        paymentProofBatch($input)?->attach($db,'treasury',$id);$db->commit();paymentProofBatch($input)?->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function commissionPaymentAllocations(PDO $db,int $document): array
{
    $q=$db->prepare('SELECT p.*,i.name AS invoice_name,i.currency_code FROM sales_commission_payments p JOIN accounting_invoices i ON i.id=p.invoice_id WHERE p.treasury_document_id=? ORDER BY p.id');$q->execute([$document]);return $q->fetchAll();
}

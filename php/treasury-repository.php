<?php
declare(strict_types=1);
require_once __DIR__.'/sales-repository.php';
require_once __DIR__.'/currency-repository.php';

function treasuryKinds(): array {return ['opening'=>'Saldo inicial','transfer'=>'Transferencia interna','income'=>'Ingreso','expense'=>'Egreso','reversal'=>'Reversión','payment_reversal'=>'Reversión de pago'];}
function treasuryStatuses(): array {return ['draft'=>'Borrador','done'=>'Confirmado','cancel'=>'Cancelado'];}
function treasuryDate(mixed $value): string
{
    $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
    if(!$date||$date->format('Y-m-d')!==$value||$value<'1000-01-01'||$value>'9999-12-31')throw new InvalidArgumentException('Selecciona una fecha válida.');
    return $value;
}
function treasuryConcepts(PDO $db): array {return $db->query('SELECT * FROM accounting_cash_concepts ORDER BY name,id')->fetchAll();}
function treasuryConcept(PDO $db,?int $id,string $direction,?int $historical=null): int
{
    $q=$db->prepare('SELECT * FROM accounting_cash_concepts WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch();
    if(!$c||(!$c['active']&&$id!==$historical)||!in_array($c['direction'],[$direction,'both'],true))throw new InvalidArgumentException('Selecciona un concepto activo que corresponda al ingreso o egreso.');
    return (int)$c['id'];
}
function treasuryDefaultConcept(PDO $db,string $partner,string $direction): int
{
    $key=$partner==='customer'?($direction==='inbound'?'customer_payment':'customer_refund'):($direction==='outbound'?'supplier_payment':'supplier_refund');
    $q=$db->prepare('SELECT id FROM accounting_cash_concepts WHERE system_key=?');$q->execute([$key]);return (int)$q->fetchColumn();
}
function treasuryLockAccounts(PDO $db,array $ids): array
{
    $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);$accounts=[];
    foreach($ids as $id){$q=$db->prepare('SELECT * FROM accounting_payment_accounts WHERE id=? FOR UPDATE');$q->execute([$id]);$a=$q->fetch();if(!$a)throw new InvalidArgumentException('La cuenta seleccionada no existe.');$accounts[$id]=$a;}
    return $accounts;
}
function treasuryCheckCurrency(array $account,string $currency): void
{
    if($account['currency_code']&&$account['currency_code']!==$currency)throw new InvalidArgumentException('La cuenta seleccionada debe admitir la moneda del movimiento.');
}
function treasuryBalance(PDO $db,int $account,string $currency,string $date): string
{
    $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM accounting_treasury_entries WHERE account_id=? AND currency_code=? AND movement_date<=?');$q->execute([$account,$currency,$date]);return bcadd((string)$q->fetchColumn(),'0',2);
}
function treasuryEntry(PDO $db,array $data): void
{
    $db->prepare('INSERT INTO accounting_treasury_entries(account_id,currency_code,movement_date,amount,category,payment_id,document_id,concept_id,contact_id,method_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$data['account_id'],$data['currency_code'],$data['movement_date'],$data['amount'],$data['category'],$data['payment_id']??null,$data['document_id']??null,$data['concept_id']??null,$data['contact_id']??null,$data['method_id']??null]);
}
function treasuryPostPayment(PDO $db,array $payment): void
{
    $q=$db->prepare('SELECT * FROM accounting_payments WHERE id=?');$q->execute([$payment['id']]);$payment=$q->fetch();
    if(!$payment)throw new InvalidArgumentException('El pago no existe.');
    $accounts=treasuryLockAccounts($db,[$payment['account_id']]);
    $q=$db->prepare('SELECT id FROM accounting_treasury_entries WHERE payment_id=?');$q->execute([$payment['id']]);if($q->fetchColumn())return;
    if($payment['status']!=='settled')throw new InvalidArgumentException('Solo los pagos confirmados generan movimientos de dinero.');
    treasuryCheckCurrency($accounts[(int)$payment['account_id']],$payment['currency_code']);
    treasuryEntry($db,['account_id'=>$payment['account_id'],'currency_code'=>$payment['currency_code'],'movement_date'=>$payment['payment_date'],
        'amount'=>$payment['direction']==='inbound'?$payment['amount']:bcsub('0',$payment['amount'],2),'category'=>'payment','payment_id'=>$payment['id'],
        'concept_id'=>$payment['concept_id']??null,'contact_id'=>$payment['contact_id'],'method_id'=>$payment['method_id']]);
}
function loadTreasuryDocument(PDO $db,int $id): ?array
{
    $q=$db->prepare('SELECT d.*,a.name AS account_name,b.name AS destination_name,c.name AS concept_name,p.name AS contact_name,m.name AS method_name,
        r.id AS reversed_by_id,r.name AS reversal_name FROM accounting_treasury_documents d
        JOIN accounting_payment_accounts a ON a.id=d.account_id LEFT JOIN accounting_payment_accounts b ON b.id=d.destination_account_id
        LEFT JOIN accounting_cash_concepts c ON c.id=d.concept_id LEFT JOIN contacts p ON p.id=d.contact_id
        LEFT JOIN accounting_payment_methods m ON m.id=d.method_id LEFT JOIN accounting_treasury_documents r ON r.reversal_of_id=d.id WHERE d.id=?');$q->execute([$id]);return $q->fetch()?:null;
}
function treasuryInsertDocument(PDO $db,array $data): int
{
    $db->prepare('INSERT INTO accounting_treasury_documents('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')')->execute($data);
    $id=(int)$db->lastInsertId();$db->prepare('UPDATE accounting_treasury_documents SET name=? WHERE id=?')->execute(['TES/'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);return $id;
}
function validateTreasuryDocument(PDO $db,array $input,?array $existing=null,bool $commissionPayment=false): array
{
    $kind=$input['kind']??'';if(!in_array($kind,['opening','transfer','income','expense'],true))throw new InvalidArgumentException('Selecciona un tipo de movimiento válido.');
    $account=salesId($input['account_id']??null);$destination=$kind==='transfer'?salesId($input['destination_account_id']??null):null;
    if(!$account||($kind==='transfer'&&(!$destination||$destination===$account)))throw new InvalidArgumentException('Selecciona cuentas distintas de origen y destino para transferir.');
    $accounts=treasuryLockAccounts($db,array_filter([$account,$destination]));
    $settings=$existing?['primary_code'=>$existing['primary_code'],'alternate_code'=>$existing['alternate_code'],'exchange_rate'=>$existing['exchange_rate']]:accountingCurrencies($db);
    $currency=$input['currency_code']??$settings['primary_code'];$destCurrency=$kind==='transfer'?($input['destination_currency_code']??$currency):null;
    $codes=array_unique(array_merge(accountingCurrencyCodes($settings),[$existing['currency_code']??null,$existing['destination_currency_code']??null]));
    if(!is_string($currency)||!in_array($currency,$codes,true)||($destCurrency!==null&&(!is_string($destCurrency)||!in_array($destCurrency,$codes,true))))throw new InvalidArgumentException('Selecciona una moneda configurada.');
    treasuryCheckCurrency($accounts[$account],$currency);if($destination)treasuryCheckCurrency($accounts[$destination],$destCurrency);
    $raw=is_string($input['amount']??null)?trim($input['amount']):'';$negative=$kind==='opening'&&str_starts_with($raw,'-');
    $amount=salesDecimal($negative?substr($raw,1):$raw,16,2);
    if($amount===null||($kind!=='opening'&&bccomp($amount,'0',2)<=0))throw new InvalidArgumentException('Indica un importe válido con hasta dos decimales.');
    if($negative)$amount=bcsub('0',$amount,2);
    $rate=accountingExchangeRate($input['exchange_rate']??$settings['exchange_rate']);$settings['exchange_rate']=$rate;
    $received=$kind==='transfer'?accountingConvert($amount,$currency,$destCurrency,$settings):null;
    if($received!==null&&bccomp($received,'0',2)<=0)throw new InvalidArgumentException('El importe convertido debe ser mayor que cero.');
    if($received!==null&&salesDecimal($received,16,2)===null)throw new InvalidArgumentException('El importe convertido supera el máximo permitido.');
    $method=$kind==='opening'?null:salesId($input['method_id']??null);
    if($kind!=='opening'){$q=$db->prepare('SELECT id FROM accounting_payment_methods WHERE id=?');$q->execute([$method]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona un medio de pago válido.');}
    $concept=in_array($kind,['income','expense'],true)?treasuryConcept($db,salesId($input['concept_id']??null),$kind==='income'?'inbound':'outbound',salesId($existing['concept_id']??null)):null;
    if($concept&&!$commissionPayment){$q=$db->prepare('SELECT system_key FROM accounting_cash_concepts WHERE id=?');$q->execute([$concept]);if($q->fetchColumn()==='sales_commission')throw new InvalidArgumentException('Registra el pago desde Ventas → Comisiones para aplicarlo al vendedor y a su factura.');}
    $contact=salesId($input['contact_id']??null);
    if(isset($input['contact_id'])&&$input['contact_id']!==''&&!$contact)throw new InvalidArgumentException('Selecciona un contacto válido o deja el campo vacío.');
    if($contact){$q=$db->prepare('SELECT id FROM contacts WHERE id=?');$q->execute([$contact]);if(!$q->fetchColumn())throw new InvalidArgumentException('El contacto no existe.');}
    $reference=is_string($input['reference']??null)?trim($input['reference']):'';if(mb_strlen($reference)>255)throw new InvalidArgumentException('La referencia admite hasta 255 caracteres.');
    return ['kind'=>$kind,'account_id'=>$account,'destination_account_id'=>$destination,'currency_code'=>$currency,'destination_currency_code'=>$destCurrency,
        'amount'=>$amount,'destination_amount'=>$received,'primary_code'=>$settings['primary_code'],'alternate_code'=>$settings['alternate_code'],'exchange_rate'=>$rate,
        'movement_date'=>treasuryDate($input['movement_date']??null),'concept_id'=>$concept,'contact_id'=>$contact,'method_id'=>$method,'reference'=>$reference];
}
function saveTreasuryDocument(PDO $db,array $input,?int $id,?int $version,string $key): int
{
    workflowBegin($db);
    try{
        $existing=null;
        if($id){$q=$db->prepare('SELECT * FROM accounting_treasury_documents WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetch();
            if(!$existing||$existing['status']!=='draft'||(int)$existing['version']!==$version)throw new InvalidArgumentException('El documento cambió o ya está confirmado. Recarga antes de guardar.');}
        else{$q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE creation_key=?');$q->execute([$key]);if($old=$q->fetchColumn()){$db->commit();return(int)$old;}}
        $data=validateTreasuryDocument($db,$input,$existing);
        if($id)$db->prepare('UPDATE accounting_treasury_documents SET '.implode(',',array_map(static fn($k)=>$k.'=:'.$k,array_keys($data))).',version=version+1 WHERE id=:id')->execute($data+['id'=>$id]);
        else $id=treasuryInsertDocument($db,$data+['creation_key'=>$key]);
        $db->commit();return $id;
    }catch(Throwable $e){
        $db->rollBack();
        if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062&&$key!==''){$q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE creation_key=?');$q->execute([$key]);if($old=$q->fetchColumn())return(int)$old;}
        throw $e;
    }
}
function confirmTreasuryDocument(PDO $db,int $id,?int $version): void
{
    workflowBegin($db);
    try{
        $q=$db->prepare('SELECT * FROM accounting_treasury_documents WHERE id=? FOR UPDATE');$q->execute([$id]);$d=$q->fetch();
        if(!$d||$d['status']!=='draft'||(int)$d['version']!==$version)throw new InvalidArgumentException('El documento cambió o ya fue confirmado.');
        $data=validateTreasuryDocument($db,$d,$d);
        if($d['kind']==='opening'){
            $q=$db->prepare("SELECT id FROM accounting_treasury_documents d WHERE kind='opening' AND status='done' AND account_id=? AND currency_code=? AND NOT EXISTS(SELECT 1 FROM accounting_treasury_documents r WHERE r.reversal_of_id=d.id)");$q->execute([$d['account_id'],$d['currency_code']]);
            if($q->fetchColumn())throw new InvalidArgumentException('La cuenta ya tiene saldo inicial en esta moneda. Revierte el anterior para corregirlo.');
            $q=$db->prepare("SELECT MIN(movement_date) FROM accounting_treasury_entries WHERE account_id=? AND currency_code=? AND category<>'opening'");$q->execute([$d['account_id'],$d['currency_code']]);$first=$q->fetchColumn();
            if($first&&$d['movement_date']>$first)throw new InvalidArgumentException('El saldo inicial debe fecharse antes o el mismo día del primer movimiento de esa cuenta y moneda.');
        }
        if($d['kind']==='transfer'&&bccomp(treasuryBalance($db,(int)$d['account_id'],$d['currency_code'],$d['movement_date']),$d['amount'],2)<0)throw new InvalidArgumentException('El saldo de la cuenta de origen no alcanza para esta transferencia en la fecha seleccionada.');
        $base=['account_id'=>$d['account_id'],'currency_code'=>$d['currency_code'],'movement_date'=>$d['movement_date'],'document_id'=>$id,'concept_id'=>$d['concept_id'],'contact_id'=>$d['contact_id'],'method_id'=>$d['method_id']];
        treasuryEntry($db,$base+['amount'=>in_array($d['kind'],['expense','transfer'],true)?bcsub('0',$d['amount'],2):$d['amount'],'category'=>$d['kind']==='opening'?'opening':($d['kind']==='transfer'?'transfer':'movement')]);
        if($d['kind']==='transfer')treasuryEntry($db,array_replace($base,['account_id'=>$d['destination_account_id'],'currency_code'=>$d['destination_currency_code'],'amount'=>$data['destination_amount'],'category'=>'transfer']));
        $db->prepare("UPDATE accounting_treasury_documents SET status='done',version=version+1 WHERE id=?")->execute([$id]);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
function cancelTreasuryDocument(PDO $db,int $id,?int $version): void
{
    $q=$db->prepare("UPDATE accounting_treasury_documents SET status='cancel',version=version+1 WHERE id=? AND version=? AND status='draft'");$q->execute([$id,$version]);if(!$q->rowCount())throw new InvalidArgumentException('Solo puedes cancelar un borrador vigente.');
}
function reverseTreasuryDocument(PDO $db,int $id,?int $version,array $input): int
{
    workflowBegin($db);
    try{
        $q=$db->prepare('SELECT * FROM accounting_treasury_documents WHERE id=? FOR UPDATE');$q->execute([$id]);$d=$q->fetch();
        if(!$d||$d['status']!=='done'||(int)$d['version']!==$version||in_array($d['kind'],['reversal','payment_reversal'],true))throw new InvalidArgumentException('Solo puedes revertir un movimiento confirmado.');
        $q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE reversal_of_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('El movimiento ya fue revertido.');
        $reason=is_string($input['reason']??null)?trim($input['reason']):'';if($reason===''||mb_strlen($reason)>255)throw new InvalidArgumentException('Indica el motivo de la reversión (hasta 255 caracteres).');
        $date=$d['kind']==='opening'?$d['movement_date']:treasuryDate($input['movement_date']??null);if($date<$d['movement_date'])throw new InvalidArgumentException('La reversión no puede ser anterior al movimiento.');
        $q=$db->prepare('SELECT * FROM accounting_treasury_entries WHERE document_id=? ORDER BY account_id');$q->execute([$id]);$entries=$q->fetchAll();treasuryLockAccounts($db,array_column($entries,'account_id'));
        $new=treasuryInsertDocument($db,['kind'=>'reversal','status'=>'done','account_id'=>$d['account_id'],'destination_account_id'=>$d['destination_account_id'],
            'currency_code'=>$d['currency_code'],'destination_currency_code'=>$d['destination_currency_code'],'amount'=>$d['amount'],'destination_amount'=>$d['destination_amount'],
            'primary_code'=>$d['primary_code'],'alternate_code'=>$d['alternate_code'],'exchange_rate'=>$d['exchange_rate'],'movement_date'=>$date,
            'concept_id'=>$d['concept_id'],'contact_id'=>$d['contact_id'],'method_id'=>$d['method_id'],'reference'=>$reason,'reversal_of_id'=>$id]);
        foreach($entries as $entry)treasuryEntry($db,['account_id'=>$entry['account_id'],'currency_code'=>$entry['currency_code'],'movement_date'=>$date,
            'amount'=>bcsub('0',$entry['amount'],2),'category'=>in_array($entry['category'],['opening','transfer'],true)?$entry['category']:'reversal','document_id'=>$new,
            'concept_id'=>$entry['concept_id'],'contact_id'=>$entry['contact_id'],'method_id'=>$entry['method_id']]);
        $db->prepare('UPDATE accounting_treasury_documents SET version=version+1 WHERE id=?')->execute([$id]);$db->commit();return $new;
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
function reverseAccountingPayment(PDO $db,int $id,?int $version,array $input): int
{
    workflowBegin($db);
    try{
        $q=$db->prepare('SELECT * FROM accounting_payments WHERE id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
        if(!$p||$p['status']!=='settled'||(int)$p['version']!==$version)throw new InvalidArgumentException('Solo puedes revertir un pago confirmado y vigente.');
        $reason=is_string($input['reason']??null)?trim($input['reason']):'';if($reason===''||mb_strlen($reason)>255)throw new InvalidArgumentException('Indica el motivo de la reversión (hasta 255 caracteres).');
        $date=treasuryDate($input['movement_date']??null);if($date<$p['payment_date'])throw new InvalidArgumentException('La reversión no puede ser anterior al pago.');
        $q=$db->prepare("SELECT invoice_id FROM accounting_invoice_payments WHERE payment_id=? AND status<>'cancel'");$q->execute([$id]);$invoices=paymentLockInvoices($db,$q->fetchAll(PDO::FETCH_COLUMN));
        foreach($invoices as $i)if($i['document_type']==='invoice'){
            $q=$db->prepare("SELECT COUNT(*) FROM accounting_invoice_payments l JOIN accounting_invoices c ON c.id=l.invoice_id WHERE c.reversal_of_id=? AND l.status IN('pending','settled')");$q->execute([$i['id']]);if($q->fetchColumn())throw new InvalidArgumentException('Revierte primero los reembolsos relacionados a la factura.');
        }
        treasuryPostPayment($db,$p);
        $new=treasuryInsertDocument($db,['kind'=>'payment_reversal','status'=>'done','account_id'=>$p['account_id'],'currency_code'=>$p['currency_code'],
            'amount'=>$p['amount'],'primary_code'=>$p['primary_code'],'alternate_code'=>$p['alternate_code'],'exchange_rate'=>$p['exchange_rate'],
            'movement_date'=>$date,'concept_id'=>$p['concept_id'],'contact_id'=>$p['contact_id'],'method_id'=>$p['method_id'],'reference'=>$reason,'payment_id'=>$id]);
        treasuryEntry($db,['account_id'=>$p['account_id'],'currency_code'=>$p['currency_code'],'movement_date'=>$date,'amount'=>$p['direction']==='inbound'?bcsub('0',$p['amount'],2):$p['amount'],
            'category'=>'reversal','document_id'=>$new,'concept_id'=>$p['concept_id'],'contact_id'=>$p['contact_id'],'method_id'=>$p['method_id']]);
        $db->prepare("UPDATE accounting_invoice_payments SET status='cancel' WHERE payment_id=? AND status<>'cancel'")->execute([$id]);
        $db->prepare("UPDATE accounting_payments SET status='reversed',version=version+1 WHERE id=?")->execute([$id]);paymentRecomputeInvoices($db,$invoices);$db->commit();return $new;
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

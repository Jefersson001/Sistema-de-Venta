<?php
declare(strict_types=1);
require_once __DIR__.'/accounting-controller.php';require_once __DIR__.'/payments-repository.php';
function renderAccountingPayments(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION['accounting_csrf']??=bin2hex(random_bytes(32));$config=require __DIR__.'/config/database.php';
    $type=$_GET['type']??'customer';$valid=is_string($type)&&in_array($type,['customer','supplier'],true);if(!$valid)$type='customer';$id=accountingId($_GET['id']??null);
    $page=['area'=>'payments','type'=>$type,'view'=>$id||isset($_GET['new'])?'form':'list','available'=>false,'errors'=>[],'record'=>[],'csrf'=>$_SESSION['accounting_csrf'],'flash'=>$_SESSION['payment_flash']??null,'databaseStatus'=>['connected'=>false,'database'=>$config['database'],'error'=>null]];unset($_SESSION['payment_flash']);
    try{
        $db=createDatabaseConnection();$page['databaseStatus']['connected']=true;$page['available']=$valid;$page['settings']=accountingCurrencies($db);
        $existing=$id?loadAccountingPayment($db,$id):null;
        if(!$valid||(isset($_GET['id'])&&(!$id||!$existing||$existing['partner_type']!==$type))){http_response_code(404);$page['available']=false;$page['errors'][]='El pago solicitado no existe.';}
        $page['record']=$existing??['name'=>'Nuevo','partner_type'=>$type,'direction'=>$type==='customer'?'inbound':'outbound','status'=>'draft','version'=>0,'amount'=>'0.00','currency_code'=>$page['settings']['primary_code'],'primary_code'=>$page['settings']['primary_code'],'alternate_code'=>$page['settings']['alternate_code'],'exchange_rate'=>$page['settings']['exchange_rate'],'payment_date'=>commerceToday(),'reference'=>'','allocated'=>'0.00','available'=>'0.00','allocations'=>[]];
        if(!$id){$key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));if($_SERVER['REQUEST_METHOD']!=='POST'){$_SESSION['payment_form_keys'][$key]=true;$_SESSION['payment_form_keys']=array_slice($_SESSION['payment_form_keys'], -50,null,true);}$page['creationKey']=$key;}
        if($_SERVER['REQUEST_METHOD']==='POST'&&$page['available']){
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='La sesión del formulario no es válida.';}
            else try{
                $action=$_POST['action']??'';
                if($action==='save_payment'&&$page['view']==='form'){
                    if(!$id&&!isset($_SESSION['payment_form_keys'][$page['creationKey']]))throw new InvalidArgumentException('La sesión de creación del pago no es válida.');
                    $input=$_POST;$input['partner_type']=$type;$saved=saveAccountingPayment($db,$input,$id,accountingId($_POST['version']??null),$page['creationKey']??'');$_SESSION['payment_flash']='Pago guardado.';header('Location: pagos.php?type='.$type.'&id='.$saved,true,303);exit;
                }elseif($id&&$action==='reverse_payment'){
                    $reversal=reverseAccountingPayment($db,$id,accountingId($_POST['version']??null),$_POST);header('Location: tesoreria.php?id='.$reversal,true,303);exit;
                }elseif($id&&in_array($action,['post_payment','settle_payment','cancel_payment','apply_payment','unapply_payment'],true)){
                    transitionAccountingPayment($db,$id,accountingId($_POST['version']??null),$action,$_POST);$_SESSION['payment_flash']=match($action){'apply_payment'=>'Pago aplicado a la factura.','unapply_payment'=>'Aplicación retirada; el importe vuelve a estar disponible.','post_payment'=>'Pago registrado en proceso.','settle_payment'=>'Pago confirmado.',default=>'Pago cancelado.'};header('Location: pagos.php?type='.$type.'&id='.$id,true,303);exit;
                }else{http_response_code(400);$page['errors'][]='La acción solicitada no es válida.';}
            }catch(InvalidArgumentException $e){$page['errors'][]=$e->getMessage();if(($action??'')==='save_payment')$page['record']=$_POST+$page['record'];}
        }
        if($page['view']==='form'&&$page['available']){
            $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE (t.system_key=? AND c.active=1) OR c.id=? ORDER BY c.name,c.id");$q->execute([$type==='customer'?'client':'supplier',$existing['contact_id']??0]);$page['contacts']=$q->fetchAll();
            $page['accounts']=$db->query('SELECT * FROM accounting_payment_accounts ORDER BY name,id')->fetchAll();$page['methods']=$db->query('SELECT * FROM accounting_payment_methods ORDER BY name,id')->fetchAll();$page['candidates']=[];
            $page['concepts']=treasuryConcepts($db);$page['record']['concept_id']??=treasuryDefaultConcept($db,$type,$page['record']['direction']);
            if($existing&&in_array($existing['status'],['pending','settled'],true)&&bccomp($existing['available'],'0',2)>0){
                $q=$db->prepare("SELECT * FROM accounting_invoices WHERE contact_id=? AND invoice_type=? AND status='posted' AND payment_state!='reversed' ORDER BY invoice_date,id");$q->execute([$existing['contact_id'],$type]);
                foreach($q->fetchAll() as $i){$inbound=($i['invoice_type']==='customer')===($i['document_type']==='invoice');if(($inbound?'inbound':'outbound')!==$existing['direction'])continue;$details=invoicePaymentDetails($db,(int)$i['id']);$balance=bcsub(paymentInvoiceAmount($db,$i),bcadd($details['settled'],$details['pending'],2),2);if(bccomp($balance,'0',2)<=0)continue;
                    try{$maximum=paymentApplicableAmount($existing['available'],$balance,$existing['currency_code'],$i['currency_code'],$existing);}catch(InvalidArgumentException $e){continue;}
                    if(bccomp($maximum,'0',2)<=0||bccomp(accountingConvert($maximum,$existing['currency_code'],$i['currency_code'],$existing),'0',2)<=0)continue;
                    $i['balance']=$balance;$i['maximum']=$maximum;$page['candidates'][]=$i;
                }
            }
        }elseif($page['available']){
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,160):'';$page['statusFilter']=is_string($_GET['status']??null)&&isset(paymentStatuses()[$_GET['status']])?$_GET['status']:'all';$page['availableFilter']=($_GET['available']??'')==='1';
            $where=' WHERE p.partner_type=:type';$params=['type'=>$type];if($page['search']!==''){$where.=' AND (p.name LIKE :name OR c.name LIKE :contact OR p.reference LIKE :reference)';$params+=['name'=>'%'.$page['search'].'%','contact'=>'%'.$page['search'].'%','reference'=>'%'.$page['search'].'%'];}
            if($page['statusFilter']!=='all'){$where.=' AND p.status=:status';$params['status']=$page['statusFilter'];}
            if($page['availableFilter'])$where.=" AND p.status IN('pending','settled') AND p.amount>COALESCE((SELECT SUM(l.payment_amount) FROM accounting_invoice_payments l WHERE l.payment_id=p.id AND l.status!='cancel'),0)";
            $from=' FROM accounting_payments p JOIN contacts c ON c.id=p.contact_id JOIN accounting_payment_accounts a ON a.id=p.account_id';$q=$db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($params);$page['total']=(int)$q->fetchColumn();$page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(accountingId($_GET['page']??null)??1,$page['pages']);
            $q=$db->prepare("SELECT p.*,c.name AS contact_name,a.name AS account_name,p.amount-COALESCE((SELECT SUM(l.payment_amount) FROM accounting_invoice_payments l WHERE l.payment_id=p.id AND l.status!='cancel'),0) AS available".$from.$where.' ORDER BY p.id DESC LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));$q->execute($params);$page['rows']=$q->fetchAll();
        }
    }catch(PDOException $e){error_log('Pagos: '.$e->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudieron cargar los pagos. Verifica la instalación de Contabilidad.';}
    renderModulePage('contabilidad',false,$page);
}

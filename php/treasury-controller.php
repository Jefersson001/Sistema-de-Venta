<?php
declare(strict_types=1);
require_once __DIR__.'/accounting-controller.php';require_once __DIR__.'/payments-repository.php';
function renderTreasury(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION['accounting_csrf']??=bin2hex(random_bytes(32));
    $page=['area'=>'treasury','available'=>false,'view'=>'list','csrf'=>$_SESSION['accounting_csrf'],'errors'=>[],'record'=>[],'databaseStatus'=>getDatabaseStatus()];
    try{
        $db=createDatabaseConnection();$page['available']=true;$id=accountingId($_GET['id']??null);$page['view']=$id||isset($_GET['new'])?'form':'list';
        $existing=$id?loadTreasuryDocument($db,$id):null;
        if(isset($_GET['id'])&&(!$id||!$existing)){http_response_code(404);$page['available']=false;$page['errors'][]='El movimiento no existe.';}
        $page['settings']=accountingCurrencies($db);$kind=is_string($_GET['kind']??null)&&in_array($_GET['kind'],['opening','transfer','income','expense'],true)?$_GET['kind']:'income';
        $page['record']=$existing??['name'=>'Nuevo','kind'=>$kind,'status'=>'draft','version'=>0,'movement_date'=>commerceToday(),'currency_code'=>$page['settings']['primary_code'],
            'destination_currency_code'=>$page['settings']['primary_code'],'amount'=>'0.00','exchange_rate'=>$page['settings']['exchange_rate'],'reference'=>'','account_id'=>accountingId($_GET['account_id']??null)];
        if(!$existing&&$kind==='opening'&&$page['record']['account_id']){
            $q=$db->prepare('SELECT currency_code FROM accounting_payment_accounts WHERE id=?');$q->execute([$page['record']['account_id']]);if($code=$q->fetchColumn())$page['record']['currency_code']=$code;
            $q=$db->prepare('SELECT MIN(movement_date) FROM accounting_treasury_entries WHERE account_id=? AND currency_code=?');$q->execute([$page['record']['account_id'],$page['record']['currency_code']]);$first=$q->fetchColumn();if($first&&$first<$page['record']['movement_date'])$page['record']['movement_date']=$first;
        }
        if(!$id){$key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));if($_SERVER['REQUEST_METHOD']!=='POST'){
            $_SESSION['treasury_form_keys'][$key]=true;$_SESSION['treasury_form_keys']=array_slice($_SESSION['treasury_form_keys'],-50,null,true);}$page['creationKey']=$key;}
        if($_SERVER['REQUEST_METHOD']==='POST'&&$page['available']){
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='La sesión del formulario no es válida.';}
            else try{
                $action=$_POST['action']??'';
                if($action==='save_treasury'&&$page['view']==='form'){
                    if(!$id&&!isset($_SESSION['treasury_form_keys'][$key]))throw new InvalidArgumentException('La sesión de creación no es válida.');
                    $saved=saveTreasuryDocument($db,$_POST,$id,accountingId($_POST['version']??null),$key??'');header('Location: tesoreria.php?id='.$saved,true,303);exit;
                }elseif($id&&$action==='confirm_treasury'){confirmTreasuryDocument($db,$id,accountingId($_POST['version']??null));header('Location: tesoreria.php?id='.$id,true,303);exit;}
                elseif($id&&$action==='cancel_treasury'){cancelTreasuryDocument($db,$id,accountingId($_POST['version']??null));header('Location: tesoreria.php?id='.$id,true,303);exit;}
                elseif($id&&$action==='reverse_treasury'){$new=reverseTreasuryDocument($db,$id,accountingId($_POST['version']??null),$_POST);header('Location: tesoreria.php?id='.$new,true,303);exit;}
                else{http_response_code(400);$page['errors'][]='La operación no es válida.';}
            }catch(InvalidArgumentException $e){$page['errors'][]=$e->getMessage();if(($action??'')==='save_treasury')$page['record']=array_filter($_POST,'is_scalar')+$page['record'];}
        }
        if($page['view']==='form'){
            $page['accounts']=$db->query('SELECT * FROM accounting_payment_accounts ORDER BY name,id')->fetchAll();$page['methods']=$db->query('SELECT * FROM accounting_payment_methods ORDER BY name,id')->fetchAll();
            $page['concepts']=treasuryConcepts($db);$page['contacts']=$db->query('SELECT id,name FROM contacts ORDER BY name,id')->fetchAll();
            $page['entries']=[];if($id){$q=$db->prepare('SELECT e.*,a.name AS account_name FROM accounting_treasury_entries e JOIN accounting_payment_accounts a ON a.id=e.account_id WHERE e.document_id=? ORDER BY e.id');$q->execute([$id]);$page['entries']=$q->fetchAll();}
            $page['originPayment']=!empty($page['record']['payment_id'])?loadAccountingPayment($db,(int)$page['record']['payment_id']):null;
            $page['commissionInvoiceId']=null;
            if($id){$q=$db->prepare('SELECT invoice_id FROM sales_commission_payments WHERE treasury_document_id=? LIMIT 1');$q->execute([$page['record']['reversal_of_id']??$id]);$page['commissionInvoiceId']=$q->fetchColumn()?:null;}
        }else{
            $page['kindFilter']=is_string($_GET['kind']??null)&&isset(treasuryKinds()[$_GET['kind']])?$_GET['kind']:'';
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,160):'';$where=' WHERE (d.name LIKE ? OR a.name LIKE ? OR d.reference LIKE ?)';$params=array_fill(0,3,'%'.$page['search'].'%');
            $where.=' AND '.permissionTreasuryWhere('d');
            if($page['kindFilter']!==''){$where.=' AND d.kind=?';$params[]=$page['kindFilter'];}
            $from=' FROM accounting_treasury_documents d JOIN accounting_payment_accounts a ON a.id=d.account_id';$q=$db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($params);$page['total']=(int)$q->fetchColumn();
            $page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(accountingId($_GET['page']??null)??1,$page['pages']);
            $q=$db->prepare('SELECT d.*,a.name AS account_name'.$from.$where.' ORDER BY d.movement_date DESC,d.id DESC LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));$q->execute($params);$page['rows']=$q->fetchAll();
        }
    }catch(PDOException $e){error_log('Tesorería: '.$e->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudo consultar Tesorería. Verifica su instalación.';}
    renderModulePage('contabilidad',false,$page);
}

<?php
declare(strict_types=1);
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/connection.php';
require_once __DIR__.'/commission-repository.php';
require_once __DIR__.'/cash-flow-controller.php';

function renderSalesCommissions(): void
{
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $_SESSION['commission_csrf'] ??= bin2hex(random_bytes(32));
    $page=['area'=>'commissions','available'=>true,'errors'=>[],'csrf'=>$_SESSION['commission_csrf'],'view'=>'list','record'=>null,
        'rows'=>[],'groups'=>[],'sellerId'=>null,'saleId'=>null,'seller'=>null,'payments'=>[],'formInput'=>[]];
    $rangeState=cashFlowPageState($_GET);$page['range']=$rangeState['cashFlowRange'];$page['errors']=$rangeState['cashFlowErrors'];
    $page['databaseStatus']=getDatabaseStatus();
    try {
        $db=createDatabaseConnection();$page['settings']=accountingCurrencies($db);
        foreach (['id','seller_id','sale_id'] as $field) {
            if (isset($_GET[$field])&&($field==='id'||$_GET[$field]!=='')&&!salesId($_GET[$field])) {
                http_response_code(404);throw new InvalidArgumentException('El registro solicitado no existe.');
            }
        }
        $id=salesId($_GET['id']??null);$page['sellerId']=salesId($_GET['seller_id']??null);$page['saleId']=salesId($_GET['sale_id']??null);
        if ($id) {
            $page['view']='form';$page['record']=loadSalesCommissions($db,null,null,$id)[0]??null;
            if (!$page['record']) { http_response_code(404);throw new InvalidArgumentException('La comisión solicitada no existe.'); }
            $key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));
            if ($_SERVER['REQUEST_METHOD']!=='POST') {
                $_SESSION['commission_form_keys'][$key]=$id;
                $_SESSION['commission_form_keys']=array_slice($_SESSION['commission_form_keys'],-50,null,true);
            }
            $page['creationKey']=$key;
        }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])) { http_response_code(403);$page['errors'][]='La sesión del formulario no es válida. Recarga la página.'; }
            elseif (!$id||($_POST['action']??null)!=='pay_commission') { http_response_code(400);$page['errors'][]='La operación solicitada no es válida.'; }
            elseif (($_SESSION['commission_form_keys'][$key]??null)!==$id) { http_response_code(403);$page['errors'][]='La solicitud de pago no es válida. Recarga la página.'; }
            else try {
                $document=paySalesCommission($db,$id,$_POST,$key);
                header('Location: ../contabilidad/tesoreria.php?id='.$document,true,303);exit;
            } catch (InvalidArgumentException $exception) { $page['errors'][]=$exception->getMessage();$page['formInput']=array_filter($_POST,'is_string'); }
        }
        if ($id) {
            $page['payments']=loadSalesCommissionPayments($db,$id);
            $page['accounts']=$db->query('SELECT id,name,currency_code FROM accounting_payment_accounts ORDER BY name,id')->fetchAll();
            $page['methods']=$db->query('SELECT id,name FROM accounting_payment_methods ORDER BY name,id')->fetchAll();
        } else {
            $page['sellers']=$db->query("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE t.system_key='salesperson' OR EXISTS(SELECT 1 FROM accounting_invoices i WHERE i.salesperson_id=c.id) ORDER BY c.name,c.id")->fetchAll();
        if(permissionScope('ventas.commissions')==='own')$page['sellers']=array_values(array_filter($page['sellers'],static fn($r)=>in_array((int)$r['id'],permissionContacts(),true)));
            if ($page['sellerId']) { $page['seller']=salesRecord($page['sellers'],$page['sellerId']);if (!$page['seller']) { http_response_code(404);throw new InvalidArgumentException('El vendedor solicitado no existe.'); } }
            if (!$rangeState['cashFlowErrors']) $page['rows']=loadSalesCommissions($db,$page['range'],$page['sellerId']);
            if ($page['saleId']) $page['rows']=array_values(array_filter($page['rows'],static fn($r)=>(int)$r['sale_id']===$page['saleId']));
            $page['groups']=summarizeSalesCommissions($page['rows']);$page['total']=count($page['rows']);
            $page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(salesId($_GET['page']??null)??1,$page['pages']);
            $page['rows']=array_slice($page['rows'],($page['currentPage']-1)*20,20);
        }
    } catch (InvalidArgumentException $exception) { $page['available']=false;$page['errors'][]=$exception->getMessage(); }
    catch (Throwable $exception) { error_log('Comisiones: '.$exception->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudieron consultar las comisiones. Verifica la instalación.'; }
    renderModulePage('ventas',false,$page);
}

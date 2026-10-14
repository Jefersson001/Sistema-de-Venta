<?php
declare(strict_types=1);
require_once __DIR__.'/commission-controller.php';

function renderSalesCommissionPayments(): void
{
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $_SESSION['commission_csrf'] ??= bin2hex(random_bytes(32));
    $state = cashFlowPageState($_GET);
    $page = ['area'=>'commission_payments','available'=>true,'errors'=>$state['cashFlowErrors'],
        'databaseStatus'=>getDatabaseStatus(),'csrf'=>$_SESSION['commission_csrf'],'range'=>$state['cashFlowRange'],
        'view'=>'list','record'=>null,'sellerId'=>null,'seller'=>null,'commissionCurrency'=>'',
        'formInput'=>[],'commissions'=>[],'payable'=>[],'hasAdjustment'=>false];
    try {
        $db = createDatabaseConnection(); $page['settings'] = accountingCurrencies($db);
        foreach (['id','seller_id','invoice_id','page'] as $field) {
            if (isset($_GET[$field]) && $_GET[$field]!=='' && !salesId($_GET[$field])) {
                http_response_code(404); throw new InvalidArgumentException('El registro solicitado no existe.');
            }
        }
        $id = salesId($_GET['id']??null);
        $page['view'] = $id ? 'form' : (isset($_GET['new']) ? 'new' : 'list');
        $page['sellerId'] = salesId($_GET['seller_id']??null);
        $page['sellers'] = $db->query("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id
            WHERE t.system_key='salesperson' OR EXISTS(SELECT 1 FROM accounting_invoices i WHERE i.salesperson_id=c.id)
            ORDER BY c.name,c.id")->fetchAll();
        if ($page['sellerId']) {
            $page['seller'] = salesRecord($page['sellers'],$page['sellerId']);
            if (!$page['seller']) { http_response_code(404); throw new InvalidArgumentException('El vendedor solicitado no existe.'); }
        }
        $currency = $_GET['commission_currency']??'';
        if (!is_string($currency) || ($currency!=='' && !preg_match('/^[A-Z]{3}$/D',$currency))) {
            http_response_code(400); throw new InvalidArgumentException('La moneda de comisión no es válida.');
        }
        $page['commissionCurrency'] = $currency;
        if ($page['view']==='form') {
            $page['record'] = salesCommissionPaymentHistory($db,null,null,$id)['rows'][0]??null;
            if (!$page['record']) { http_response_code(404); throw new InvalidArgumentException('El pago de comisión no existe.'); }
        } elseif ($page['view']==='list') {
            $page += salesCommissionPaymentHistory($db,$page['range'],$page['sellerId'],null,salesId($_GET['page']??null)??1);
        } else {
            $invoiceId = salesId($_GET['invoice_id']??null);
            if ($invoiceId && !$page['sellerId']) {
                $commission = loadSalesCommissions($db,null,null,$invoiceId)[0]??null;
                if (!$commission) { http_response_code(404); throw new InvalidArgumentException('La comisión solicitada no existe.'); }
                $page['sellerId'] = (int) $commission['salesperson_id'];
                $page['seller'] = salesRecord($page['sellers'],$page['sellerId']);
            }
            if ($page['sellerId'] && !$page['errors']) {
                $all = loadSalesCommissions($db,null,$page['sellerId']);
                $page['hasAdjustment'] = (bool) array_filter($all,static fn($r)=>bccomp($r['commission_overpaid'],'0',2)>0);
                $page['commissions'] = array_values(array_filter($all,static fn($r)=>
                    $r['invoice_date'] >= $page['range']['date_from'] && $r['invoice_date'] <= $page['range']['date_to']
                    && ($currency==='' || $r['currency_code']===$currency)));
                $page['payable'] = array_values(array_filter($page['commissions'],static fn($r)=>bccomp($r['commission_pending'],'0',2)>0));
                if ($invoiceId) {
                    $page['record'] = salesRecord($page['commissions'],$invoiceId);
                    if (!$page['record']) { http_response_code(404); throw new InvalidArgumentException('La factura no corresponde al vendedor, moneda o período seleccionado.'); }
                } else {
                    $page['record'] = $page['payable'][0]??null;
                    $invoiceId = $page['record'] ? (int) $page['record']['id'] : null;
                }
            }
            if ($page['record']) {
                $key = is_string($_POST['creation_key']??null) ? $_POST['creation_key'] : bin2hex(random_bytes(32));
                if ($_SERVER['REQUEST_METHOD']!=='POST') {
                    $_SESSION['commission_form_keys'][$key] = $invoiceId;
                    $_SESSION['commission_form_keys'] = array_slice($_SESSION['commission_form_keys'],-50,null,true);
                }
                $page['creationKey'] = $key;
                $page['paymentAction'] = 'pagos-comisiones.php?'.http_build_query(['new'=>1,'seller_id'=>$page['sellerId'],
                    'invoice_id'=>$invoiceId,'commission_currency'=>$currency]).'&'.cashFlowQuery($page['range']);
                $page['accounts'] = $db->query('SELECT id,name,currency_code FROM accounting_payment_accounts ORDER BY name,id')->fetchAll();
                $page['methods'] = $db->query('SELECT id,name FROM accounting_payment_methods ORDER BY name,id')->fetchAll();
            }
        }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!is_string($_POST['csrf']??null) || !hash_equals($page['csrf'],$_POST['csrf'])) {
                http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view']!=='new' || !$page['record'] || ($_POST['action']??null)!=='pay_commission' || $state['cashFlowErrors']) {
                http_response_code(400); $page['errors'][] = 'Selecciona un vendedor y una factura válidos antes de pagar.';
            } elseif (($_SESSION['commission_form_keys'][$key]??null)!==$invoiceId) {
                http_response_code(403); $page['errors'][] = 'La solicitud de pago no es válida. Recarga la página.';
            } else {
                try {
                    $document = paySalesCommission($db,$invoiceId,$_POST,$key);
                    $query = $db->prepare('SELECT id FROM sales_commission_payments WHERE treasury_document_id=?');
                    $query->execute([$document]); $payment = (int) $query->fetchColumn();
                    header('Location: pagos-comisiones.php?id='.$payment,true,303); exit;
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            }
            $page['formInput'] = array_filter($_POST,'is_string');
        }
    } catch (InvalidArgumentException $exception) { $page['available']=false; $page['errors'][]=$exception->getMessage(); }
    catch (Throwable $exception) {
        error_log('Pagos de comisiones: '.$exception->getMessage()); http_response_code(503); $page['available']=false;
        $page['errors'][]='No se pudieron consultar los pagos de comisiones. Verifica la instalación.';
    }
    renderModulePage('ventas',false,$page);
}

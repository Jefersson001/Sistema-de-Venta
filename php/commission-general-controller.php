<?php
declare(strict_types=1);

function renderGeneralCommissionPayment(PDO $db,array $page): void
{
    $page['view']='general';$page['balances']=$page['sellerId']?sellerCommissionBalances($db,$page['sellerId']):[];
    $currency=$page['commissionCurrency'];if($currency==='')$currency=isset($page['balances'][$page['settings']['primary_code']])?$page['settings']['primary_code']:(array_key_first($page['balances'])??$page['settings']['primary_code']);
    $page['commissionCurrency']=$currency;$balance=$page['balances'][$currency]??null;
    $key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));$binding='seller:'.$page['sellerId'].':'.$currency;
    if($_SERVER['REQUEST_METHOD']!=='POST'){$_SESSION['commission_form_keys'][$key]=$binding;$_SESSION['commission_form_keys']=array_slice($_SESSION['commission_form_keys']??[],-50,null,true);}
    $page['creationKey']=$key;$page['paymentAction']='pagos-comisiones.php?'.http_build_query(['new'=>1,'seller_id'=>$page['sellerId'],'commission_currency'=>$currency]);
    $page['record']=$balance?['currency_code'=>$currency,'commission_pending'=>$balance['pending'],'invoice_date'=>$balance['first_date']]:null;
    $page['accounts']=$db->query('SELECT id,name,currency_code FROM accounting_payment_accounts ORDER BY name,id')->fetchAll();$page['methods']=$db->query('SELECT id,name FROM accounting_payment_methods ORDER BY name,id')->fetchAll();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='La sesión del formulario no es válida.';}
        elseif(($_SESSION['commission_form_keys'][$key]??null)!==$binding){http_response_code(403);$page['errors'][]='La solicitud de pago no corresponde a este vendedor y moneda.';}
        elseif(!$page['sellerId']||($_POST['action']??'')!=='pay_commission'){http_response_code(400);$page['errors'][]='Selecciona un vendedor para pagar.';}
        else try{
            $document=paySellerCommission($db,$page['sellerId'],$currency,paymentProofInput($_POST,$_FILES),$key);
            $q=$db->prepare('SELECT MIN(id) FROM sales_commission_payments WHERE treasury_document_id=?');$q->execute([$document]);header('Location: pagos-comisiones.php?id='.(int)$q->fetchColumn(),true,303);return;
        }catch(InvalidArgumentException $e){$page['errors'][]=$e->getMessage();}
        $page['formInput']=array_filter($_POST,'is_string');
    }
    renderModulePage('ventas',false,$page);
}

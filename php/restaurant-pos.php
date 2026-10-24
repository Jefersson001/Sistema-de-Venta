<?php
declare(strict_types=1);
require_once __DIR__.'/restaurant-repository.php';
require_once __DIR__.'/sales-repository.php';
require_once __DIR__.'/workflow-invoices.php';
require_once __DIR__.'/operation-status-repository.php';
require_once __DIR__.'/payments-repository.php';
require_once __DIR__.'/restaurant-checkout.php';
require_once __DIR__.'/receipt-snapshot.php';

function restaurantPosFields(array $cart,string $action): void
{
    foreach(['csrf'=>$_SESSION['auth_csrf']??'','company_scope'=>companyScopeToken(),'version'=>$cart['account']['version'],
        'request_key'=>bin2hex(random_bytes(32)),'action'=>$action] as $key=>$value){
        echo '<input type="hidden" name="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">';
    }
}

function restaurantCart(PDO $db,int $id): array
{
    $account=restaurantRecord($db,'restaurant_accounts',$id);
    $q=$db->prepare('SELECT * FROM restaurant_account_carts WHERE account_id=?');$q->execute([$id]);$cart=$q->fetch();
    $q=$db->prepare('SELECT * FROM restaurant_account_items WHERE account_id=? ORDER BY id');$q->execute([$id]);$items=$q->fetchAll();
    $base='0.00';$tax='0.00';$total='0.00';
    foreach($items as &$item){$item+=commerceAmounts($item['quantity'],$item['unit_price'],$item['tax_rate']);$base=bcadd($base,$item['base_amount'],2);$tax=bcadd($tax,$item['tax_amount'],2);$total=bcadd($total,$item['total_amount'],2);}unset($item);
    return ['account'=>$account,'header'=>$cart?:null,'items'=>$items,'base'=>$base,'tax'=>$tax,'total'=>$total,'currency'=>$cart['currency_code']??accountingCurrencies($db)['primary_code']];
}
/** Visiting a free table is read-only; its first product opens the account atomically. */
function restaurantStartOrder(PDO $db,array $input,int $waiter): int
{
    if(!$db instanceof CompanyConnection)throw new LogicException('El pedido requiere una conexión de empresa.');
    return $db->atomicTransfer(function()use($db,$input,$waiter){
        $id=restaurantOpenAccount($db,array_replace($input,['waiter_id'=>$waiter,'guests'=>1,'notes'=>'']));
        restaurantCartCommand($db,$id,array_replace($input,['action'=>'pos_add','version'=>1]));
        return $id;
    });
}
function restaurantCartCommand(PDO $db,int $id,array $input): int
{
    permissionRequire('restaurante.accounts','edit',$id);
    $action=$input['action']??'';
    if(!in_array($action,['pos_add','pos_quantity','pos_remove','pos_note'],true))throw new InvalidArgumentException('Acción de pedido no válida.');
    $target=restaurantInt($input[$action==='pos_add'?'product_id':'item_id']??null);
    $quantity=$input['quantity']??'1';$notes=$input['notes']??'';
    return restaurantAtomic($db,function()use($db,$id,$input,$action,$target,$quantity,$notes){
        $account=restaurantRecord($db,'restaurant_accounts',$id,true);
        return restaurantRequest($db,$action,restaurantText($input['request_key']??null,64),[$id,$target,$quantity,$notes],function()use($db,$id,$input,$account,$action,$target,$quantity,$notes){
            restaurantVersion($account,$input['version']??null);
            if($account['status']!=='open')throw new InvalidArgumentException('Esta cuenta ya está facturada o cerrada.',409);
            $cart=restaurantCart($db,$id);if($cart['header']['invoice_id']??null)throw new InvalidArgumentException('La cuenta ya está facturada.',409);
            if($action==='pos_add'){
                if(count($cart['items'])>=100)throw new InvalidArgumentException('El pedido admite hasta 100 líneas.');
                $q=$db->prepare("SELECT p.*,t.name AS tax_name,t.rate AS tax_rate FROM inventory_products p
                    LEFT JOIN business_taxes t ON t.id=p.sale_tax_id WHERE p.id=? AND p.sale_enabled=1");$q->execute([$target]);$product=$q->fetch();
                if(!$product)throw new InvalidArgumentException('El producto no está habilitado para venta.');
                if(!in_array((int)$product['category_id'],inventoryRestaurantCategoryIds(loadInventoryCategories($db)),true)){
                    throw new InvalidArgumentException('El producto no pertenece a una categoría principal marcada como Es para restaurante.');
                }
                if(!$cart['header']){
                    $settings=accountingCurrencies($db);
                    $db->prepare('INSERT INTO restaurant_account_carts(account_id,currency_code,primary_code,alternate_code,exchange_rate) VALUES(?,?,?,?,?)')
                        ->execute([$id,$settings['primary_code'],$settings['primary_code'],$settings['alternate_code'],$settings['exchange_rate']]);
                    $cart=restaurantCart($db,$id);
                }
                $pair=$cart['header'];$price=accountingConvert($product['sale_price'],$product['currency_code'],$pair['currency_code'],$pair);
                $cost=accountingConvert($product['cost_price'],$product['currency_code'],$pair['currency_code'],$pair);
                if(salesDecimal($price,10,2)===null||salesDecimal($cost,10,2)===null)throw new InvalidArgumentException('El precio convertido supera el máximo admitido.');
                $q=$db->prepare('SELECT id,quantity FROM restaurant_account_items WHERE account_id=? AND product_id=? AND unit_price=? AND tax_rate=? AND notes=? ORDER BY id LIMIT 1');
                $q->execute([$id,$target,$price,$product['tax_rate']??'0','']);$old=$q->fetch();
                if($old){$next=bcadd($old['quantity'],'1',3);if(salesDecimal($next,9,3)===null)throw new InvalidArgumentException('Cantidad fuera de rango.');$db->prepare('UPDATE restaurant_account_items SET quantity=? WHERE id=?')->execute([$next,$old['id']]);}
                else $db->prepare('INSERT INTO restaurant_account_items(account_id,product_id,product_name,quantity,unit_price,unit_cost,tax_id,tax_name,tax_rate,product_type) VALUES(?,?,?,1,?,?,?,?,?,?)')
                    ->execute([$id,$target,$product['name'],$price,$cost,$product['sale_tax_id'],$product['tax_name'],$product['tax_rate']??'0',$product['product_type']]);
            }else{
                $q=$db->prepare('SELECT * FROM restaurant_account_items WHERE id=? AND account_id=?');$q->execute([$target,$id]);$item=$q->fetch();if(!$item)throw new InvalidArgumentException('El artículo ya no está en esta mesa.',409);
                if($action==='pos_remove')$db->prepare('DELETE FROM restaurant_account_items WHERE id=?')->execute([$target]);
                elseif($action==='pos_note')$db->prepare('UPDATE restaurant_account_items SET notes=? WHERE id=?')->execute([restaurantText($notes,255,false),$target]);
                else{$qty=salesDecimal($quantity,9,3);if($qty===null||bccomp($qty,'0',3)<=0)throw new InvalidArgumentException('La cantidad debe ser positiva.');$db->prepare('UPDATE restaurant_account_items SET quantity=? WHERE id=?')->execute([$qty,$target]);}
            }
            $fresh=restaurantCart($db,$id);if(bccomp($fresh['total'],'9999999999999999.99',2)>0)throw new InvalidArgumentException('El pedido supera el importe máximo.');
            $db->prepare('UPDATE restaurant_accounts SET version=version+1 WHERE id=?')->execute([$id]);
            restaurantAudit($db,$action,['account_id'=>$id,'target'=>$target,'quantity'=>$quantity,'notes'=>$notes,'previous_item'=>$item??null]);return $id;
        });
    });
}

/** One transaction coordinates the existing sales, stock, invoice and payment services. */
function restaurantCheckout(PDO $db,int $id,array $input): int
{
    $input=restaurantCheckoutInput($input);
    $trace=static function(string $step):void{if(PHP_SAPI==='cli'&&getenv('NEXO_POS_TRACE'))fwrite(STDERR,'POS '.$step.PHP_EOL);};
    permissionRequire('restaurante.accounts','confirm',$id);permissionRequire('restaurante.accounts','pay',$id);
    if(!$db instanceof CompanyConnection)throw new LogicException('El cobro requiere una conexión de empresa.');
    $customer=restaurantInt($input['customer_id']??null);$location=restaurantInt($input['location_id']??null);
    $requestPayments=$input['payments']??['account_id'=>$input['account_id']??null,'method_id'=>$input['method_id']??null,'received_amount'=>$input['received_amount']??null];
    if(!is_array($requestPayments)||count($requestPayments)>20)throw new InvalidArgumentException('Revisa el reparto de los pagos.');
    return $db->atomicTransfer(function()use($db,$id,$input,$customer,$location,$requestPayments,$trace){
        $account=restaurantRecord($db,'restaurant_accounts',$id,true);
        return restaurantRequest($db,'pos_checkout',restaurantText($input['request_key']??null,64),[$id,$customer,$location,$input['split_mode']??'full',$requestPayments],function()use($db,$id,$input,$account,$customer,$location,$trace){
            restaurantVersion($account,$input['version']??null);
            if($account['status']!=='open')throw new InvalidArgumentException('La mesa ya fue facturada. Recarga para ver su comprobante.',409);
            $cart=restaurantCart($db,$id);if(!$cart['items'])throw new InvalidArgumentException('Agrega productos antes de facturar.');
            if($cart['header']['invoice_id'])throw new InvalidArgumentException('La mesa ya tiene una factura.',409);
            restaurantPaymentPlan($cart,$input);
            $q=$db->prepare("SELECT c.* FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=? AND c.active=1 AND t.system_key='client'");$q->execute([$customer]);$contact=$q->fetch();if(!$contact)throw new InvalidArgumentException('Selecciona un cliente activo.');
            $receiptSnapshot=restaurantReceiptSnapshot($db,$contact,(int)$account['company_id']);
            $q=$db->prepare("SELECT * FROM inventory_locations WHERE id=? AND location_type='internal'");$q->execute([$location]);$origin=$q->fetch();if(!$origin)throw new InvalidArgumentException('Selecciona una ubicación interna para las existencias.');
            $header=$cart['header'];$date=commerceToday();
            $snapshot=invoiceExchangeSnapshot($db,['currency_primary_code'=>$header['primary_code'],'currency_alternate_code'=>$header['alternate_code']],$date);
            if(!$snapshot['exchange_rate'])throw new InvalidArgumentException('Registra la tasa vigente antes de facturar, como requiere la facturación actual.');
            $data=['customer_id'=>$customer,'invoice_address'=>salesAddress($contact),'delivery_address'=>salesAddress($contact),
                'order_date'=>$date,'payment_term_id'=>null,'salesperson_id'=>null,'warehouse_id'=>$origin['warehouse_id'],'location_id'=>$location,'storage_label'=>'',
                'order_type'=>'invoice','base_amount'=>$cart['base'],'tax_amount'=>$cart['tax'],'total_amount'=>$cart['total'],
                'currency_code'=>$header['currency_code'],'currency_primary_code'=>$header['primary_code'],'currency_alternate_code'=>$header['alternate_code'],'exchange_rate'=>$snapshot['exchange_rate']];
            $lines=[];foreach($cart['items'] as $item){$line=[];foreach(['product_id','product_name','quantity','unit_price','unit_cost','tax_id','tax_name','tax_rate','tax_amount','base_amount','total_amount','product_type'] as $key)$line[$key]=$item[$key];$line['invoice_policy']='ordered';$lines[]=$line;}
            $trace('sale');$sale=saveSalesQuotation($db,$data,$lines,null,null);transitionSalesQuotation($db,$sale,1,'sale');
            if(array_filter($lines,static fn($l)=>$l['product_type']==='goods')){
                $trace('stock');$operation=createLinkedStockOperation($db,'sale',$sale);transitionInventoryOperation($db,$operation,1,'confirm_operation');transitionInventoryOperation($db,$operation,2,'check_ready');
                $db->prepare('UPDATE inventory_operation_lines SET quantity=demand WHERE operation_id=?')->execute([$operation]);validateInventoryStockOperation($db,$operation,3);
            }
            $trace('invoice');$invoice=workflowCreateInvoice($db,'sale',$sale);
            // Same date/rate validation and payment-state computation used by invoice registration.
            $db->prepare("UPDATE accounting_invoices SET status='posted',version=version+1 WHERE id=? AND status='draft'")->execute([$invoice]);
            $trace('payment');$collection=restaurantCollectPayments($db,$id,$account,$invoice,$cart,$customer,$input);
            $trace('close');$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);$invoiceRow=$q->fetch();invoiceRecomputePaymentState($db,$invoiceRow);
            $details=invoicePaymentDetails($db,$invoice);if(bccomp($details['settled'],$cart['total'],2)!==0)throw new LogicException('El cobro no coincide con la factura.');
            $db->prepare('UPDATE restaurant_account_carts SET sale_id=?,invoice_id=?,receipt_snapshot=? WHERE account_id=?')->execute([$sale,$invoice,json_encode($receiptSnapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
            $q=$db->prepare('SELECT table_id FROM restaurant_active_tables WHERE account_id=? ORDER BY table_id');$q->execute([$id]);$tableIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            foreach($tableIds as $table)restaurantReleaseTable($db,$id,$table,'Facturación y cobro completados','cleaning');
            $db->prepare("UPDATE restaurant_accounts SET status='closed',closed_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?")->execute([$id]);
            restaurantAudit($db,'pos_checkout',['account_id'=>$id,'sale_id'=>$sale,'invoice_id'=>$invoice,'amount'=>$cart['total']]+$collection);return $invoice;
        });
    });
}

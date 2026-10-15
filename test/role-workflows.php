<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/commerce-repository.php';
require_once dirname(__DIR__).'/php/inventory-stock-repository.php';
require_once dirname(__DIR__).'/php/payments-repository.php';
require_once dirname(__DIR__).'/php/commission-repository.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';

function roleExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function roleRecord(PDO $db, string $table, int $id): array {
    $q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?'); $q->execute([$id]);
    return $q->fetch();
}
function roleCreated(array $response): int {
    roleExpect($response[0]===303 && preg_match('/[?&]id=(\d+)/',$response[2],$m)===1,
        'La acción no terminó: '.mb_substr(strip_tags($response[1]),-1200));
    return (int)$m[1];
}
$server=startAuthTestServer(); $db=$server['db']; $cookies=[];
try {
    $prefix='Circuito-'.bin2hex(random_bytes(5)); $password=bin2hex(random_bytes(14)); $today=commerceToday();
    $token=prepareFirstAdministrator($db);
    $admin=createFirstAdministrator($db,['name'=>$prefix,'login'=>strtolower($prefix),
        'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $types=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix]); $warehouse=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations(name,warehouse_id) VALUES(?,?)')->execute(['Existencias',$warehouse]); $location=(int)$db->lastInsertId();
    foreach (['salesperson','client','supplier'] as $type) {
        $db->prepare('INSERT INTO contacts(name,type_id,warehouse_id,location_id,address) VALUES(?,?,?,?,?)')
            ->execute([$prefix.' '.$type,$types[$type],$type==='salesperson'?$warehouse:null,$type==='salesperson'?$location:null,'Dirección de prueba']);
        $contacts[$type]=(int)$db->lastInsertId();
    }
    $db->prepare('UPDATE contacts SET salesperson_id=? WHERE id=?')->execute([$contacts['salesperson'],$contacts['client']]);
    $settings=accountingCurrencies($db); $code=$settings['primary_code'];
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES(?,'cash',?)")->execute([$prefix,$code]); $account=(int)$db->lastInsertId();
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->prepare("INSERT INTO business_taxes(name,module_key,rate) VALUES(?,'ventas',10)")->execute(['Impuesto de prueba 10 %']); $tax=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,sale_price,cost_price,product_type,invoice_policy,commission_type,commission_value,sale_tax_id,currency_code) VALUES(?,1,1,5,3,'goods','delivered','fixed',2,?,?)")
        ->execute([$prefix.' Producto',$tax,$code]); $product=(int)$db->lastInsertId();
    foreach (accessInitialGroups() as $key=>$spec) {
        if (!in_array($key,['sales','purchases','inventory','accounting','readonly'],true)) continue;
        $scopes=[]; foreach($spec['permissions'] as $permission=>$scope) $scopes[substr($permission,0,strrpos($permission,'.'))]=$scope;
        $group=saveAccessGroup($db,['name'=>$prefix.' '.$key,'active'=>'1','permissions'=>array_keys($spec['permissions']),'scopes'=>$scopes],null,null,bin2hex(random_bytes(32)));
        $login=strtolower($prefix.'.'.$key);
        $user=saveAuthUser($db,['name'=>$prefix.' '.$key,'login'=>$login,'active'=>'1','groups'=>[$group],
            'contact_id'=>$key==='sales'?$contacts['salesperson']:null,'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
        $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$user]);
        $cookies[$key]=tempnam(sys_get_temp_dir(),'nexo-role-');
        [, $loginForm]=authTestRequest($server,'/login.php',$cookies[$key]);
        [$status]=authTestRequest($server,'/login.php',$cookies[$key],['csrf'=>authTestToken($loginForm),'login'=>$login,'password'=>$password]);
        roleExpect($status===303,'No entró el usuario '.$key);
        [, $home]=authTestRequest($server,'/index.php',$cookies[$key]); $csrf[$key]=authTestToken($home);
    }
    $post=static function(string $role,string $path,array $input)use($server,$cookies,$csrf):array {
        return authTestRequest($server,$path,$cookies[$role],$input+['csrf'=>$csrf[$role]]);
    };
    $export=static function(string $name,string $role,string $path)use($server,$cookies):void {
        [, $view]=authTestRequest($server,$path,$cookies[$role]);
        $view=str_replace(['href="../../css/','src="../../js/'],['href="http://localhost/project/css/','src="http://localhost/project/js/'],$view);
        file_put_contents(sys_get_temp_dir().'/nexo-audit-'.$name.'.html',$view);
    };
    // An opening balance is a separate document, not a sale or a purchase.
    $openingPath='/modules/contabilidad/tesoreria.php?new=1&kind=opening';
    [, $openingForm]=authTestRequest($server,$openingPath,$cookies['accounting']);
    preg_match('/name="creation_key" value="([a-f0-9]+)"/',$openingForm,$key);
    $opening=roleCreated($post('accounting',$openingPath,['action'=>'save_treasury','creation_key'=>$key[1],
        'kind'=>'opening','account_id'=>$account,'currency_code'=>$code,'amount'=>'100.00','movement_date'=>$today,'method_id'=>$method]));
    roleCreated($post('accounting','/modules/contabilidad/tesoreria.php?id='.$opening,['action'=>'confirm_treasury','version'=>1]));
    $move=static function(int $id,string $quantity)use($db,$post):void {
        $o=roleRecord($db,'inventory_operations',$id); $path='/modules/inventario/operaciones.php?type='.$o['operation_type'].'&id='.$id;
        $lines=loadInventoryOperationLines($db,$id); foreach($lines as &$line) $line['quantity']=$quantity; unset($line);
        roleCreated($post('inventory',$path,['action'=>'save_operation','version'=>$o['version'],'contact_id'=>$o['contact_id'],
            'scheduled_date'=>$o['scheduled_date'],'source_location_id'=>$o['source_location_id'],'destination_location_id'=>$o['destination_location_id'],'lines'=>$lines]));
        foreach(['confirm_operation','check_ready','validate_operation'] as $action) {
            $o=roleRecord($db,'inventory_operations',$id); roleCreated($post('inventory',$path,['action'=>$action,'version'=>$o['version']]));
        }
    };
    $invoice=static function(string $role,string $kind,int $order)use($post):int {
        return roleCreated($post($role,'/modules/'.$kind.'/index.php?id='.$order,['action'=>'create_invoice']));
    };
    $register=static function(string $type,int $id)use($post,$today):void {
        roleCreated($post('accounting','/modules/contabilidad/index.php?type='.$type.'&id='.$id,
            ['action'=>'confirm_invoice','version'=>1,'invoice_date'=>$today,'due_date'=>$today]));
    };
    $pay=static function(string $type,int $id,string $amount,bool $settle=true)use($db,$post,$code,$account,$method,$today):int {
        $i=roleRecord($db,'accounting_invoices',$id); $path='/modules/contabilidad/index.php?type='.$type.'&id='.$id;
        roleCreated($post('accounting',$path,['action'=>'register_payment','version'=>$i['version'],'amount'=>$amount,
            'currency_code'=>$code,'account_id'=>$account,'method_id'=>$method,'payment_date'=>$today]));
        $q=$db->prepare("SELECT id,payment_id FROM accounting_invoice_payments WHERE invoice_id=? AND status='pending' ORDER BY id DESC LIMIT 1"); $q->execute([$id]); $allocation=$q->fetch();
        if($settle) {
            $i=roleRecord($db,'accounting_invoices',$id);
            roleCreated($post('accounting',$path,['action'=>'settle_payment','version'=>$i['version'],'payment_id'=>$allocation['id']]));
        }
        return (int)$allocation['payment_id'];
    };
    $purchasePath='/modules/compras/index.php';
    $purchase=roleCreated($post('purchases',$purchasePath.'?new=1',['action'=>'save_purchase','supplier_id'=>$contacts['supplier'],
        'order_date'=>$today,'order_type'=>'receipt','currency_code'=>$code,'destination_location_id'=>$location,
        'lines'=>[['product_id'=>$product,'quantity'=>'10.000','unit_price'=>'3.00','tax_id'=>'']]]));
    roleCreated($post('purchases',$purchasePath.'?id='.$purchase,['action'=>'confirm_purchase','version'=>1]));
    $receipt=roleCreated($post('purchases',$purchasePath.'?id='.$purchase,['action'=>'create_receipt']));
    roleExpect($post('purchases','/modules/inventario/operaciones.php?type=receipt&id='.$receipt,['action'=>'validate_operation','version'=>1])[0]===403,'Compras pudo validar inventario sin permiso.');
    $move($receipt,'10.000'); $bill=$invoice('purchases','compras',$purchase);
    roleExpect($post('purchases','/modules/contabilidad/index.php?type=supplier&id='.$bill,['action'=>'confirm_invoice','version'=>1])[0]===403,'Compras pudo registrar la factura sin permiso.');
    $register('supplier',$bill); $pay('supplier',$bill,'30.00');
    roleExpect(treasuryBalance($db,$account,$code,$today)==='70.00','Saldo después de la compra incorrecto.');
    $salePath='/modules/ventas/index.php';
    $sale=roleCreated($post('sales',$salePath.'?new=1',['action'=>'save_quotation','customer_id'=>$contacts['client'],
        'order_date'=>$today,'order_type'=>'delivery','currency_code'=>$code,'lines'=>[['product_id'=>$product,'quantity'=>'2.000']]]));
    $order=loadSalesQuotation($db,$sale);
    roleExpect($order['total_amount']==='15.40' && $order['commission_estimated']==='4.00' && (int)$order['salesperson_id']===$contacts['salesperson'],'No viajaron impuesto, comisión o vendedor.');
    roleCreated($post('sales',$salePath.'?id='.$sale,['action'=>'confirm_sale','version'=>1]));
    $delivery=roleCreated($post('sales',$salePath.'?id='.$sale,['action'=>'create_delivery'])); $move($delivery,'2.000');
    $customerInvoice=$invoice('sales','ventas',$sale); $register('customer',$customerInvoice);
    $pay('customer',$customerInvoice,'7.70',false);
    roleExpect(roleRecord($db,'accounting_invoices',$customerInvoice)['payment_state']==='in_payment','El cobro pendiente tiene un estado incorrecto.');
    $q=$db->prepare('SELECT id FROM accounting_invoice_payments WHERE invoice_id=?'); $q->execute([$customerInvoice]); $allocation=(int)$q->fetchColumn();
    $i=roleRecord($db,'accounting_invoices',$customerInvoice);
    roleCreated($post('accounting','/modules/contabilidad/index.php?type=customer&id='.$customerInvoice,['action'=>'settle_payment','version'=>$i['version'],'payment_id'=>$allocation]));
    roleExpect(roleRecord($db,'accounting_invoices',$customerInvoice)['payment_state']==='partial','El cobro parcial confirmado no deja Parcialmente pagado.');
    $commission=loadSalesCommissions($db,null,null,$customerInvoice)[0];
    roleExpect($commission['commission_earned']==='2.00','El cobro parcial no habilita la mitad de la comisión.');
    $pay('customer',$customerInvoice,'7.70');
    roleExpect(roleRecord($db,'accounting_invoices',$customerInvoice)['payment_state']==='paid','La factura no termina pagada.');
    $commissionPath='/modules/ventas/pagos-comisiones.php?new=1&seller_id='.$contacts['salesperson'].'&invoice_id='.$customerInvoice.'&commission_currency='.$code;
    [, $commissionForm]=authTestRequest($server,$commissionPath,$cookies['accounting']);
    preg_match('/name="creation_key" value="([a-f0-9]+)"/',$commissionForm,$key);
    roleCreated($post('accounting',$commissionPath,['action'=>'pay_commission','creation_key'=>$key[1],
        'amount'=>'4.00','account_id'=>$account,'method_id'=>$method,'currency_code'=>$code,'movement_date'=>$today]));
    roleExpect(loadSalesCommissions($db,null,null,$customerInvoice)[0]['commission_paid']==='4.00','La comisión no quedó pagada.');
    roleExpect(loadInventoryProductStock($db,$product)['total']==='8.000','Las cantidades físicas no coinciden.');
    $cash=loadCashFlowSummary($db,cashFlowRange([]),$account)['currencies'][0];
    roleExpect($cash['income']==='15.40' && $cash['expense']==='34.00' && $cash['balance_end']==='81.40','Ingresos, egresos o saldo final no coinciden.');
    roleExpect($post('readonly',$salePath.'?id='.$sale,['action'=>'cancel_quotation','version'=>2])[0]===403,'Consulta pudo cancelar una venta.');
    roleExpect(authTestRequest($server,'/modules/contabilidad/tesoreria.php?kind=expense',$cookies['sales'])[0]===403,'Ventas accedió a egresos generales.');
    if(in_array('--export-views',$argv,true)) {
        $export('sale','sales',$salePath.'?id='.$sale); $export('sale-list','sales',$salePath.'?status=all&q='.rawurlencode($prefix));
        $export('invoice','accounting','/modules/contabilidad/index.php?type=customer&id='.$customerInvoice);
        $export('product','inventory','/modules/inventario/index.php?id='.$product);
        $export('dashboard','accounting','/modules/dashboard/index.php');
    }
    echo "PASS: circuito HTTP con Ventas, Compras, Inventario, Contabilidad y Consulta separados; cobro en proceso → parcial → pagado; comisión pagada; stock 8; ingreso 15.40; egreso 34.00; saldo 81.40.\n";
} finally {
    foreach($cookies as $cookie) if(is_file($cookie)) unlink($cookie);
    stopAuthTestServer($server);
}

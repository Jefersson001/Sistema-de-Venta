<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/restaurant-schema.php';
require_once dirname(__DIR__).'/php/restaurant-controller.php';
require_once dirname(__DIR__).'/php/restaurant-receipt.php';
require_once dirname(__DIR__).'/php/company-connection.php';
require_once dirname(__DIR__).'/php/bcv-sync.php';
require_once dirname(__DIR__).'/php/company-repository.php';
function posCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function posReject(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Se aceptó una operación inválida.');}
echo "RUN POS isolated server\n";
$s=startAuthTestServer();$cookies=[];$fixtures=null;
try{
    $raw=$s['db'];$password=bin2hex(random_bytes(12));$login='pos.'.bin2hex(random_bytes(5));$token=prepareFirstAdministrator($raw);
    $actor=createFirstAdministrator($raw,['name'=>'Caja restaurante','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    echo "RUN POS migration\n";
    installRestaurant($raw);bcvApplyPublication($raw,['rate'=>'100.00000000','effective_date'=>commerceToday(),'source'=>'bcv']);
    $c=require dirname(__DIR__).'/php/config/database.php';
    $db=new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$s['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[1],1,$actor,$raw->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN));
    $prefix='POS '.bin2hex(random_bytes(5));$area=restaurantSaveArea($db,['name'=>$prefix,'active'=>1]);
    $table=restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa rápida','capacity'=>4,'active'=>1]);
    $account=restaurantOpenAccount($db,['table_id'=>$table,'table_version'=>1,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    $db->prepare('INSERT INTO inventory_categories(name,restaurant_enabled) VALUES(?,1)')->execute([$prefix]);$restaurantRoot=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,currency_code,product_type,category_id) VALUES(?,1,5,'USD','goods',?)")->execute([$prefix,$restaurantRoot]);$product=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,currency_code,product_type) VALUES(?,1,1,'USD','service')")->execute(['Fuera de restaurante '.$prefix]);$outsideProduct=(int)$db->lastInsertId();
    posReject(fn()=>restaurantCartCommand($db,$account,['action'=>'pos_add','product_id'=>$outsideProduct,'version'=>1,'request_key'=>bin2hex(random_bytes(32))]));
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix]);$warehouse=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,'internal')")->execute([$prefix,$warehouse]);$location=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,10)')->execute([$product,$location]);
    $type=(int)$raw->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix,$type]);$customer=(int)$db->lastInsertId();
    $initialSales=(int)$raw->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn();
    echo "RUN POS cart and historical price\n";
    $add=['action'=>'pos_add','product_id'=>$product,'version'=>1,'request_key'=>bin2hex(random_bytes(32))];
    restaurantCartCommand($db,$account,$add);restaurantCartCommand($db,$account,$add);
    $cart=restaurantCart($db,$account);posCheck(count($cart['items'])===1&&$cart['total']==='5.00','El reintento duplicó productos.');$item=(int)$cart['items'][0]['id'];
    $db->prepare('UPDATE inventory_products SET sale_price=9 WHERE id=?')->execute([$product]);
    restaurantCartCommand($db,$account,['action'=>'pos_quantity','item_id'=>$item,'quantity'=>'2','version'=>2,'request_key'=>bin2hex(random_bytes(32))]);
    posCheck(restaurantCart($db,$account)['total']==='10.00','Se perdió el precio aplicado.');
    posCheck((int)$raw->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn()===$initialSales,'El borrador creó una venta.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);posCheck(bccomp((string)$q->fetchColumn(),'10',3)===0,'El borrador descontó existencias.');
    echo "RUN POS close without consumption discards draft once\n";
    $cancelTable=restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa sin consumo','capacity'=>4,'position_x'=>3,'position_y'=>1,'active'=>1]);
    $cancelAccount=restaurantOpenAccount($db,['table_id'=>$cancelTable,'table_version'=>1,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    restaurantCartCommand($db,$cancelAccount,['action'=>'pos_add','product_id'=>$product,'version'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    $cancelCommand=['action'=>'close_empty_account','version'=>2,'reason'=>'Cliente no acepta el precio','request_key'=>bin2hex(random_bytes(32))];
    restaurantAccountAction($db,$cancelAccount,$cancelCommand);restaurantAccountAction($db,$cancelAccount,$cancelCommand);
    $cancelCart=restaurantCart($db,$cancelAccount);
    posCheck($cancelCart['account']['status']==='closed'&&$cancelCart['items']===[]&&bccomp($cancelCart['total'],'0',2)===0,'El cierre no descartó el borrador.');
    posCheck(restaurantRecord($db,'restaurant_tables',$cancelTable)['service_state']==='cleaning','El cierre no liberó la mesa para limpieza.');
    posCheck((int)$raw->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn()===$initialSales,'Cerrar sin consumo generó una venta.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);posCheck(bccomp((string)$q->fetchColumn(),'10',3)===0,'Cerrar sin consumo alteró existencias.');
    $q=$db->prepare("SELECT details FROM restaurant_audit_events WHERE account_id=? AND action='close_empty_account'");$q->execute([$cancelAccount]);$cancelEvents=$q->fetchAll(PDO::FETCH_COLUMN);
    posCheck(count($cancelEvents)===1&&count(json_decode($cancelEvents[0],true,512,JSON_THROW_ON_ERROR)['discarded_items'])===1,'El cierre no conservó los artículos descartados o duplicó su auditoría.');
    $cash=(int)$db->query("SELECT id FROM accounting_payment_accounts WHERE account_type='cash' AND (currency_code IS NULL OR currency_code='USD') LIMIT 1")->fetchColumn();
    $method=(int)$db->query('SELECT id FROM accounting_payment_methods LIMIT 1')->fetchColumn();
    $checkout=['customer_id'=>$customer,'location_id'=>$location,'account_id'=>$cash,'method_id'=>$method,'version'=>3,'request_key'=>bin2hex(random_bytes(32))];
    echo "RUN POS invoice, payment and stock\n";
    $invoice=restaurantCheckout($db,$account,$checkout);posCheck(restaurantCheckout($db,$account,$checkout)===$invoice,'Se duplicó una factura.');
    $q=$db->prepare('SELECT status,payment_state,total_amount FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);$bill=$q->fetch();
    posCheck($bill['status']==='posted'&&$bill['payment_state']==='paid'&&$bill['total_amount']==='10.00','La factura y cobro no coinciden.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);posCheck(bccomp((string)$q->fetchColumn(),'8',3)===0,'Descuento de stock incorrecto o duplicado.');
    posCheck(restaurantRecord($db,'restaurant_accounts',$account)['status']==='closed','La cuenta no se cerró.');
    posReject(fn()=>restaurantAccountAction($db,$account,['action'=>'close_empty_account','version'=>4,'reason'=>'No debe borrar una venta cobrada','request_key'=>bin2hex(random_bytes(32))]));
    posCheck((int)$raw->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn()===$initialSales+1,'Cantidad incorrecta de ventas.');
    $stockMoves=(int)$raw->query('SELECT COUNT(*) FROM inventory_stock_moves')->fetchColumn();
    restaurantChangeService($db,$table,['version'=>3,'service_state'=>'free','reason'=>'Mesa lista']);
    echo "RUN POS insufficient-stock rollback\n";
    $second=restaurantOpenAccount($db,['table_id'=>$table,'table_version'=>4,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    restaurantCartCommand($db,$second,['action'=>'pos_add','product_id'=>$product,'version'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    $secondCart=restaurantCart($db,$second);restaurantCartCommand($db,$second,['action'=>'pos_quantity','item_id'=>$secondCart['items'][0]['id'],'quantity'=>'99','version'=>2,'request_key'=>bin2hex(random_bytes(32))]);
    posReject(fn()=>restaurantCheckout($db,$second,array_replace($checkout,['version'=>3,'request_key'=>bin2hex(random_bytes(32))])));
    posCheck((int)$raw->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn()===$initialSales+1,'La falta de stock dejó una venta parcial.');
    posCheck((int)$raw->query('SELECT COUNT(*) FROM inventory_stock_moves')->fetchColumn()===$stockMoves,'La falta de stock dejó movimientos parciales.');
    $cookie=tempnam(sys_get_temp_dir(),'nexo-pos-cookie-');$cookies[]=$cookie;$r=authTestRequest($s,'/login.php',$cookie);$r=authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password]);posCheck($r[0]===303,'Login falló.');
    echo "RUN read-only table preview and atomic first product\n";
    $previewTable=restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa para consultar','capacity'=>4,'position_x'=>4,'position_y'=>1,'active'=>1]);
    $previewRoute='/modules/restaurante/cuenta.php?new=1&preview=1&table_id='.$previewTable;
    $beforeAccounts=(int)$db->query('SELECT COUNT(*) FROM restaurant_accounts')->fetchColumn();
    foreach([1,2] as $visit){$previewResponse=authTestRequest($s,$previewRoute,$cookie);posCheck($previewResponse[0]===200&&str_contains($previewResponse[1],'save_first_product'),'No abre la carta sin ocupar la mesa.');}
    posCheck((int)$db->query('SELECT COUNT(*) FROM restaurant_accounts')->fetchColumn()===$beforeAccounts,'Consultar la mesa abrió una cuenta.');
    $start=['csrf'=>authTestToken($previewResponse[1]),'action'=>'save_first_product','table_id'=>$previewTable,'table_version'=>1,'product_id'=>$outsideProduct,'request_key'=>bin2hex(random_bytes(32))];
    $failedStart=authTestRequest($s,$previewRoute,$cookie,$start);posCheck($failedStart[0]===422,'Se aceptó un producto fuera de la carta.');
    posCheck((int)$db->query('SELECT COUNT(*) FROM restaurant_accounts')->fetchColumn()===$beforeAccounts,'El primer producto fallido dejó una cuenta abierta.');
    $start['product_id']=$product;$start['request_key']=bin2hex(random_bytes(32));
    foreach([1,2] as $attempt){$started=authTestRequest($s,$previewRoute,$cookie,$start);posCheck($started[0]===303,'No se agregó el primer producto: '.$started[0]);}
    posCheck((int)$db->query('SELECT COUNT(*) FROM restaurant_accounts')->fetchColumn()===$beforeAccounts+1,'El reintento duplicó la primera cuenta.');
    $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$previewTable]);$startedAccount=(int)$q->fetchColumn();
    $startedCart=restaurantCart($db,$startedAccount);posCheck(count($startedCart['items'])===1&&bccomp($startedCart['items'][0]['quantity'],'1',3)===0,'El reintento duplicó el primer producto.');
    posReject(fn()=>restaurantStartOrder($db,array_replace($start,['request_key'=>bin2hex(random_bytes(32))]),$actor));
    $r=authTestRequest($s,'/modules/restaurante/cuenta.php?id='.$second,$cookie);posCheck($r[0]===200&&str_contains($r[1],'data-pos-root')&&str_contains($r[1],'Facturar y cobrar'),'No se ve el pedido directo.');
    $r=authTestRequest($s,'/modules/restaurante/cuenta.php?id='.$account,$cookie);posCheck($r[0]===200&&str_contains($r[1],'Factura emitida y cobro registrado.'),'No se ve el comprobante.');
    $closeResponse=authTestRequest($s,'/modules/restaurante/cuenta.php?id='.$cancelAccount,$cookie,array_merge($cancelCommand,['csrf'=>authTestToken($r[1])]));
    posCheck($closeResponse[0]===303&&str_ends_with($closeResponse[2],'/modules/restaurante/index.php'),'El cierre sin consumo no vuelve al mapa al reintentar.');
    echo "RUN POS JSON commands and lost-response retry\n";
    $jsonCommand=['csrf'=>authTestToken($r[1]),'company_scope'=>authTestToken($r[1],'company_scope'),'action'=>'pos_note',
        'item_id'=>$secondCart['items'][0]['id'],'notes'=>'Sin cebolla','version'=>3,'request_key'=>bin2hex(random_bytes(32))];
    foreach([1,2] as $attempt){
        $curl=curl_init($s['url'].'/modules/restaurante/cuenta.php?id='.$second);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_POSTFIELDS=>http_build_query($jsonCommand)]);
        $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        $result=is_string($response)?json_decode($response,true):null;
        posCheck($status===200&&($result['ok']??false)&&str_contains($result['html'],'Sin cebolla')&&(int)$result['version']===4,'No funcionó el guardado JSON o su reintento: '.(string)$response);
    }
    echo "RUN restaurant category flag and inherited selection\n";
    $categoryRoute='/modules/inventario/configuracion.php?section=categories';
    $r=authTestRequest($s,$categoryRoute.'&new=1',$cookie);
    posCheck($r[0]===200&&str_contains($r[1],'name="restaurant_enabled"'),'Falta el check de restaurante.');
    $categoryToken=authTestToken($r[1]);$categoryName='Categoría HTTP '.$prefix;
    $r=authTestRequest($s,$categoryRoute.'&new=1',$cookie,['csrf'=>$categoryToken,'action'=>'save_category','name'=>$categoryName,'parent_id'=>'','restaurant_enabled'=>'1']);posCheck($r[0]===303,'No se guardó el check.');
    $q=$db->prepare('SELECT id,restaurant_enabled FROM inventory_categories WHERE name=?');$q->execute([$categoryName]);$markedRoot=$q->fetch();posCheck((int)$markedRoot['restaurant_enabled']===1,'No se conservó el check de la categoría principal.');
    $r=authTestRequest($s,$categoryRoute.'&new=1',$cookie,['csrf'=>$categoryToken,'action'=>'save_category','name'=>'Hija HTTP '.$prefix,'parent_id'=>$markedRoot['id'],'restaurant_enabled'=>'1']);posCheck($r[0]===303,'No se creó la subcategoría.');
    $q=$db->prepare('SELECT id,restaurant_enabled FROM inventory_categories WHERE parent_id=?');$q->execute([$markedRoot['id']]);$child=$q->fetch();posCheck((int)$child['restaurant_enabled']===0,'La subcategoría conservó un check independiente.');
    posCheck(in_array((int)$child['id'],inventoryRestaurantCategoryIds(loadInventoryCategories($db)),true),'La subcategoría no heredó su relación principal.');
    $r=authTestRequest($s,$categoryRoute.'&id='.$markedRoot['id'],$cookie,['csrf'=>$categoryToken,'action'=>'save_category','name'=>$categoryName,'parent_id'=>'']);posCheck($r[0]===303,'No se pudo desmarcar la categoría.');
    posCheck(!in_array((int)$child['id'],inventoryRestaurantCategoryIds(loadInventoryCategories($db)),true),'Desmarcar la principal no retiró sus descendientes.');
    echo "RUN mixed payments, change, invalid totals and transactional rollback\n";
    $cashMethod=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE allow_change=1 LIMIT 1")->fetchColumn();
    $manualMethod=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES(?,'bank','USD')")->execute(['Banco '.$prefix]);$bank=(int)$db->lastInsertId();
    $collectionArea=restaurantSaveArea($db,['name'=>'Cobros '.$prefix,'active'=>1]);
    $newCollection=static function(string $name,int $column,int $productId)use($db,$collectionArea,$actor):int{
        $tableId=restaurantSaveTable($db,['area_id'=>$collectionArea,'name'=>$name,'position_x'=>$column,'position_y'=>1,'capacity'=>4,'active'=>1]);
        $accountId=restaurantOpenAccount($db,['table_id'=>$tableId,'table_version'=>1,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);
        restaurantCartCommand($db,$accountId,['action'=>'pos_add','product_id'=>$productId,'version'=>1,'request_key'=>bin2hex(random_bytes(32))]);return $accountId;
    };
    $mixedAccount=$newCollection('Mixto',1,$product);
    $mixed=['customer_id'=>$customer,'location_id'=>$location,'version'=>2,'request_key'=>bin2hex(random_bytes(32)),'split_mode'=>'amount','payments'=>[
        ['label'=>'Ana','method_id'=>$cashMethod,'account_id'=>$cash,'amount'=>'4.00','received_amount'=>'5.00'],
        ['label'=>'Luis','method_id'=>$manualMethod,'account_id'=>$bank,'amount'=>'5.00']]];
    $beforeMixed=(int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn();
    $beforeMixedStock=(int)$db->query('SELECT COUNT(*) FROM inventory_stock_moves')->fetchColumn();
    $beforeMixedOperations=(int)$db->query('SELECT COUNT(DISTINCT operation_id) FROM inventory_stock_moves')->fetchColumn();
    $badMixed=$mixed;$badMixed['payments'][1]['amount']='4.99';posReject(fn()=>restaurantCheckout($db,$mixedAccount,$badMixed));
    $badMixed=$mixed;$badMixed['payments'][1]['amount']='5.01';posReject(fn()=>restaurantCheckout($db,$mixedAccount,$badMixed));
    $badMixed=$mixed;$badMixed['payments'][1]['received_amount']='6.00';posReject(fn()=>restaurantCheckout($db,$mixedAccount,$badMixed));
    posCheck((int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn()===$beforeMixed,'Un cobro inválido dejó pagos parciales.');
    posCheck((int)$db->query('SELECT COUNT(*) FROM inventory_stock_moves')->fetchColumn()===$beforeMixedStock,'Un fallo de vuelto dejó una entrega parcial.');
    $mixedInvoice=restaurantCheckout($db,$mixedAccount,$mixed);posCheck(restaurantCheckout($db,$mixedAccount,$mixed)===$mixedInvoice,'El reintento de pagos mixtos duplicó factura.');
    $mixedReceipt=restaurantLoadReceipt($db,$mixedAccount);
    posCheck(count($mixedReceipt['payments'])===2&&$mixedReceipt['invoice']['payment_state']==='paid','No se registraron ambos medios de pago.');
    posCheck($mixedReceipt['payments'][0]['received_amount']==='5.00'&&$mixedReceipt['payments'][0]['change_amount']==='1.00'&&$mixedReceipt['payments'][0]['amount']==='4.00','El efectivo y el cambio no coinciden con el neto aplicado.');
    posCheck((int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn()===$beforeMixed+2,'Se duplicaron pagos al reintentar.');
    posCheck((int)$db->query('SELECT COUNT(DISTINCT operation_id) FROM inventory_stock_moves')->fetchColumn()===$beforeMixedOperations+1,'El reparto duplicó la entrega de inventario.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);posCheck(bccomp((string)$q->fetchColumn(),'7',3)===0,'El reparto descontó más unidades que las consumidas.');
    $changedRetry=$mixed;$changedRetry['payments'][0]['received_amount']='6.00';posReject(fn()=>restaurantCheckout($db,$mixedAccount,$changedRetry));
    $r=authTestRequest($s,'/modules/restaurante/comprobante.php?id='.$mixedAccount,$cookie);
    posCheck($r[0]===200&&str_contains($r[1],'Guardar PDF')&&str_contains($r[1],'Ana')&&str_contains($r[1],'Cambio: 1.00 USD'),'No se puede consultar el comprobante con reparto y vuelto.');
    posCheck(authTestRequest($s,'/modules/restaurante/comprobante.php?id='.$second,$cookie)[0]===404,'Se imprimió una mesa sin facturar.');
    echo "RUN product division, fractional quantities and exact tax rounding\n";
    $db->prepare("INSERT INTO business_taxes(name,module_key,rate) VALUES(?,'ventas',16)")->execute(['IVA cobro '.$prefix]);$splitTax=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,currency_code,product_type,category_id,sale_tax_id) VALUES(?,1,3.33,'USD','service',?,?)")->execute(['Servicio divisible '.bin2hex(random_bytes(5)),$restaurantRoot,$splitTax]);$splitProduct=(int)$db->lastInsertId();
    $splitAccount=$newCollection('Por productos',2,$splitProduct);$splitCart=restaurantCart($db,$splitAccount);$splitItem=$splitCart['items'][0]['id'];
    $split=['customer_id'=>$customer,'location_id'=>$location,'version'=>2,'request_key'=>bin2hex(random_bytes(32)),'split_mode'=>'products','payments'=>[
        ['label'=>'Persona 1','method_id'=>$cashMethod,'account_id'=>$cash,'amount'=>'999.00','items'=>[$splitItem=>'0.333']],
        ['label'=>'Persona 2','method_id'=>$manualMethod,'account_id'=>$bank,'amount'=>'999.00','items'=>[$splitItem=>'0.667']]]];
    $splitPlan=restaurantPaymentPlan($splitCart,$split);posCheck(array_column($splitPlan,'amount')===['1.29','2.57'],'El reparto perdió céntimos de impuestos o confió en importes del navegador.');
    $badSplit=$split;$badSplit['payments'][1]['items'][$splitItem]='0.666';posReject(fn()=>restaurantCheckout($db,$splitAccount,$badSplit));
    $badSplit=$split;$badSplit['payments'][1]['items'][$splitItem]='0.668';posReject(fn()=>restaurantCheckout($db,$splitAccount,$badSplit));
    $badSplit=$split;$badSplit['payments'][1]['items'][999999]='1';posReject(fn()=>restaurantCheckout($db,$splitAccount,$badSplit));
    $splitInvoice=restaurantCheckout($db,$splitAccount,$split);posCheck(restaurantCheckout($db,$splitAccount,$split)===$splitInvoice,'El reparto por productos duplicó la factura.');
    $splitReceipt=restaurantLoadReceipt($db,$splitAccount);
    posCheck($splitReceipt['invoice']['total_amount']==='3.86'&&$splitReceipt['invoice']['payment_state']==='paid'&&array_column($splitReceipt['payments'],'amount')===['1.29','2.57'],'El reparto no cubrió exactamente la factura.');
    echo "RUN checkout endpoint, lost response and protected receipt\n";
    $httpAccount=$newCollection('Cobro HTTP',3,$splitProduct);
    $httpCheckout=['csrf'=>$jsonCommand['csrf'],'company_scope'=>$jsonCommand['company_scope'],'action'=>'pos_checkout','customer_id'=>$customer,'location_id'=>$location,'version'=>2,'request_key'=>bin2hex(random_bytes(32)),
        'split_mode'=>'full','payments'=>[['method_id'=>$manualMethod,'account_id'=>$bank,'amount'=>'3.86']]];
    foreach([1,2] as $attempt){
        $curl=curl_init($s['url'].'/modules/restaurante/cuenta.php?id='.$httpAccount);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_POSTFIELDS=>http_build_query($httpCheckout)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$result=json_decode((string)$body,true);
        posCheck($status===200&&($result['closed']??false)&&$result['receipt_url']==='comprobante.php?id='.$httpAccount,'El cobro no devuelve su comprobante o falla al reintentar: '.(string)$body);
    }
    $raw->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Mesero de cobros '.$prefix,'Solo cuentas propias']);$receiptGroup=(int)$raw->lastInsertId();
    $raw->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'restaurante.accounts.read','own')")->execute([$receiptGroup]);
    $receiptLogin='receipt.'.bin2hex(random_bytes(5));
    $raw->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Mesero ajeno',$receiptLogin,password_hash($password,PASSWORD_DEFAULT)]);$receiptUser=(int)$raw->lastInsertId();
    $raw->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$receiptUser,$receiptGroup]);$raw->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$receiptUser]);
    $limitedCookie=tempnam(sys_get_temp_dir(),'nexo-receipt-cookie-');$cookies[]=$limitedCookie;
    $r=authTestRequest($s,'/login.php',$limitedCookie);$r=authTestRequest($s,'/login.php',$limitedCookie,['csrf'=>authTestToken($r[1]),'login'=>$receiptLogin,'password'=>$password]);posCheck($r[0]===303,'No inicia sesión el mesero de prueba.');
    posCheck(authTestRequest($s,'/modules/restaurante/comprobante.php?id='.$mixedAccount,$limitedCookie)[0]===403,'Se filtró un comprobante de otra cuenta al mesero.');
    echo "RUN POS initial table setup\n";
    $otherCompany=createBusinessCompany($raw,['name'=>'Prueba de inicio rápido'],$actor);
    $other=new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$s['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[$otherCompany],$otherCompany,$actor,$raw->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN));
    posReject(fn()=>restaurantLoadReceipt($other,$mixedAccount));
    $GLOBALS['company_context']=['active'=>$otherCompany];$setup=['table_count'=>3,'request_key'=>bin2hex(random_bytes(32))];
    $setupArea=restaurantQuickSetup($other,$setup);posCheck(restaurantQuickSetup($other,$setup)===$setupArea,'El reintento duplicó la configuración.');
    posCheck((int)$other->query('SELECT COUNT(*) FROM restaurant_tables')->fetchColumn()===3,'La creación rápida no generó las mesas solicitadas.');
    posReject(fn()=>restaurantQuickSetup($other,['table_count'=>2,'request_key'=>bin2hex(random_bytes(32))]));unset($GLOBALS['company_context']);
    if(isset($argv[1])){
        restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa libre para traslado','capacity'=>4,'position_x'=>2,'position_y'=>1,'active'=>1]);
        $addCategory=static function(string $name,?int $parent=null,bool $enabled=false)use($db):int{
            $db->prepare('INSERT INTO inventory_categories(name,parent_id,restaurant_enabled) VALUES(?,?,?)')->execute([$name,$parent,(int)$enabled]);return (int)$db->lastInsertId();
        };
        $rootCategory=$restaurantRoot;$kitchenCategory=$addCategory('Cocina',$rootCategory);
        $chickenCategory=$addCategory('Pollo',$kitchenCategory);$drinksCategory=$addCategory('Bebidas',$rootCategory);$barCategory=$addCategory('Bar '.$prefix,null,true);
        $db->prepare('UPDATE inventory_products SET category_id=? WHERE id=?')->execute([$chickenCategory,$product]);
        $addCategoryProduct=static function(string $name,?int $category)use($db):int{
            $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,product_type,currency_code,category_id) VALUES(?,1,2,'service','USD',?)")->execute([$name.' '.bin2hex(random_bytes(5)),$category]);return (int)$db->lastInsertId();
        };
        $drinkProduct=$addCategoryProduct('Refresco',$drinksCategory);$directProduct=$addCategoryProduct('Servicio de mesa',$rootCategory);
        $barProduct=$addCategoryProduct('Bebida del bar',$barCategory);$uncategorizedProduct=$addCategoryProduct('Sin clasificar',null);
        $shopCategory=$addCategory('Tienda '.$prefix);$shopProduct=$addCategoryProduct('Producto de tienda',$shopCategory);
        $categoryFixture=['root'=>$rootCategory,'kitchen'=>$kitchenCategory,'chicken'=>$chickenCategory,'drinks'=>$drinksCategory,'bar'=>$barCategory,
            'rootProducts'=>[$product,$drinkProduct,$directProduct,$splitProduct],'chickenProducts'=>[$product],'drinksProducts'=>[$drinkProduct],'barProducts'=>[$barProduct],'uncategorizedProduct'=>$uncategorizedProduct,'shopCategory'=>$shopCategory,'shopProduct'=>$shopProduct];
        $fixtures=sys_get_temp_dir().'/nexo-pos-fixtures-'.bin2hex(random_bytes(6));mkdir($fixtures,0700);
        $css=preg_replace('/^@import[^\r\n]+[\r\n]*/m','',file_get_contents(dirname(__DIR__).'/css/app.css')).file_get_contents(dirname(__DIR__).'/css/modules.css').file_get_contents(dirname(__DIR__).'/css/restaurant.css');
        $js=file_get_contents(dirname(__DIR__).'/js/app.js')."\n".file_get_contents(dirname(__DIR__).'/js/modules.js')."\n".file_get_contents(dirname(__DIR__).'/js/navigation.js')."\n".file_get_contents(dirname(__DIR__).'/js/restaurant.js')."\n".file_get_contents(dirname(__DIR__).'/js/restaurant-checkout.js');
        foreach(['map'=>'/modules/restaurante/index.php','pos'=>'/modules/restaurante/cuenta.php?id='.$second,'receipt'=>'/modules/restaurante/comprobante.php?id='.$mixedAccount] as $name=>$route){
            echo 'RENDER POS '.$name.PHP_EOL;$r=authTestRequest($s,$route,$cookie);posCheck($r[0]===200,'Vista no disponible.');
            $fixtureCss=$name==='receipt'?file_get_contents(dirname(__DIR__).'/css/restaurant-receipt.css'):$css;
            $fixtureJs=$name==='receipt'?file_get_contents(dirname(__DIR__).'/js/restaurant-receipt.js'):$js;
            $html=preg_replace('~<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>|<link\b[^>]*rel="stylesheet"[^>]*>~i','',$r[1]);$html=str_replace('</head>','<style>'.$fixtureCss.'</style></head>',$html);$html=str_replace('</body>','<script type="application/json" id="pos-category-fixture">'.json_encode($categoryFixture,JSON_THROW_ON_ERROR).'</script><script>'.$fixtureJs.'</script></body>',$html);file_put_contents($fixtures.'/'.$name.'.html',$html);
        }
        $p=proc_open(['node',__DIR__.'/restaurant-ui.js',$fixtures,$argv[1],'pos'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);posCheck(proc_close($p)===0,'Falló la revisión visual POS.');
    }
    echo "PASS POS: precio histórico, reintentos, borradores sin consumo, venta/factura/cobro únicos, stock, rollback completo y vistas HTTP.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");throw $e;}
finally{unset($GLOBALS['company_context']);foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);if($fixtures){foreach(glob($fixtures.'/*.html') as $file)unlink($file);rmdir($fixtures);}stopAuthTestServer($s);}

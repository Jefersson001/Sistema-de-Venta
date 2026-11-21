<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function dpCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$server=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'direct-purchase-');
try{
    $db=$server['db'];$migration=require dirname(__DIR__).'/php/migrations/versioned/20261121163558_compras_directas.php';$migration($db);
    $token=prepareFirstAdministrator($db);$login='purchase.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));
    $actor=createFirstAdministrator($db,['name'=>'Compras QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$actor);
    $supplierType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='supplier' LIMIT 1")->fetchColumn();
    $db->prepare('INSERT INTO contacts(name,type_id,phone) VALUES(?,?,?)')->execute(['Proveedor QA directo',$supplierType,'04121234567']);$supplier=(int)$db->lastInsertId();
    $db->exec("INSERT INTO inventory_warehouses(name) VALUES('Compras QA')");$warehouse=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES('Destino QA',?,'internal')")->execute([$warehouse]);$location=(int)$db->lastInsertId();
    $db->exec("INSERT INTO accounting_payment_terms(name,days) VALUES('Crédito QA 15 días',15)");$term=(int)$db->lastInsertId();
    $db->exec("INSERT INTO inventory_products(name,purchase_enabled,sale_enabled,product_type,cost_price,currency_code,invoice_policy) VALUES('Producto compra rápida',1,1,'goods',5,'USD','delivered')");$product=(int)$db->lastInsertId();
    $db->exec("INSERT INTO inventory_products(name,purchase_enabled,product_type,cost_price,currency_code,tracking_mode,expiration_enabled) VALUES('Lote compra rápida',1,'goods',5,'USD','lot',1)");$lotProduct=(int)$db->lastInsertId();
    require_once dirname(__DIR__).'/php/bcv-sync.php';bcvApplyPublication($db,['rate'=>'100.00','effective_date'=>date('Y-m-d'),'source'=>'bcv']);
    [, $html]=authTestRequest($server,'/login.php',$cookie);dpCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'Login failed.');
    $path='/modules/compra_directa/index.php';[, $html]=authTestRequest($server,$path,$cookie);
    dpCheck(str_contains($html,'data-purchase-checkout')&&str_contains($html,'Proveedor QA directo'),'Purchase screen missing.');
    $post=['action'=>'direct_purchase','csrf'=>authTestToken($html),'company_scope'=>authTestToken($html,'company_scope'),'request_key'=>bin2hex(random_bytes(32)),'supplier_id'=>(string)$supplier,'destination_location_id'=>(string)$location,'payment_term_id'=>(string)$term,'currency_code'=>'USD','order_date'=>date('Y-m-d'),'lines_json'=>json_encode([['product_id'=>$product,'quantity'=>'3','unit_price'=>'7.50','tax_id'=>'']])];
    dpCheck(authTestRequest($server,$path,$cookie,array_replace($post,['csrf'=>'invalid']))[0]===403,'CSRF accepted.');
    $result=authTestRequest($server,$path,$cookie,$post);dpCheck($result[0]===303,'Checkout failed: '.mb_substr(strip_tags($result[1]),-1500));preg_match('/[?&]id=(\d+)/',$result[2],$match);$checkout=(int)($match[1]??0);dpCheck($checkout>0,'Checkout ID missing.');
    $q=$db->prepare('SELECT * FROM direct_purchase_checkouts WHERE id=?');$q->execute([$checkout]);$row=$q->fetch();
    dpCheck($db->query('SELECT status FROM purchase_orders WHERE id='.(int)$row['purchase_id'])->fetchColumn()==='purchase','Order not confirmed.');
    dpCheck($db->query('SELECT status FROM inventory_operations WHERE id='.(int)$row['operation_id'])->fetchColumn()==='done','Receipt not done.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);dpCheck(bccomp($q->fetchColumn(),'3',2)===0,'Stock not received.');
    $invoice=$db->query('SELECT * FROM accounting_invoices WHERE id='.(int)$row['invoice_id'])->fetch();
    dpCheck($invoice['status']==='posted'&&$invoice['payment_state']==='not_paid'&&bccomp($invoice['total_amount'],'22.50',2)===0,'Supplier invoice incorrect.');
    dpCheck($invoice['due_date']===(new DateTimeImmutable(date('Y-m-d')))->modify('+15 days')->format('Y-m-d'),'Credit due date incorrect.');
    $retry=authTestRequest($server,$path,$cookie,$post);dpCheck($retry[0]===303&&$retry[2]===$result[2],'Retry duplicated checkout.');
    dpCheck(authTestRequest($server,$path,$cookie,array_replace($post,['currency_code'=>'VES']))[0]===409,'Changed payload reused key.');
    $before=(int)$db->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
    $bad=$post;$bad['request_key']=bin2hex(random_bytes(32));$bad['lines_json']=json_encode([['product_id'=>$lotProduct,'quantity'=>'2','unit_price'=>'5','tracking'=>[]]]);
    dpCheck(authTestRequest($server,$path,$cookie,$bad)[0]===422,'Unidentified lot received.');
    dpCheck((int)$db->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn()===$before,'Failed reception left an order.');
    $bad['lines_json']=json_encode([['product_id'=>$lotProduct,'quantity'=>'2','unit_price'=>'5','tracking'=>[['name'=>'LOTE-QA','quantity'=>'2','expiration_date'=>'2030-01-01']]]]);
    $lotResponse=authTestRequest($server,$path,$cookie,$bad);dpCheck($lotResponse[0]===303,'Lot receipt failed: '.mb_substr(strip_tags($lotResponse[1]),-1200));
    $db->exec("INSERT INTO business_taxes(name,module_key,rate) VALUES('IVA QA','compras',16)");$tax=(int)$db->lastInsertId();
    $db->exec("INSERT INTO inventory_products(name,purchase_enabled,product_type,cost_price,currency_code,tracking_mode) VALUES('Serie compra rápida',1,'goods',5,'USD','serial')");$serialProduct=(int)$db->lastInsertId();
    $series=$post;$series['request_key']=bin2hex(random_bytes(32));$series['lines_json']=json_encode([['product_id'=>$serialProduct,'quantity'=>'2','unit_price'=>'5','tax_id'=>(string)$tax,'tracking'=>[['name'=>'SERIE-QA-1','quantity'=>'1'],['name'=>'SERIE-QA-2','quantity'=>'1']]]]);
    $serialResponse=authTestRequest($server,$path,$cookie,$series);dpCheck($serialResponse[0]===303,'Serial receipt failed: '.mb_substr(strip_tags($serialResponse[1]),-1200));preg_match('/[?&]id=(\d+)/',$serialResponse[2],$serialMatch);
    $serialInvoice=$db->query('SELECT i.total_amount FROM direct_purchase_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.id='.(int)$serialMatch[1])->fetchColumn();dpCheck(bccomp($serialInvoice,'11.60',2)===0,'Purchase tax incorrect.');
    $before=(int)$db->query('SELECT COUNT(*) FROM direct_purchase_checkouts')->fetchColumn();$series['request_key']=bin2hex(random_bytes(32));
    dpCheck(authTestRequest($server,$path,$cookie,$series)[0]===422,'Duplicate serial received twice.');dpCheck((int)$db->query('SELECT COUNT(*) FROM direct_purchase_checkouts')->fetchColumn()===$before,'Duplicate serial left a checkout.');
    $db->exec("INSERT INTO inventory_products(name,purchase_enabled,product_type,cost_price,currency_code) VALUES('Servicio compra rápida',1,'service',5,'USD')");$serviceProduct=(int)$db->lastInsertId();
    $service=$post;$service['request_key']=bin2hex(random_bytes(32));$service['lines_json']=json_encode([['product_id'=>$serviceProduct,'quantity'=>'1','unit_price'=>'5']]);$serviceResponse=authTestRequest($server,$path,$cookie,$service);dpCheck($serviceResponse[0]===303,'Service purchase failed.');preg_match('/[?&]id=(\d+)/',$serviceResponse[2],$serviceMatch);dpCheck($db->query('SELECT operation_id FROM direct_purchase_checkouts WHERE id='.(int)$serviceMatch[1])->fetchColumn()===null,'Service created a stock receipt.');
    $records=$db->query('SELECT * FROM direct_purchase_checkouts ORDER BY id')->fetchAll();$migration($db);dpCheck($records===$db->query('SELECT * FROM direct_purchase_checkouts ORDER BY id')->fetchAll(),'Repeated migration altered data.');
    [, $html]=authTestRequest($server,$path.'?id='.$checkout,$cookie);dpCheck(str_contains($html,'Factura / registrar pago')&&str_contains($html,'04121234567'),'Detail links or phone missing.');
    [, $home]=authTestRequest($server,'/index.php',$cookie);dpCheck(str_contains($home,'data-module="compra_directa"'),'Module missing from launcher.');
    require_once dirname(__DIR__).'/php/company-repository.php';
    $other=createBusinessCompany($db,['name'=>'Otra empresa de prueba'],$actor);$db->exec('SET @nexo_company_id='.$other.',@nexo_actor_id='.$actor);
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Proveedor ajeno',$supplierType]);$foreignSupplier=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id=1');
    $foreign=$post;$foreign['request_key']=bin2hex(random_bytes(32));$foreign['supplier_id']=(string)$foreignSupplier;
    dpCheck(authTestRequest($server,$path,$cookie,$foreign)[0]===422,'Accepted foreign company supplier.');
    $db->exec("INSERT INTO access_groups(name,system_key) VALUES('Consulta compras QA','dp_qa_readonly')");$readGroup=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'compra_directa.purchases.read','all')")->execute([$readGroup]);
    $readUser=saveAuthUser($db,['name'=>'Consulta compras','login'=>$login.'.read','active'=>'1','groups'=>[$readGroup],'company_ids'=>[1],'password'=>$password,'password_confirmation'=>$password],null,null,$actor,bin2hex(random_bytes(32)));$db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$readUser]);
    $readCookie=tempnam(sys_get_temp_dir(),'purchase-read-');
    try{
        [, $loginForm]=authTestRequest($server,'/login.php',$readCookie);authTestRequest($server,'/login.php',$readCookie,['csrf'=>authTestToken($loginForm),'login'=>$login.'.read','password'=>$password]);
        [, $readForm]=authTestRequest($server,$path.'?view=history',$readCookie);dpCheck(!str_contains($readForm,'data-purchase-checkout')&&str_contains($readForm,'Proveedor QA directo'),'Readonly history incorrect.');
        $denied=$post;$denied['request_key']=bin2hex(random_bytes(32));$denied['csrf']=authTestToken($readForm);$denied['company_scope']=authTestToken($readForm,'company_scope');dpCheck(authTestRequest($server,$path,$readCookie,$denied)[0]===403,'Readonly user purchased.');
    }finally{unlink($readCookie);}
    if(in_array('--browser',$argv,true)){
        $env=getenv();foreach(['NEXO_TEST_BASE_URL'=>$server['url'],'NEXO_TEST_LOGIN'=>$login,'NEXO_TEST_PASSWORD'=>$password,'NEXO_PURCHASE_SUPPLIER'=>$supplier,'NEXO_PURCHASE_LOCATION'=>$location,'NEXO_PURCHASE_TERM'=>$term,'NEXO_PURCHASE_PRODUCT'=>$product] as $key=>$value)$env[$key]=(string)$value;
        $process=proc_open(['node',__DIR__.'/direct-purchases-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);dpCheck(proc_close($process)===0,'Browser purchase failed.');
    }
    echo "PASS: compra directa, orden/recepción/factura nativas, stock, crédito, teléfono, reintento sin duplicados, CSRF, rollback por lote faltante, recepción con vencimiento y migración repetida con datos previos.\n";
}finally{if(is_file($cookie))unlink($cookie);stopAuthTestServer($server);}

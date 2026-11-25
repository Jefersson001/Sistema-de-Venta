<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/company-repository.php';
$server=null;$cookie=tempnam(sys_get_temp_dir(),'cart-qa-');
try{
    $server=startAuthTestServer();$db=$server['db'];$password=bin2hex(random_bytes(14));$login='cart.'.bin2hex(random_bytes(6));
    $token=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>'Carrito QA','login'=>$login.'.admin','password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$admin);
    $insert=static function(string $table,array $data)use($db):int{$db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$db->lastInsertId();};
    $warehouse=$insert('inventory_warehouses',['name'=>'Carrito QA']);$location=$insert('inventory_locations',['name'=>'Carrito QA','warehouse_id'=>$warehouse,'location_type'=>'internal']);
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $seller=$insert('contacts',['name'=>'Vendedor carrito QA','type_id'=>$types['salesperson'],'warehouse_id'=>$warehouse,'location_id'=>$location]);
    $customer=$insert('contacts',['name'=>'Cliente carrito QA','type_id'=>$types['client'],'salesperson_id'=>$seller]);
    $tax=$insert('business_taxes',['name'=>'IVA carrito QA','module_key'=>'ventas','rate'=>'10']);
    $product=$insert('inventory_products',['name'=>'AAA Carrito QA','sale_enabled'=>1,'product_type'=>'goods','sale_price'=>'5','cost_price'=>'3','invoice_policy'=>'delivered','commission_type'=>'fixed','commission_value'=>'2','sale_tax_id'=>$tax,'currency_code'=>'USD']);
    $insert('inventory_stock',['product_id'=>$product,'location_id'=>$location,'quantity'=>'10']);
    $serial=$insert('inventory_products',['name'=>'AAA Serie carrito QA','sale_enabled'=>1,'product_type'=>'goods','sale_price'=>'5','invoice_policy'=>'delivered','tracking_mode'=>'serial','currency_code'=>'USD']);
    $insert('accounting_payment_terms',['name'=>'Contado QA','days'=>0]);$term=$insert('accounting_payment_terms',['name'=>'Credito QA','days'=>15]);
    $insert('accounting_payment_accounts',['name'=>'Caja carrito QA USD','account_type'=>'cash','currency_code'=>'USD']);
    $group=$db->query("SELECT id FROM access_groups WHERE system_key='direct_sales'")->fetchColumn();
    $actor=saveAuthUser($db,['name'=>'Vendedor carrito QA','login'=>$login,'active'=>'1','groups'=>[$group],'company_ids'=>[1],'contact_id'=>$seller,'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$actor]);
    foreach(range(1,100) as $n){$id=$insert('inventory_products',['name'=>'ZZ Carrito catalog '.str_pad((string)$n,3,'0',STR_PAD_LEFT),'sale_enabled'=>1,'product_type'=>'goods','sale_price'=>'1','currency_code'=>'USD']);$insert('inventory_stock',['product_id'=>$id,'location_id'=>$location,'quantity'=>'2']);}
    require_once dirname(__DIR__).'/php/bcv-sync.php';bcvApplyPublication($db,['rate'=>'100.00','effective_date'=>commerceToday(),'source'=>'bcv']);
    [, $form]=authTestRequest($server,'/login.php',$cookie);authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($form),'login'=>$login,'password'=>$password]);
    $path='/modules/venta_directa/index.php';[, $form]=authTestRequest($server,$path,$cookie);$used=bin2hex(random_bytes(32));
    $result=authTestRequest($server,$path,$cookie,['csrf'=>authTestToken($form),'action'=>'direct_checkout','request_key'=>$used,'seller_id'=>(string)$seller,'customer_id'=>(string)$customer,'payment_term_id'=>(string)$term,'lines_json'=>json_encode([['product_id'=>$product,'quantity'=>'1.00']])]);
    if($result[0]!==303)throw new RuntimeException('Could not seed previously used cart key: '.mb_substr(strip_tags($result[1]),-1000));
    $env=getenv();foreach(['NEXO_TEST_BASE_URL'=>$server['url'],'NEXO_TEST_LOGIN'=>$login,'NEXO_TEST_PASSWORD'=>$password,'NEXO_DIRECT_CUSTOMER'=>$customer,'NEXO_DIRECT_PRODUCT'=>$product,'NEXO_DIRECT_SERIAL_PRODUCT'=>$serial,'NEXO_DIRECT_USED_KEY'=>$used] as $key=>$value)$env[$key]=(string)$value;
    $process=proc_open(['node',__DIR__.'/direct-sales-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Cart browser regression failed.');
    echo "PASS: isolated stale-key regression with actual sale, unchanged reload, completed cart recovery and product requests.\n";
}finally{if($server)stopAuthTestServer($server);if(is_file($cookie))unlink($cookie);}

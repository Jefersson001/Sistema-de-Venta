<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/company-repository.php';
require_once dirname(__DIR__).'/php/direct-sales-repository.php';
function dsExpect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dsId(array $response):int{dsExpect($response[0]===303&&preg_match('/[?&]id=(\d+)/',$response[2],$m)===1,'No se completó la operación: HTTP '.$response[0].' '.mb_substr(strip_tags($response[1]),-1400));return (int)$m[1];}
$server=startAuthTestServer();$cookies=[];
try{
    $db=$server['db'];$prefix='Direct-'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));
    $token=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>$prefix,'login'=>strtolower($prefix),'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $baseMigration=require dirname(__DIR__).'/php/migrations/versioned/20261113131544_ventas_directas.php';
    $supervisionMigration=require dirname(__DIR__).'/php/migrations/versioned/20261113161546_separar_supervision_ventas_directas.php';
    $operationsMigration=require dirname(__DIR__).'/php/migrations/versioned/20261113171011_completar_operacion_venta_directa.php';
    $baseMigration($db);$supervisionMigration($db);
    // Existing requests outside company 1 must migrate with that company's context.
    $legacyCompany=createBusinessCompany($db,['name'=>'ZZZ '.$prefix.' previa'],$admin);$db->exec('SET @nexo_company_id='.$legacyCompany);
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix.' previo']);$legacyWarehouse=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,'internal')")->execute([$prefix.' previa',$legacyWarehouse]);$legacyLocation=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO contacts(name,type_id,warehouse_id,location_id) SELECT ?,id,?,? FROM contact_types WHERE system_key='salesperson'")->execute([$prefix.' previo',$legacyWarehouse,$legacyLocation]);$legacySeller=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO direct_stock_requests(seller_id,destination_location_id,request_key,payload_hash,notes) VALUES(?,?,?,?,?)')->execute([$legacySeller,$legacyLocation,bin2hex(random_bytes(32)),str_repeat('d',64),'Solicitud previa multiempresa']);$legacyRequest=(int)$db->lastInsertId();
    $db->exec('SET @nexo_company_id=1');$migration=$operationsMigration;$migration($db);$migration($db);
    $legacy=$db->query('SELECT company_id,seller_location_id,notes,status FROM direct_stock_requests WHERE id='.$legacyRequest)->fetch();
    dsExpect((int)$legacy['company_id']===$legacyCompany&&(int)$legacy['seller_location_id']===$legacyLocation&&$legacy['notes']==='Solicitud previa multiempresa'&&$legacy['status']==='pending','La migración no conserva la solicitud previa de otra empresa.');
    dsExpect((int)$db->query('SELECT @nexo_company_id')->fetchColumn()===1,'La migración cambia el contexto de empresa del llamador.');
    require_once dirname(__DIR__).'/php/bcv-sync.php';bcvApplyPublication($db,['rate'=>'100.00','effective_date'=>commerceToday(),'source'=>'bcv']);$db->exec('SET @nexo_company_id=1, @nexo_actor_id='.$admin);
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix]);$warehouse=(int)$db->lastInsertId();
    $locations=[];foreach(['central','seller'] as $key){$db->prepare('INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,\'internal\')')->execute([$prefix.$key,$warehouse]);$locations[$key]=(int)$db->lastInsertId();}
    $db->prepare('INSERT INTO contacts(name,type_id,warehouse_id,location_id) VALUES(?,?,?,?)')->execute([$prefix.' seller',$types['salesperson'],$warehouse,$locations['seller']]);$seller=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,salesperson_id,address) VALUES(?,?,?,?)')->execute([$prefix.' customer',$types['client'],$seller,'Dirección de prueba']);$customer=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,address) VALUES(?,?,?)')->execute([$prefix.' other customer',$types['client'],'Otro']);$otherCustomer=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO business_taxes(name,module_key,rate) VALUES(?,'ventas',10)")->execute([$prefix]);$tax=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,product_type,sale_price,cost_price,invoice_policy,commission_type,commission_value,sale_tax_id,currency_code) VALUES(?,1,'goods',5,3,'delivered','fixed',2,?,'USD')")->execute([$prefix.' product',$tax]);$product=(int)$db->lastInsertId();
    foreach([['central','20.00'],['seller','5.00']] as [$where,$qty])$db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,?)')->execute([$product,$locations[$where],$qty]);
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,product_type,sale_price,cost_price,invoice_policy,tracking_mode,currency_code) VALUES(?,1,'goods',5,3,'delivered','serial','USD')")->execute([$prefix.' serial']);$serialProduct=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO accounting_payment_terms(name,days) VALUES(?,15)')->execute([$prefix.' 15 días']);$term=(int)$db->lastInsertId();
    $groups=$db->query('SELECT system_key,id FROM access_groups WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $actors=[];$logins=['admin'=>strtolower($prefix)];
    foreach(['seller'=>'direct_sales','manager'=>'direct_sales_manager'] as $role=>$group){
        $logins[$role]=strtolower($prefix).'.'.$role;
        $actors[$role]=saveAuthUser($db,['name'=>$prefix.' '.$role,'login'=>$logins[$role],'active'=>'1','groups'=>[$groups[$group]],'company_ids'=>[1],'contact_id'=>$role==='seller'?$seller:'','password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
        $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$actors[$role]]);
    }
    foreach($logins as $role=>$login){$cookie=tempnam(sys_get_temp_dir(),'direct-test-');$cookies[$role]=$cookie;[, $html]=authTestRequest($server,'/login.php',$cookie);dsExpect(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'Falló login.');authTestRequest($server,'/index.php',$cookie);}
    $post=static function(string $role,string $path,array $input)use($server,$cookies):array{[, $html]=authTestRequest($server,$path,$cookies[$role]);if(!str_contains($html,'name="csrf"'))[, $html]=authTestRequest($server,'/index.php',$cookies[$role]);return authTestRequest($server,$path,$cookies[$role],['csrf'=>authTestToken($html)]+$input);};
    $path='/modules/venta_directa/index.php';$requestPath='/modules/venta_directa/solicitudes.php';
    [$code,$contactForm]=authTestRequest($server,'/modules/contactos/index.php?id='.$seller,$cookies['admin']);
    dsExpect($code===200&&str_contains($contactForm,'data-related-location')&&str_contains($contactForm,'name="location_id"'),'La ficha del vendedor no permite configurar su ubicación interna.');
    [$code,$html]=authTestRequest($server,$path,$cookies['seller']);dsExpect($code===200&&str_contains($html,'data-direct-form')&&str_contains($html,$prefix.' product'),'No abre el catálogo del vendedor.');
    // The contact form historically saved only a warehouse. Never choose an arbitrary location.
    $db->prepare('UPDATE contacts SET location_id=NULL WHERE id=?')->execute([$seller]);
    [$code,$html]=authTestRequest($server,$path,$cookies['seller']);
    dsExpect(!str_contains($html,'data-direct-form')&&str_contains($html,'Selecciona una ubicación interna'),'Se elige una ubicación ambigua sin autorización.');
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix.' vendedor móvil']);$sellerWarehouse=(int)$db->lastInsertId();
    $db->prepare('UPDATE contacts SET warehouse_id=? WHERE id=?')->execute([$sellerWarehouse,$seller]);
    $db->prepare('UPDATE inventory_locations SET warehouse_id=? WHERE id=?')->execute([$sellerWarehouse,$locations['seller']]);
    [$code,$html]=authTestRequest($server,$path,$cookies['seller']);
    dsExpect($code===200&&str_contains($html,'data-direct-form')&&str_contains($html,$prefix.' product'),'Un almacén con una sola ubicación no resuelve el vendedor sin ubicación explícita.');
    [$code,$html]=authTestRequest($server,'/modules/venta_directa/inventario.php',$cookies['admin']);
    dsExpect($code===200&&str_contains($html,'Inventario para abastecimiento')&&str_contains($html,$prefix.'central')&&!str_contains($html,$prefix.'seller')&&!str_contains($html,'data-direct-form'),'El administrador no consulta inventario central separado del vendedor.');
    dsExpect(!str_contains($html,'>Vender</a>')&&!str_contains($html,'name="seller_id"'),'El administrador recibe la interfaz de vendedor.');
    dsExpect(authTestRequest($server,'/modules/venta_directa/inventario.php?source_location_id='.$locations['seller'],$cookies['admin'])[0]===403,'Se permite consultar un almacén de vendedor como origen central.');
    dsExpect(authTestRequest($server,'/modules/venta_directa/inventario.php',$cookies['seller'])[0]===403,'El vendedor puede consultar inventario central.');
    [$code,,$headers]=authTestRequest($server,$path,$cookies['admin']);dsExpect($code===303&&str_contains($headers,'inventario.php'),'El administrador no entra al inventario de abastecimiento.');
    dsExpect($post('admin',$path,['action'=>'direct_checkout'])[0]===403,'Administrador puede crear ventas directas sin rol vendedor.');
    dsExpect($post('admin',$requestPath,['action'=>'direct_request'])[0]===403,'Administrador puede crear solicitudes como vendedor.');
    $salePost=['action'=>'direct_checkout','request_key'=>bin2hex(random_bytes(32)),'seller_id'=>(string)$seller,'customer_id'=>(string)$customer,'payment_mode'=>'cash','lines_json'=>json_encode([['product_id'=>$product,'quantity'=>'2.00']])];
    $cash=dsId($post('seller',$path,$salePost));
    $q=$db->prepare('SELECT d.*,i.status,i.total_amount,i.due_date,i.invoice_date,i.payment_state FROM direct_sale_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.id=?');$q->execute([$cash]);$cashRow=$q->fetch();
    dsExpect($cashRow['status']==='posted'&&$cashRow['total_amount']==='15.40'&&$cashRow['payment_state']==='not_paid'&&$cashRow['due_date']===$cashRow['invoice_date'],'Factura de contado, comisión, impuesto o vencimiento incorrectos.');
    $q=$db->prepare('SELECT status FROM sales_quotations WHERE id=?');$q->execute([$cashRow['sale_id']]);dsExpect($q->fetchColumn()==='sale','La orden no queda confirmada.');
    $q=$db->prepare('SELECT status,source_location_id FROM inventory_operations WHERE id=?');$q->execute([$cashRow['operation_id']]);$movement=$q->fetch();dsExpect($movement['status']==='done'&&(int)$movement['source_location_id']===$locations['seller'],'La entrega no está realizada desde el vendedor.');
    $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$locations['seller']]);dsExpect($q->fetchColumn()==='3.00','La venta no descuenta existencias.');
    dsExpect(dsId($post('seller',$path,$salePost))===$cash,'Un reintento duplica la venta.');
    $bad=$salePost;$bad['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'1.00']]);dsExpect($post('seller',$path,$bad)[0]===409,'Se reutiliza la misma clave con otro carrito.');
    $bad=$salePost;$bad['request_key']=bin2hex(random_bytes(32));$bad['customer_id']=(string)$otherCustomer;dsExpect($post('seller',$path,$bad)[0]===403,'Se vende a un cliente ajeno.');
    $bad=$salePost;$bad['request_key']=bin2hex(random_bytes(32));$bad['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'1','unit_price'=>'0.01']]);dsExpect($post('seller',$path,$bad)[0]===422,'El cliente puede modificar precios.');
    $before=(int)$db->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn();$bad=$salePost;$bad['request_key']=bin2hex(random_bytes(32));$bad['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'99']]);dsExpect($post('seller',$path,$bad)[0]===422,'Se permite sobreventa.');dsExpect((int)$db->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn()===$before,'Un fallo deja documentos parciales.');
    $credit=$salePost;$credit['request_key']=bin2hex(random_bytes(32));$credit['payment_mode']='credit';$credit['payment_term_id']=(string)$term;$credit['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'1.00']]);$creditId=dsId($post('seller',$path,$credit));
    $q->execute([$product,$locations['seller']]);dsExpect($q->fetchColumn()==='2.00','La segunda venta no descuenta stock.');
    $query=$db->prepare('SELECT i.* FROM accounting_invoices i JOIN direct_sale_checkouts d ON d.invoice_id=i.id WHERE d.id=?');$query->execute([$creditId]);$creditInvoice=$query->fetch();
    dsExpect($creditInvoice['due_date']===(new DateTimeImmutable(commerceToday()))->modify('+15 days')->format('Y-m-d')&&$creditInvoice['payment_state']==='not_paid','El crédito no conserva el plazo y saldo.');
    dsExpect((int)$db->query('SELECT COUNT(*) FROM accounting_invoice_payments WHERE invoice_id IN ('.(int)$cashRow['invoice_id'].','.(int)$creditInvoice['id'].')')->fetchColumn()===0,'La venta confirmó o registró pagos automáticamente.');
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES(?,'cash','USD')")->execute([$prefix.' caja']);$account=(int)$db->lastInsertId();
    $method=$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual' LIMIT 1")->fetchColumn();
    $invoicePath='/modules/contabilidad/index.php?type=customer&id='.$cashRow['invoice_id'];
    dsId($post('seller',$invoicePath,['action'=>'register_payment','version'=>'2','amount'=>'15.40','currency_code'=>'USD','payment_date'=>commerceToday(),'account_id'=>(string)$account,'method_id'=>(string)$method]));
    $qState=$db->prepare('SELECT payment_state FROM accounting_invoices WHERE id=?');$qState->execute([$cashRow['invoice_id']]);dsExpect($qState->fetchColumn()==='in_payment','El vendedor no puede registrar el cobro pendiente.');
    dsExpect($post('seller',$invoicePath,['action'=>'settle_payment','version'=>'3','payment_id'=>'1'])[0]===403,'El vendedor puede confirmar su propio pago.');
    $db->exec('SET @nexo_company_id=1');
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,product_type,sale_price,currency_code,tracking_mode,expiration_enabled) VALUES(?,1,'goods',3,'USD','lot',1)")->execute([$prefix.' lote']);$lotProduct=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,2)')->execute([$lotProduct,$locations['seller']]);
    trackingInitialize($db,$lotProduct,$locations['seller'],[['name'=>$prefix.' lote A','quantity'=>'2.00','expiration_date'=>(new DateTimeImmutable(commerceToday()))->modify('+3 days')->format('Y-m-d')]]);
    $lotPost=$salePost;$lotPost['request_key']=bin2hex(random_bytes(32));$lotPost['lines_json']=json_encode([['product_id'=>$lotProduct,'quantity'=>'1.00']]);$lotSale=dsId($post('seller',$path,$lotPost));
    $lotOperation=$db->query('SELECT operation_id FROM direct_sale_checkouts WHERE id='.$lotSale)->fetchColumn();
    dsExpect((int)$db->query('SELECT COUNT(*) FROM inventory_lot_lines a JOIN inventory_operation_lines l ON l.id=a.line_id WHERE l.operation_id='.(int)$lotOperation)->fetchColumn()===1,'La venta directa pierde el seguimiento por lotes.');
    $db->prepare('UPDATE inventory_lots SET expiration_date=? WHERE product_id=?')->execute([(new DateTimeImmutable(commerceToday()))->modify('-1 day')->format('Y-m-d'),$lotProduct]);
    dsExpect(!in_array($lotProduct,array_column(directProducts($db,$locations['seller']),'id'),true),'El catálogo ofrece existencias vencidas.');
    $lotPost['request_key']=bin2hex(random_bytes(32));dsExpect($post('seller',$path,$lotPost)[0]===422,'Se puede vender un lote vencido.');
    $request=['action'=>'direct_request','request_key'=>bin2hex(random_bytes(32)),'seller_id'=>(string)$seller,'lines_json'=>json_encode([['product_id'=>$product,'quantity'=>'4.00']]),'notes'=>'Reponer ruta'];
    $requestId=dsId($post('seller',$requestPath,$request));dsExpect(dsId($post('seller',$requestPath,$request))===$requestId,'Se duplica la solicitud.');
    $line=$db->query('SELECT id FROM direct_stock_request_lines WHERE request_id='.$requestId)->fetchColumn();
    $approve=['action'=>'direct_approve','version'=>'1','source_location_id'=>(string)$locations['central'],'approved'=>[$line=>'2.00'],'decision_note'=>'Entrega parcial'];
    [$code,$html]=authTestRequest($server,$requestPath.'?id='.$requestId.'&source_location_id='.$locations['central'],$cookies['admin']);
    dsExpect($code===200&&str_contains($html,'Disponible en origen:')&&str_contains($html,'20.00')&&!str_contains($html,'name="seller_id"'),'El administrador no ve existencias al aprobar sin ser vendedor.');
    $badOrigin=$approve;$badOrigin['source_location_id']=(string)$locations['seller'];dsExpect(in_array($post('manager',$requestPath.'?id='.$requestId,$badOrigin)[0],[403,422],true),'Se permite abastecer desde un almacén de vendedor.');
    dsExpect($post('seller',$requestPath.'?id='.$requestId,$approve)[0]===403,'El vendedor aprueba su mercancía.');
    $badApproval=$approve;$badApproval['approved']=[$line=>'30.00'];dsExpect($post('manager',$requestPath.'?id='.$requestId,$badApproval)[0]===422,'Se aprueba más de lo solicitado.');
    dsId($post('manager',$requestPath.'?id='.$requestId,$approve));
    $q->execute([$product,$locations['seller']]);dsExpect($q->fetchColumn()==='2.00','Aprobar mueve inventario antes de la entrega física.');
    dsExpect($db->query('SELECT status FROM direct_stock_requests WHERE id='.$requestId)->fetchColumn()==='approved','La solicitud no queda aprobada pendiente de entrega.');
    $dispatch=['action'=>'direct_dispatch','version'=>'2'];dsExpect($post('seller',$requestPath.'?id='.$requestId,$dispatch)[0]===403,'El vendedor puede confirmar la entrega central.');
    dsId($post('manager',$requestPath.'?id='.$requestId,$dispatch));$transfer=$db->query('SELECT operation_id FROM direct_stock_requests WHERE id='.$requestId)->fetchColumn();
    dsExpect($transfer!==false&&$transfer!==null,'No se generó transferencia.');
    $q->execute([$product,$locations['seller']]);dsExpect($q->fetchColumn()==='4.00','La mercancía aprobada no entra al vendedor.');
    $q->execute([$product,$locations['central']]);dsExpect($q->fetchColumn()==='18.00','La transferencia no descuenta el origen.');
    dsId($post('manager',$requestPath.'?id='.$requestId,$approve));$q->execute([$product,$locations['seller']]);dsExpect($q->fetchColumn()==='4.00','Repetir la aprobación duplica existencias.');
    $large=$request;$large['request_key']=bin2hex(random_bytes(32));$large['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'99.00']]);$largeId=dsId($post('seller',$requestPath,$large));
    $largeLine=$db->query('SELECT id FROM direct_stock_request_lines WHERE request_id='.$largeId)->fetchColumn();
    $operationCount=(int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn();
    dsId($post('manager',$requestPath.'?id='.$largeId,['action'=>'direct_approve','version'=>'1','source_location_id'=>(string)$locations['central'],'approved'=>[$largeLine=>'99.00']]));
    dsExpect($post('manager',$requestPath.'?id='.$largeId,$dispatch)[0]===422,'Se entrega mercancía sin stock.');
    dsExpect((int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn()===$operationCount&&$db->query('SELECT status FROM direct_stock_requests WHERE id='.$largeId)->fetchColumn()==='approved','Una entrega fallida deja una transferencia o resuelve la solicitud.');
    dsId($post('manager',$requestPath.'?id='.$largeId,['action'=>'direct_cancel','version'=>'2','decision_note'=>'Sin existencias para esta cantidad']));
    dsExpect($db->query('SELECT status FROM direct_stock_requests WHERE id='.$largeId)->fetchColumn()==='rejected','El supervisor no puede rechazar con motivo.');
    require __DIR__.'/direct-sales-operations-cases.php';
    $otherCompany=createBusinessCompany($db,['name'=>$prefix.' otra empresa'],$admin);$db->exec('SET @nexo_company_id='.$otherCompany);
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute([$prefix.' otro']);$otherWarehouse=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations(name,warehouse_id) VALUES(?,?)')->execute([$prefix.' otra ubicación',$otherWarehouse]);$otherLocation=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,warehouse_id,location_id) VALUES(?,?,?,?)')->execute([$prefix.' otro vendedor',$types['salesperson'],$otherWarehouse,$otherLocation]);$otherSeller=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO direct_stock_requests(seller_id,destination_location_id,seller_location_id,request_key,payload_hash) VALUES(?,?,?,?,?)')->execute([$otherSeller,$otherLocation,$otherLocation,bin2hex(random_bytes(32)),str_repeat('a',64)]);$otherRequest=(int)$db->lastInsertId();
    dsExpect(authTestRequest($server,$requestPath.'?id='.$otherRequest,$cookies['seller'])[0]===403,'El vendedor ve solicitudes de otra empresa.');
    $db->prepare("INSERT INTO direct_sale_checkouts(seller_id,request_key,payload_hash,payment_mode) VALUES(?,?,?,'cash')")->execute([$otherSeller,bin2hex(random_bytes(32)),str_repeat('b',64)]);$foreignSale=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO direct_sale_actions(sale_id,seller_id,action_kind,request_key,payload_hash,payload_json) VALUES(?,?,'return',?,?,'{}')")->execute([$foreignSale,$otherSeller,bin2hex(random_bytes(32)),str_repeat('c',64)]);$foreignReturn=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO direct_day_closings(seller_id,closing_date,snapshot_json,declared_json) VALUES(?,?,'{}','{}')")->execute([$otherSeller,commerceToday()]);$foreignClosing=(int)$db->lastInsertId();
    dsExpect(authTestRequest($server,'/modules/venta_directa/devoluciones.php?id='.$foreignReturn,$cookies['seller'])[0]===403,'El vendedor consulta una devolución de otra empresa.');
    dsExpect(authTestRequest($server,'/modules/venta_directa/cierre.php?id='.$foreignClosing,$cookies['seller'])[0]===403,'El vendedor consulta un cierre de otra empresa.');
    $bad=$salePost;$bad['request_key']=bin2hex(random_bytes(32));$bad['seller_id']=(string)$otherSeller;dsExpect($post('seller',$path,$bad)[0]===403,'Se puede vender desde el almacén de otro vendedor o empresa.');
    dsExpect(authTestRequest($server,$path,$cookies['seller'],['action'=>'direct_checkout','csrf'=>'invalid'])[0]===403,'El módulo omite la protección CSRF.');
    $db->exec('SET @nexo_company_id=1');$db->exec('DELETE FROM accounting_currency_rates WHERE company_id=1');
    $noRate=$salePost;$noRate['request_key']=bin2hex(random_bytes(32));$noRate['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'1']]);
    dsExpect($post('seller',$path,$noRate)[0]===422,'Se factura sin una tasa vigente.');bcvApplyPublication($db,['rate'=>'100.00','effective_date'=>commerceToday(),'source'=>'bcv']);
    $rowsBefore=$db->query('SELECT * FROM direct_stock_requests ORDER BY id')->fetchAll();$migration($db);dsExpect($rowsBefore===$db->query('SELECT * FROM direct_stock_requests ORDER BY id')->fetchAll(),'La migración repetida cambia solicitudes existentes.');
    if(in_array('--browser',$argv,true)){
        // Real stock fixtures exercise a large catalog without changing the checkout amounts.
        $db->exec('SET @nexo_company_id=1, @nexo_actor_id='.$admin);
        for($n=1;$n<=100;$n++){
            $db->prepare("INSERT INTO inventory_products(name,sale_enabled,product_type,sale_price,currency_code) VALUES(?,1,'goods',1,'USD')")->execute(['ZZ '.$prefix.' catalog '.str_pad((string)$n,3,'0',STR_PAD_LEFT)]);$catalogProduct=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,2)')->execute([$catalogProduct,$locations['seller']]);
        }
        $env=getenv();$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$logins['seller'];$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_DIRECT_CUSTOMER']=(string)$customer;$env['NEXO_DIRECT_PRODUCT']=(string)$product;$env['NEXO_DIRECT_SERIAL_PRODUCT']=(string)$serialProduct;
        $process=proc_open(['node',__DIR__.'/direct-sales-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Falló la revisión del navegador.');
    }
    echo "PASS: venta directa de contado/crédito, documentos nativos, impuestos/comisiones, stock, pagos separados, reintentos, permisos, solicitud y transferencia parcial idempotente; migración conserva datos.\n";
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($server);}

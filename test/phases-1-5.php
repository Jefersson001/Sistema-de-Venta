<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/payments-repository.php';
require_once dirname(__DIR__).'/php/transfer-models.php';
function phaseExpect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function phaseCreated(array $response):int{
    phaseExpect($response[0]===303&&preg_match('/[?&]id=(\d+)/',$response[2],$m)===1,'Failed save: '.mb_substr(strip_tags($response[1]),0,1200));return(int)$m[1];
}
$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'phase15-');
try{
    $db=$s['db'];$login='phases.admin.'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(14));$token=prepareFirstAdministrator($db);
    $actor=createFirstAdministrator($db,['name'=>'Validación fases','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    [, $body]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($body),'login'=>$login,'password'=>$password]);
    [, $body]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie);$csrf=authTestToken($body);
    $db->exec("INSERT INTO contact_countries(name) VALUES('País QA fases')");$country=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO contact_states(name,country_id) VALUES('Estado QA fases',?)")->execute([$country]);$state=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO contact_states(name,country_id) VALUES('Otro estado QA',?)")->execute([$country]);$otherState=(int)$db->lastInsertId();
    [, $body]=authTestRequest($s,'/modules/contactos/configuracion.php?section=municipalities&new=1',$cookie);
    phaseExpect(str_contains($body,'Municipio')||str_contains($body,'municipio'),'Municipality form missing.');
    [$status]=authTestRequest($s,'/modules/contactos/configuracion.php?section=municipalities&new=1',$cookie,['csrf'=>$csrf,'action'=>'save_catalog','name'=>'Municipio QA','state_id'=>$state]);phaseExpect($status===303,'Municipality save failed.');
    $municipality=(int)$db->query("SELECT id FROM contact_municipalities WHERE name='Municipio QA'")->fetchColumn();
    [$status]=authTestRequest($s,'/modules/contactos/configuracion.php?section=cities&new=1',$cookie,['csrf'=>$csrf,'action'=>'save_catalog','name'=>'Ciudad incorrecta','state_id'=>$otherState,'municipality_id'=>$municipality]);phaseExpect($status===200&&!$db->query("SELECT id FROM contact_cities WHERE name='Ciudad incorrecta'")->fetchColumn(),'Invalid municipality/state accepted.');
    [$status]=authTestRequest($s,'/modules/contactos/configuracion.php?section=cities&new=1',$cookie,['csrf'=>$csrf,'action'=>'save_catalog','name'=>'Ciudad QA','state_id'=>$state,'municipality_id'=>$municipality]);phaseExpect($status===303,'City/municipality save failed.');$city=(int)$db->query("SELECT id FROM contact_cities WHERE name='Ciudad QA'")->fetchColumn();
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $geo=['country_id'=>$country,'state_id'=>$state,'municipality_id'=>$municipality,'city_id'=>$city];
    $line=['label'=>'Sucursal QA','address'=>'Calle sucursal original','usage_type'=>'both','active'=>'1']+$geo;
    $input=['csrf'=>$csrf,'action'=>'save_contact','name'=>'Cliente fases QA','entity_kind'=>'person','type_id'=>$type,'address'=>'Calle principal original','addresses_present'=>'1','addresses_revision'=>contactAddressesRevision([]),'addresses'=>[$line],'default_invoice_address'=>'primary','default_delivery_address'=>'row_0']+$geo;
    $client=phaseCreated(authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,$input));
    $rows=loadContactAddresses($db,$client);phaseExpect(count($rows)===2,'Two addresses not saved.');$primary=(int)$rows[0]['id'];$delivery=(int)$rows[1]['id'];
    [, $contactForm]=authTestRequest($s,'/modules/contactos/index.php?id='.$client,$cookie);$scope=authTestToken($contactForm,'company_scope');
    [$status,$fresh]=authTestRequest($s,'/modules/contactos/direcciones.php?id='.$client.'&scope='.$scope,$cookie);phaseExpect($status===200&&count(json_decode($fresh,true)['addresses'])===2,'Fresh address endpoint failed.');
    [$status]=authTestRequest($s,'/modules/contactos/direcciones.php?id='.$client.'&scope=wrong',$cookie);phaseExpect($status===409,'Stale scope accepted on fresh address endpoint.');
    phaseExpect($rows[0]['default_invoice']&&$rows[1]['default_delivery']&&str_contains($rows[1]['formatted'],'Municipio QA'),'Defaults or formatted geography incorrect.');
    installPhase15Schema($db);phaseExpect(count(loadContactAddresses($db,$client))===2,'Repeated migration duplicated addresses.');$db->exec('SET @nexo_company_id=1');
    $models=transferModels();phaseExpect(isset($models['municipalities'])&&in_array('municipality_id',$models['contacts']['write'],true),'Municipalities missing in transfer catalog.');
    $db->exec("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,product_type,invoice_policy,sale_price) VALUES('Producto QA fases',1,1,'goods','ordered',10)");$product=(int)$db->lastInsertId();
    $currencies=$db->query('SELECT code,id FROM accounting_currencies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->prepare("INSERT INTO accounting_currency_rates(currency_id,base_currency_id,rate_date,rate) VALUES(?,?,'2020-01-01',100)")->execute([$currencies['VES'],$currencies['USD']]);
    $orderInput=['csrf'=>$csrf,'action'=>'save_quotation','customer_id'=>$client,'order_type'=>'invoice','order_date'=>commerceToday(),'currency_code'=>'USD','invoice_address_id'=>$primary,'delivery_address_id'=>$delivery,'lines'=>[['product_id'=>$product,'quantity'=>'1','unit_price'=>'10']]];
    $sale=phaseCreated(authTestRequest($s,'/modules/ventas/index.php?new=1',$cookie,$orderInput));
    $draft=phaseCreated(authTestRequest($s,'/modules/ventas/index.php?new=1',$cookie,$orderInput));
    $saved=loadSalesQuotation($db,$sale);phaseExpect(str_contains($saved['delivery_address'],'Calle sucursal original')&&(int)$saved['delivery_address_id']===$delivery,'Order did not use chosen address.');
    phaseCreated(authTestRequest($s,'/modules/ventas/index.php?id='.$sale,$cookie,['csrf'=>$csrf,'action'=>'confirm_sale','version'=>'1']));
    $operation=createSalesDelivery($db,$sale);$q=$db->prepare('SELECT partner_address FROM inventory_operations WHERE id=?');$q->execute([$operation]);phaseExpect($q->fetchColumn()===$saved['delivery_address'],'Delivery snapshot differs from order.');
    // Address edits/archival must never rewrite existing document snapshots.
    $oldRevision=contactAddressesRevision(loadContactAddresses($db,$client));$edit=$input;$edit['addresses_revision']=$oldRevision;$edit['address']='Calle principal nueva';$edit['addresses'][0]['id']=$delivery;$edit['addresses'][0]['address']='Sucursal cambiada';$edit['addresses'][0]['active']='0';$edit['default_delivery_address']='primary';
    phaseCreated(authTestRequest($s,'/modules/contactos/index.php?id='.$client,$cookie,$edit));
    phaseExpect(loadSalesQuotation($db,$sale)['delivery_address']===$saved['delivery_address'],'Contact edit changed confirmed sale.');
    $invoice=createCommerceInvoice($db,'sale',$sale);$q=$db->prepare('SELECT delivery_address,invoice_address FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);$i=$q->fetch();phaseExpect($i['delivery_address']===$saved['delivery_address']&&$i['invoice_address']===$saved['invoice_address'],'Invoice did not copy historical addresses.');
    phaseCreated(authTestRequest($s,'/modules/contabilidad/index.php?type=customer&id='.$invoice,$cookie,['csrf'=>$csrf,'action'=>'confirm_invoice','version'=>'1','invoice_date'=>commerceToday(),'due_date'=>commerceToday()]));
    $q=$db->prepare('SELECT version FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);$credit=createCreditNote($db,$invoice,(int)$q->fetchColumn(),'Validación de direcciones');$q=$db->prepare('SELECT delivery_address FROM accounting_invoices WHERE id=?');$q->execute([$credit]);phaseExpect($q->fetchColumn()===$saved['delivery_address'],'Credit did not preserve address.');
    $editOrder=$orderInput;$editOrder['version']='1';phaseCreated(authTestRequest($s,'/modules/ventas/index.php?id='.$draft,$cookie,$editOrder));phaseExpect(loadSalesQuotation($db,$draft)['delivery_address']===$saved['delivery_address'],'Unchanged draft address lost snapshot.');
    $editOrder['version']='2';$editOrder['delivery_address_id']=$primary;$editOrder['refresh_invoice_address']='1';phaseCreated(authTestRequest($s,'/modules/ventas/index.php?id='.$draft,$cookie,$editOrder));phaseExpect(str_contains(loadSalesQuotation($db,$draft)['delivery_address'],'Calle principal nueva'),'Explicit address change did not refresh draft.');
    [$status,$body]=authTestRequest($s,'/modules/contactos/index.php?id='.$client,$cookie,array_replace($edit,['name'=>'Nombre que no debe guardarse','addresses_revision'=>$oldRevision]));phaseExpect($status===200&&str_contains($body,'otra ventana'),'Stale address revision accepted.');
    phaseExpect($db->query('SELECT name FROM contacts WHERE id='.$client)->fetchColumn()==='Cliente fases QA','Stale form overwrote contact.');
    $db->prepare("INSERT INTO contacts(name,type_id) VALUES('Otro cliente QA',?)")->execute([$type]);$other=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO contact_addresses(contact_id,label,address) VALUES(?,'Ajena','Dirección ajena')")->execute([$other]);$alien=(int)$db->lastInsertId();
    [$status,$body]=authTestRequest($s,'/modules/ventas/index.php?new=1',$cookie,array_replace($orderInput,['delivery_address_id'=>$alien]));phaseExpect($status===200&&str_contains($body,'no corresponde al contacto'),'Another contact address accepted.');
    // Supplier addresses flow to the bill and the physical receipt separately
    // from the internal warehouse receiving the goods.
    $supplierInput=$input;$supplierInput['name']='Proveedor fases QA';$supplierInput['type_id']=(int)$db->query("SELECT id FROM contact_types WHERE system_key='supplier'")->fetchColumn();$supplierInput['address']='Sede proveedor';$supplierInput['addresses'][0]['address']='Facturación proveedor';$supplierInput['default_invoice_address']='row_0';$supplierInput['default_delivery_address']='primary';
    $supplier=phaseCreated(authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,$supplierInput));$supplierAddresses=loadContactAddresses($db,$supplier);$supplierAddress=(int)$supplierAddresses[1]['id'];
    $db->exec("INSERT INTO inventory_warehouses(name) VALUES('Almacén QA direcciones')");$warehouse=(int)$db->lastInsertId();$db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES('Recepción QA',?,'internal')")->execute([$warehouse]);$location=(int)$db->lastInsertId();
    $purchase=phaseCreated(authTestRequest($s,'/modules/compras/index.php?new=1',$cookie,['csrf'=>$csrf,'action'=>'save_purchase','supplier_id'=>$supplier,'order_type'=>'invoice','order_date'=>commerceToday(),'currency_code'=>'USD','invoice_address_id'=>$supplierAddress,'destination_location_id'=>$location,'lines'=>[['product_id'=>$product,'quantity'=>'1','unit_price'=>'4']]]));
    phaseCreated(authTestRequest($s,'/modules/compras/index.php?id='.$purchase,$cookie,['csrf'=>$csrf,'action'=>'confirm_purchase','version'=>'1']));
    $receipt=createLinkedStockOperation($db,'purchase',$purchase);$q=$db->prepare('SELECT partner_address FROM inventory_operations WHERE id=?');$q->execute([$receipt]);phaseExpect(str_contains((string)$q->fetchColumn(),'Facturación proveedor'),'Receipt lost supplier address.');
    $bill=createCommerceInvoice($db,'purchase',$purchase);$q=$db->prepare('SELECT invoice_address FROM accounting_invoices WHERE id=?');$q->execute([$bill]);phaseExpect(str_contains((string)$q->fetchColumn(),'Facturación proveedor'),'Supplier bill lost address.');
    $manual=phaseCreated(authTestRequest($s,'/modules/inventario/operaciones.php?type=delivery&new=1',$cookie,['csrf'=>$csrf,'action'=>'save_operation','contact_id'=>$client,'partner_address_id'=>$primary,'source_location_id'=>$location,'scheduled_date'=>commerceToday(),'lines'=>[['product_id'=>$product,'demand'=>'1','quantity'=>'0']]]));$q=$db->prepare('SELECT partner_address_id FROM inventory_operations WHERE id=?');$q->execute([$manual]);phaseExpect((int)$q->fetchColumn()===$primary,'Manual operation address was not saved.');
    require_once dirname(__DIR__).'/php/company-repository.php';$second=createBusinessCompany($db,['name'=>'Otra empresa QA direcciones'],$actor);$db->exec('SET @nexo_company_id='.$second);$db->prepare("INSERT INTO contacts(name,type_id) VALUES('Contacto otra empresa QA',?)")->execute([$type]);$secondContact=(int)$db->lastInsertId();$db->prepare("INSERT INTO contact_addresses(contact_id,label,address) VALUES(?,'Otra empresa','Dirección de otra empresa')")->execute([$secondContact]);$secondAddress=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id=1');
    [$status,$body]=authTestRequest($s,'/modules/ventas/index.php?new=1',$cookie,array_replace($orderInput,['delivery_address_id'=>$secondAddress]));phaseExpect($status===200&&str_contains($body,'no corresponde al contacto'),'Cross-company address accepted.');
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_PHASE_CONTACT']=(string)$client;
    $p=proc_open(['node',__DIR__.'/phases-1-5-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);phaseExpect(proc_close($p)===0,'Browser phase test failed.');
    echo "PASS: municipalities/cities, migration repeatability, primary and alternate addresses, defaults, archival, revisions, partner ownership, delivery/invoice/credit snapshots and explicit refresh.\n";
}finally{if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

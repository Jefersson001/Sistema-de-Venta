<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/navigation-search.php';
require_once dirname(__DIR__).'/php/company-repository.php';
$server=startAuthTestServer();$cookies=[];
$modules=in_array('--modules',$argv,true);
$contactsPurchases=in_array('--contacts-purchases',$argv,true);
$remaining=in_array('--remaining',$argv,true);
$visual=$remaining||$modules||$contactsPurchases||in_array('--visual',$argv,true);
$output=dirname(__DIR__).($remaining?'/work/final-design-review':($contactsPurchases?'/work/contacts-purchases-review':($modules?'/work/module-design-review':($visual?'/work/visual-review':'/work/mobile-audit'))));
if(!is_dir($output))mkdir($output,0777,true);
try{
    $db=$server['db'];$password=bin2hex(random_bytes(16));$login='mobile.qa.'.bin2hex(random_bytes(5));
    $token=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>'Administrador QA móvil','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    foreach(['20261113131544_ventas_directas.php','20261113161546_separar_supervision_ventas_directas.php','20261113171011_completar_operacion_venta_directa.php'] as $file){$migration=require dirname(__DIR__).'/php/migrations/versioned/'.$file;$migration($db);}
    $db->exec('SET @nexo_company_id=1, @nexo_actor_id='.$admin);
    // Visual fixtures must not depend on the business company's current setup.
    if(!$db->query('SELECT id FROM accounting_currency_settings WHERE company_id=1 AND id=1')->fetchColumn())$db->exec("INSERT INTO accounting_currency_settings(id,primary_code,alternate_code) VALUES(1,'USD','VES')");
    foreach(['USD'=>['Dólar','$'],'VES'=>['Bolívar','Bs.']] as $code=>$labels){$q=$db->prepare('SELECT id FROM accounting_currencies WHERE company_id=1 AND code=?');$q->execute([$code]);if(!$q->fetchColumn())$db->prepare('INSERT INTO accounting_currencies(code,name,symbol) VALUES(?,?,?)')->execute([$code,...$labels]);}
    foreach(['customer'=>'Clientes','supplier'=>'Proveedores'] as $kind=>$label){$q=$db->prepare('SELECT id FROM inventory_locations WHERE company_id=1 AND system_key=?');$q->execute(['default_'.$kind]);if(!$q->fetchColumn())$db->prepare('INSERT INTO inventory_locations(name,location_type,system_key) VALUES(?,?,?)')->execute([$label,$kind,'default_'.$kind]);}
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->exec("INSERT INTO inventory_warehouses(name) VALUES('Almacén QA móvil')");$warehouse=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,'internal')")->execute(['Ubicación QA móvil',$warehouse]);$location=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,warehouse_id,location_id) VALUES(?,?,?,?)')->execute(['Vendedor QA con nombre de prueba largo',$types['salesperson'],$warehouse,$location]);$seller=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO contacts(name,type_id,salesperson_id,address,email,phone) VALUES(?,?,?,?,?,?)')->execute(['Cliente de prueba con nombre comercial largo para revisar teléfonos',$types['client'],$seller,'Dirección de prueba larga, edificio y referencias de entrega','cliente@example.com','04121234567']);$customer=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,product_type,sale_price,cost_price,invoice_policy,currency_code) VALUES(?,1,1,'goods',12,5,'delivered','USD')")->execute(['Producto de prueba con descripción larga y presentación familiar']);$product=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,50)')->execute([$product,$location]);
    $db->exec("INSERT INTO accounting_payment_terms(name,days) VALUES('QA 15 días',15)");$term=(int)$db->lastInsertId();
    $db->exec("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES('Caja QA móvil','cash','USD')");
    require_once dirname(__DIR__).'/php/bcv-sync.php';bcvApplyPublication($db,['rate'=>'100.00','effective_date'=>commerceToday(),'source'=>'bcv']);
    $group=(int)$db->query("SELECT id FROM access_groups WHERE system_key='direct_sales' LIMIT 1")->fetchColumn();
    $sellerLogin=$login.'.seller';$sellerUser=saveAuthUser($db,['name'=>'Vendedor QA móvil','login'=>$sellerLogin,'active'=>'1','groups'=>[$group],'company_ids'=>[1],'contact_id'=>$seller,'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$sellerUser]);
    foreach(['admin'=>$login,'seller'=>$sellerLogin] as $role=>$userLogin){
        $cookie=tempnam(sys_get_temp_dir(),'mobile-qa-');$cookies[$role]=$cookie;
        [, $html]=authTestRequest($server,'/login.php',$cookie);$r=authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$userLogin,'password'=>$password]);if($r[0]!==303)throw new RuntimeException('No inicia la sesión de prueba.');
        authTestRequest($server,'/index.php',$cookie);
    }
    $saleLines=[['product_id'=>$product,'quantity'=>'2']];
    if($visual){
        for($n=1;$n<=12;$n++){
            $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,product_type,sale_price,cost_price,invoice_policy,currency_code) VALUES(?,1,1,'goods',?,100,'delivered','USD')")->execute(['Presentación familiar de producto con nombre largo, sabor y empaque número '.$n,number_format(1234.56+$n,2,'.','')]);$extra=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,100)')->execute([$extra,$location]);
            $saleLines[]=['product_id'=>$extra,'quantity'=>'8'];
        }
    }
    $path='/modules/venta_directa/index.php';[, $html]=authTestRequest($server,$path,$cookies['seller']);
    $response=authTestRequest($server,$path,$cookies['seller'],['csrf'=>authTestToken($html),'action'=>'direct_checkout','request_key'=>bin2hex(random_bytes(32)),'seller_id'=>$seller,'customer_id'=>$customer,'payment_term_id'=>$term,'lines_json'=>json_encode($saleLines)]);
    if($response[0]!==303||!preg_match('/[?&]id=(\d+)/',$response[2],$match))throw new RuntimeException('No se creó la venta aislada: '.(preg_match_all('~<p[^>]*class="[^"]*(?:notice|error)[^"]*"[^>]*>(.*?)</p>~s',$response[1],$failures)?implode(' ',array_map('strip_tags',$failures[1])):mb_substr(strip_tags($response[1]),0,900)));
    $checkout=(int)$match[1];$row=$db->query('SELECT * FROM direct_sale_checkouts WHERE id='.$checkout)->fetch();
    if($contactsPurchases){
        $db->exec('SET @nexo_company_id=1, @nexo_actor_id='.$admin);
        if(!$db->query("SELECT id FROM contact_types WHERE name='Administrador'")->fetchColumn())$db->exec("INSERT INTO contact_types(name) VALUES('Administrador')");
        $db->prepare('INSERT INTO contacts(name,type_id,address,email,phone) VALUES(?,?,?,?,?)')->execute(['Proveedor de prueba con nombre largo para revisar órdenes de compra',$types['supplier'],'Avenida de prueba, local y referencias para recepción','proveedor@example.com','04121234568']);$supplier=(int)$db->lastInsertId();
        $newPurchase='/modules/compras/index.php?new=1';[, $purchaseForm]=authTestRequest($server,$newPurchase,$cookies['admin']);
        $purchaseLines=array_map(static fn(array $line):array=>['product_id'=>$line['product_id'],'quantity'=>'3','unit_price'=>'125.75','tax_id'=>''],$saleLines);
        $r=authTestRequest($server,$newPurchase,$cookies['admin'],['csrf'=>authTestToken($purchaseForm),'action'=>'save_purchase','supplier_id'=>$supplier,'order_type'=>'receipt','order_date'=>date('Y-m-d'),'payment_term_id'=>$term,'destination_location_id'=>$location,'currency_code'=>'USD','exchange_rate'=>'100','lines'=>$purchaseLines]);
        if($r[0]!==303||!preg_match('/[?&]id=(\d+)/',$r[2],$purchaseMatch))throw new RuntimeException('No se pudo preparar la compra de prueba: '.mb_substr(strip_tags($r[1]),-800));
        $purchase=(int)$purchaseMatch[1];$purchasePath='/modules/compras/index.php?id='.$purchase;[, $purchaseForm]=authTestRequest($server,$purchasePath,$cookies['admin']);
        $r=authTestRequest($server,$purchasePath,$cookies['admin'],['csrf'=>authTestToken($purchaseForm),'action'=>'confirm_purchase','version'=>1]);if($r[0]!==303)throw new RuntimeException('No se confirmó la compra de prueba.');
    }
    $routes=['/index.php','/perfil.php','/cambiar-contrasena.php'];
    foreach(navigationSearchCatalog() as $entry)$routes[]='/modules/'.$entry['module'].'/'.$entry['route'];
    foreach(['contactos/index.php','inventario/index.php','inventario/lotes.php','inventario/variantes.php','ventas/index.php','compras/index.php','contabilidad/pagos.php?type=customer','contabilidad/pagos.php?type=supplier','ajustes/usuarios.php','ajustes/grupos.php','restaurante/cuenta.php'] as $route)$routes[]='/modules/'.$route.(str_contains($route,'?')?'&':'?').'new=1';
    foreach(navigationSearchCatalog() as $entry)if(str_contains($entry['route'],'configuracion.php?section=')&&!str_contains($entry['route'],'currency_settings'))$routes[]='/modules/'.$entry['module'].'/'.$entry['route'].'&new=1';
    foreach(['delivery','receipt','internal'] as $type)$routes[]='/modules/inventario/operaciones.php?type='.$type.'&new=1';
    foreach(['income','expense','transfer','opening'] as $kind)$routes[]='/modules/contabilidad/tesoreria.php?kind='.$kind.'&new=1';
    $routes=array_merge($routes,[
        '/modules/contactos/index.php?id='.$customer,'/modules/contactos/index.php?id='.$seller,'/modules/inventario/index.php?id='.$product,
        '/modules/ventas/index.php?id='.$row['sale_id'],'/modules/inventario/operaciones.php?type=delivery&id='.$row['operation_id'],
        '/modules/contabilidad/index.php?type=customer&id='.$row['invoice_id'],'/modules/venta_directa/index.php?id='.$checkout,
        '/modules/contactos/index.php?q=qa_no_matching_record_97083',
    ]);
    if($visual)$routes=[
        '/index.php','/modules/dashboard/index.php','/modules/contactos/index.php',
        '/modules/contactos/index.php?id='.$customer,'/modules/inventario/index.php','/modules/inventario/index.php?id='.$product,
        '/modules/ventas/index.php','/modules/ventas/index.php?new=1','/modules/ventas/index.php?id='.$row['sale_id'],
        '/modules/compras/index.php?new=1','/modules/contabilidad/index.php?type=customer&id='.$row['invoice_id'],
        '/modules/contabilidad/pagos.php?type=customer&new=1','/modules/restaurante/index.php',
        '/modules/ajustes/grupos.php?new=1','/modules/ajustes/empresa.php',
    ];
    $sellerRoutes=$visual?['/modules/venta_directa/index.php','/modules/venta_directa/index.php?id='.$checkout]:['/modules/venta_directa/index.php','/modules/venta_directa/index.php?view=history','/modules/venta_directa/index.php?view=stock','/modules/venta_directa/solicitudes.php','/modules/venta_directa/devoluciones.php','/modules/venta_directa/cierre.php','/modules/venta_directa/index.php?id='.$checkout];
    if($modules){
        $routes=[
            '/modules/ventas/index.php','/modules/ventas/index.php?status=sale&q=Cliente','/modules/ventas/index.php?new=1','/modules/ventas/index.php?id='.$row['sale_id'],'/modules/ventas/comisiones.php','/modules/ventas/pagos-comisiones.php',
            '/modules/inventario/index.php','/modules/inventario/index.php?q=producto','/modules/inventario/index.php?id='.$product,'/modules/inventario/index.php?new=1','/modules/inventario/variantes.php','/modules/inventario/lotes.php','/modules/inventario/informes.php?view=stock','/modules/inventario/operaciones.php?type=delivery&id='.$row['operation_id'],
            '/modules/contabilidad/index.php?type=customer','/modules/contabilidad/index.php?type=customer&status=not_paid','/modules/contabilidad/index.php?type=customer&id='.$row['invoice_id'],'/modules/contabilidad/pagos.php?type=customer','/modules/contabilidad/pagos.php?type=customer&new=1','/modules/contabilidad/cuentas-pendientes.php','/modules/contabilidad/ingresos-egresos.php',
        ];$sellerRoutes=[];
    }
    if($contactsPurchases){
        $routes=['/modules/contactos/index.php','/modules/contactos/index.php?q=Cliente','/modules/contactos/index.php?new=1','/modules/contactos/index.php?id='.$customer,'/modules/contactos/index.php?id='.$supplier,'/modules/contactos/index.php?id='.$seller,'/modules/contactos/index.php?status=archived',
            '/modules/contactos/configuracion.php','/modules/contactos/configuracion.php?section=types','/modules/contactos/configuracion.php?section=document_types',
            '/modules/compras/index.php','/modules/compras/index.php?q=Proveedor&status=purchase','/modules/compras/index.php?new=1',$purchasePath,'/modules/compras/configuracion.php'];$sellerRoutes=[];
    }
    if($remaining){
        [, $setup]=authTestRequest($server,'/modules/restaurante/configuracion.php',$cookies['admin']);
        $setupResponse=authTestRequest($server,'/modules/restaurante/configuracion.php',$cookies['admin'],['csrf'=>authTestToken($setup),'action'=>'setup_tables','table_count'=>3,'request_key'=>bin2hex(random_bytes(32))]);
        if($setupResponse[0]!==303)throw new RuntimeException('No se prepararon las mesas aisladas.');
        $table=(int)$db->query('SELECT id FROM restaurant_tables WHERE company_id=1 ORDER BY id LIMIT 1')->fetchColumn();
        if(!$table)throw new RuntimeException('Falta mesa de prueba.');
        $routes=['/index.php','/modules/dashboard/index.php','/modules/restaurante/index.php','/modules/restaurante/cuenta.php?new=1','/modules/restaurante/configuracion.php','/modules/ajustes/empresa.php','/modules/ajustes/usuarios.php','/modules/ajustes/usuarios.php?new=1','/modules/ajustes/grupos.php?new=1','/modules/venta_directa/index.php','/modules/venta_directa/solicitudes.php'];
        $routes[3]='/modules/restaurante/cuenta.php?new=1&preview=1&table_id='.$table;
        $sellerRoutes=['/modules/venta_directa/index.php','/modules/venta_directa/index.php?view=history','/modules/venta_directa/index.php?view=stock','/modules/venta_directa/solicitudes.php','/modules/venta_directa/devoluciones.php','/modules/venta_directa/cierre.php','/modules/venta_directa/index.php?id='.$checkout];
    }
    file_put_contents($output.'/routes.json',json_encode(['admin'=>array_values(array_unique($routes)),'seller'=>$sellerRoutes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_SELLER']=$sellerLogin;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_MOBILE_OUTPUT']=$output;
    if($visual)$env['NEXO_VISUAL_REVIEW']='1';
    if($modules)$env['NEXO_MODULE_REVIEW']='1';
    if($contactsPurchases)$env['NEXO_CONTACTS_PURCHASES_REVIEW']='1';
    $p=proc_open(['node',__DIR__.'/mobile-audit-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('La auditoría detectó incidencias; consultar '.$output.'/report.json.');
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($server);}

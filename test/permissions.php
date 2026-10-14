<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/sales-repository.php';
function aclAssert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$server=startAuthTestServer();$db=$server['db'];$cookies=[];
try {
    $prefix='ACL-'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(14));
    $token=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>$prefix,'login'=>$prefix,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $sellers=[];$clients=[];$sales=[];$invoices=[];$operations=[];
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,sale_price,cost_price,product_type,commission_type,commission_value) VALUES(?,1,1,5,3,'service','fixed',2)")->execute([$prefix]);$product=(int)$db->lastInsertId();
    foreach([1,2] as $n) {
        $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.'-seller'.$n,$roles['salesperson']]);$sellers[$n]=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO contacts(name,type_id,salesperson_id) VALUES(?,?,?)')->execute([$prefix.'-client'.$n,$roles['client'],$sellers[$n]]);$clients[$n]=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO sales_quotations(name,customer_id,salesperson_id,invoice_address,delivery_address,order_date) VALUES(?,?,?,'','',?)")->execute([$prefix.'-sale'.$n,$clients[$n],$sellers[$n],commerceToday()]);$sales[$n]=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO sales_quotation_lines(quotation_id,position,product_id,product_name,quantity,unit_price,base_amount,total_amount,product_type,invoice_policy) VALUES(?,0,?,?,1,7,7,7,'service','ordered')")->execute([$sales[$n],$product,$prefix]);
        $db->prepare("INSERT INTO accounting_invoices(name,invoice_type,sale_id,contact_id,salesperson_id,invoice_address,invoice_date,due_date,base_amount,total_amount,status) VALUES(?,'customer',?,?,?,'',?,'2020-01-01',7,7,'posted')")->execute([$prefix.'-invoice'.$n,$sales[$n],$clients[$n],$sellers[$n],commerceToday()]);$invoices[$n]=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO accounting_invoice_lines(invoice_id,position,product_id,product_name,quantity,unit_price,base_amount,total_amount,commission_reference_price,commission_unit_amount) VALUES(?,0,?,?,1,7,7,7,5,2)')->execute([$invoices[$n],$product,$prefix]);
        $db->prepare("INSERT INTO inventory_operations(name,operation_type,scheduled_date,contact_id,quotation_id,notes) VALUES(?,'delivery',?,?,?,'')")->execute([$prefix.'-delivery'.$n,commerceToday(),$clients[$n],$sales[$n]]);$operations[$n]=(int)$db->lastInsertId();
    }
    $makeGroup=function(string $suffix,array $permissions,array $scopes=[])use($db,$prefix):int {
        return saveAccessGroup($db,['name'=>$prefix.$suffix,'active'=>'1','permissions'=>$permissions,'scopes'=>$scopes],null,null,bin2hex(random_bytes(32)));
    };
    $ownScopes=array_fill_keys(['contactos.contacts','ventas.orders','inventario.deliveries','contabilidad.customer_invoices','ventas.commissions','ventas.commission_payments','dashboard.business','dashboard.tasks','dashboard.commissions'],'own');
    $ownPermissions=['contactos.contacts.read','contactos.contacts.create','contactos.contacts.edit','ventas.orders.read','ventas.orders.create','ventas.orders.edit','ventas.orders.confirm','ventas.orders.create_delivery','ventas.orders.create_invoice','inventario.products.read','inventario.deliveries.read','contabilidad.customer_invoices.read','ventas.commissions.read','ventas.commission_payments.read','dashboard.business.read','dashboard.tasks.read','dashboard.commissions.read'];
    $ownGroup=$makeGroup('-own',$ownPermissions,$ownScopes);
    $readGroup=$makeGroup('-read',['contactos.contacts.read','ventas.orders.read','inventario.products.read'],['contactos.contacts'=>'all','ventas.orders'=>'all']);
    $configGroup=$makeGroup('-config',['inventario.configuration.read']);
    $financeGroup=$makeGroup('-finance-read',['contabilidad.customer_invoices.read','contabilidad.customer_payments.read','contabilidad.expense.read','ventas.commissions.read','ventas.commission_payments.read','inventario.deliveries.read','compras.orders.read'],['contabilidad.customer_invoices'=>'all','ventas.commissions'=>'all','ventas.commission_payments'=>'all','inventario.deliveries'=>'all']);
    $managerGroup=$makeGroup('-user-manager',['ajustes.users.read','ajustes.users.edit']);
    $productGroup=$makeGroup('-product-editor',['inventario.products.read','inventario.products.edit']);
    $dashboardGroup=$makeGroup('-dashboard-only',['dashboard.business.read','dashboard.tasks.read','dashboard.cash_flow.read','dashboard.commissions.read'],['dashboard.business'=>'all','dashboard.tasks'=>'all','dashboard.commissions'=>'all']);
    $makeUser=function(string $suffix,array $groups,?int $contact=null)use($db,$admin,$prefix,$password):array {
        $login=strtolower($prefix.$suffix);$id=saveAuthUser($db,['name'=>$prefix.$suffix,'login'=>$login,'active'=>'1','contact_id'=>$contact,'groups'=>$groups,'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));
        $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$id]);return [$id,$login];
    };
    [$own,$ownLogin]=$makeUser('-ownuser',[$ownGroup],$sellers[1]);[$reader,$readLogin]=$makeUser('-reader',[$readGroup]);[$config,$configLogin]=$makeUser('-configuser',[$configGroup]);
    [, $financeLogin]=$makeUser('-financeuser',[$financeGroup]);
    [, $managerLogin]=$makeUser('-manager',[$managerGroup]);[, $productLogin]=$makeUser('-products',[$productGroup]);
    [, $dashboardLogin]=$makeUser('-dashboardonly',[$dashboardGroup]);
    $login=function(string $login)use($server,$password,&$cookies):string {
        $cookie=tempnam(sys_get_temp_dir(),'acl-cookie-');$cookies[]=$cookie;[, $html]=authTestRequest($server,'/login.php',$cookie);
        [$code]=authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password]);aclAssert($code===303,'No se inició la sesión de prueba.');return $cookie;
    };
    $ownCookie=$login($ownLogin);$readCookie=$login($readLogin);$configCookie=$login($configLogin);
    $financeCookie=$login($financeLogin);
    $managerCookie=$login($managerLogin);$productCookie=$login($productLogin);
    $dashboardCookie=$login($dashboardLogin);
    [$code]=authTestRequest($server,'/modules/dashboard/ingresos-egresos.php',$dashboardCookie);aclAssert($code===403,'El permiso del Dashboard concede saldos bancarios sin consultar sus fuentes.');
    [$code,$onlySnapshot]=authTestRequest($server,'/modules/dashboard/resumen.php',$dashboardCookie);$onlyData=json_decode($onlySnapshot,true,512,JSON_THROW_ON_ERROR);
    aclAssert($code===200&&$onlyData['currencies']===[]&&$onlyData['commissions']===[]&&$onlyData['dashboard']['metrics']===[]&&$onlyData['dashboard']['tasks']===[],'Dashboard revela datos sin autorización en sus fuentes.');
    [, $managedForm]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$own,$managerCookie);$managedBefore=loadAuthUser($db,$own);
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$own,$managerCookie,['csrf'=>authTestToken($managedForm),'action'=>'save_user','version'=>$managedBefore['version'],'name'=>$prefix.'-managed','login'=>$ownLogin]);
    $managedAfter=loadAuthUser($db,$own);aclAssert($code===303&&$managedAfter['contact_id']===$managedBefore['contact_id']&&$managedAfter['active']===$managedBefore['active']&&$managedAfter['group_ids']===$managedBefore['group_ids'],'Delegar edición básica altera contacto, estado o grupos.');
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$own,$managerCookie,['csrf'=>authTestToken($managedForm),'action'=>'save_user','version'=>$managedAfter['version'],'name'=>$prefix,'login'=>$ownLogin,'groups'=>[$readGroup]]);aclAssert($code===403,'Editar usuarios permite cambiar sus grupos sin autorización.');
    [$code]=authTestRequest($server,'/modules/ajustes/usuarios.php?id='.$own,$managerCookie,['csrf'=>authTestToken($managedForm),'action'=>'reset_password','version'=>$managedAfter['version']]);aclAssert($code===403,'Editar usuarios permite restablecer contraseñas sin permiso.');
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.'-supplier',$roles['supplier']]);$supplier=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO product_supplier_prices(product_id,supplier_id,price) VALUES(?,?,9)')->execute([$product,$supplier]);
    [, $editProduct]=authTestRequest($server,'/modules/inventario/index.php?id='.$product,$productCookie);$productCsrf=authTestToken($editProduct);
    $productInput=['csrf'=>$productCsrf,'action'=>'save_product','name'=>$prefix.'-edited','sale_enabled'=>'1','purchase_enabled'=>'1','sale_price'=>'5.00','product_type'=>'service','invoice_policy'=>'ordered'];
    [$code]=authTestRequest($server,'/modules/inventario/index.php?id='.$product,$productCookie,$productInput+['cost_price'=>'99']);aclAssert($code===403,'Editar productos permite cambiar costos sin autorización.');
    [$code]=authTestRequest($server,'/modules/inventario/index.php?id='.$product,$productCookie,$productInput+['commission_type'=>'percent','commission_value'=>'99']);aclAssert($code===403,'Editar productos permite configurar comisión sin autorización.');
    [$code]=authTestRequest($server,'/modules/inventario/index.php?id='.$product,$productCookie,$productInput);$current=$db->query('SELECT cost_price,commission_type,commission_value FROM inventory_products WHERE id='.$product)->fetch();
    aclAssert($code===303&&$current['cost_price']==='3.00'&&$current['commission_type']==='fixed'&&bccomp($current['commission_value'],'2',4)===0&&(int)$db->query('SELECT COUNT(*) FROM product_supplier_prices WHERE product_id='.$product)->fetchColumn()===1,'Guardar el producto pierde los valores protegidos.');
    $settings=accountingCurrencies($db);$method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type) VALUES(?,'cash')")->execute([$prefix]);$account=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_treasury_documents(name,kind,account_id,currency_code,amount,primary_code,alternate_code,movement_date) VALUES(?,'expense',?,?,3,?,?,?)")->execute([$prefix.'-expense',$account,$settings['primary_code'],$settings['primary_code'],$settings['alternate_code'],commerceToday()]);$expense=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_payments(name,partner_type,direction,contact_id,amount,currency_code,primary_code,alternate_code,payment_date,reference,account_id,method_id) VALUES(?,'customer','inbound',?,7,?,?,?,?,'',?,?)")->execute([$prefix.'-payment',$clients[1],$settings['primary_code'],$settings['primary_code'],$settings['alternate_code'],commerceToday(),$account,$method]);$payment=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO purchase_orders(name,supplier_id,order_date) VALUES(?,?,?)")->execute([$prefix.'-purchase',$clients[1],commerceToday()]);$purchase=(int)$db->lastInsertId();
    [, $financeForm]=authTestRequest($server,'/modules/contabilidad/index.php?type=customer&id='.$invoices[1],$financeCookie);$financeCsrf=authTestToken($financeForm);
    aclAssert(!str_contains($financeForm,'data-invoice-wizard-open'),'Consultar facturas muestra asistentes de pago o nota de crédito.');
    foreach([
        ['/modules/contabilidad/index.php?type=customer&id='.$invoices[1],['confirm_invoice','cancel_invoice','register_payment','settle_payment','cancel_payment','create_credit','apply_existing_payment']],
        ['/modules/contabilidad/pagos.php?type=customer&id='.$payment,['post_payment','settle_payment','cancel_payment','apply_payment','unapply_payment','reverse_payment']],
        ['/modules/contabilidad/tesoreria.php?id='.$expense,['confirm_treasury','cancel_treasury','reverse_treasury']],
        ['/modules/inventario/operaciones.php?type=delivery&id='.$operations[1],['confirm_operation','check_ready','validate_operation','cancel_operation','create_return']],
        ['/modules/compras/orden.php?id='.$purchase,['confirm_purchase','cancel_purchase','create_receipt','create_invoice']],
        ['/modules/ventas/comisiones.php?id='.$invoices[1],['pay_commission']],
    ] as [$path,$actions])foreach($actions as $action){[$code]=authTestRequest($server,$path,$financeCookie,['csrf'=>$financeCsrf,'action'=>$action,'version'=>'1']);aclAssert($code===403,'Consultar permite ejecutar '.$action);}
    [$code,$expenseForm]=authTestRequest($server,'/modules/contabilidad/tesoreria.php?id='.$expense,$financeCookie);aclAssert($code===200&&!str_contains($expenseForm,'value="confirm_treasury"'),'El permiso de egresos no reconoce su ficha o muestra confirmación.');
    [$code]=authTestRequest($server,'/modules/contabilidad/tesoreria.php?kind=transfer&new=1',$financeCookie);aclAssert($code===403,'Consulta puede crear transferencias.');
    [$code]=authTestRequest($server,'/modules/contabilidad/tesoreria.php?kind=opening&new=1',$financeCookie);aclAssert($code===403,'Consulta puede crear saldos iniciales.');
    [, $home]=authTestRequest($server,'/index.php',$ownCookie);aclAssert(str_contains($home,'/modules/ventas/index.php')&&!str_contains($home,'/modules/compras/index.php')&&!str_contains($home,'/modules/ajustes/index.php'),'Inicio concede módulos ajenos al grupo.');
    [$code,$list]=authTestRequest($server,'/modules/ventas/index.php?q='.$prefix,$ownCookie);aclAssert($code===200&&str_contains($list,$prefix.'-sale1')&&!str_contains($list,$prefix.'-sale2'),'El listado no limita las ventas propias.');
    [, $contacts]=authTestRequest($server,'/modules/contactos/index.php?q='.$prefix,$ownCookie);aclAssert(str_contains($contacts,$prefix.'-client1')&&!str_contains($contacts,$prefix.'-client2'),'Contactos filtra mal los clientes propios.');
    foreach(['/modules/ventas/index.php?id='.$sales[2],'/modules/contactos/index.php?id='.$clients[2],'/modules/inventario/operaciones.php?type=delivery&id='.$operations[2],'/modules/contabilidad/index.php?type=customer&id='.$invoices[2],'/modules/ventas/comisiones.php?id='.$invoices[2],'/modules/ventas/comisiones.php?seller_id='.$sellers[2],'/modules/contabilidad/tesoreria.php?kind=expense','/modules/dashboard/ingresos-egresos.php'] as $path){[$code]=authTestRequest($server,$path,$ownCookie);aclAssert($code===403,'Se pudo abrir una URL prohibida: '.$path);}
    [, $deliveries]=authTestRequest($server,'/modules/inventario/operaciones.php?type=delivery&q='.$prefix,$ownCookie);aclAssert(str_contains($deliveries,$prefix.'-delivery1')&&!str_contains($deliveries,$prefix.'-delivery2'),'Las entregas no respetan la venta de origen.');
    [, $bills]=authTestRequest($server,'/modules/contabilidad/index.php?type=customer&q='.$prefix,$ownCookie);aclAssert(str_contains($bills,$prefix.'-invoice1')&&!str_contains($bills,$prefix.'-invoice2'),'Las facturas propias muestran otro vendedor.');
    [, $commissions]=authTestRequest($server,'/modules/ventas/comisiones.php',$ownCookie);aclAssert(str_contains($commissions,$prefix.'-invoice1')&&!str_contains($commissions,$prefix.'-invoice2'),'Las comisiones no están limitadas al vendedor.');
    [, $form]=authTestRequest($server,'/modules/ventas/index.php?id='.$sales[1],$readCookie);$csrf=authTestToken($form);
    aclAssert(!str_contains($form,'value="confirm_sale"')&&!str_contains($form,'>Guardar cotización<')&&!str_contains($form,'href="index.php?new=1"'),'Consulta muestra acciones de modificación.');
    [$code]=authTestRequest($server,'/modules/ventas/index.php?id='.$sales[1],$readCookie,['csrf'=>$csrf,'action'=>'confirm_sale','version'=>'1']);aclAssert($code===403,'Consulta confirmó una venta mediante POST.');
    [$code]=authTestRequest($server,'/modules/ventas/index.php?new=1',$readCookie);aclAssert($code===403,'Consulta puede crear órdenes.');
    [, $productForm]=authTestRequest($server,'/modules/inventario/index.php?id='.$product,$readCookie);aclAssert(!str_contains($productForm,'name="cost_price"')&&!str_contains($productForm,'name="supplier_prices['),'Se muestra el costo sin permiso.');
    [, $ownForm]=authTestRequest($server,'/modules/ventas/index.php?new=1',$ownCookie);$ownCsrf=authTestToken($ownForm);aclAssert(!str_contains($ownForm,$prefix.'-client2')&&!str_contains($ownForm,$prefix.'-seller2'),'Los selectores exponen contactos ajenos.');
    $post=['csrf'=>$ownCsrf,'action'=>'save_quotation','order_type'=>'invoice','customer_id'=>$clients[1],'order_date'=>commerceToday(),'lines'=>[['product_id'=>$product,'quantity'=>'1','unit_price'=>'7']]];
    [$code]=authTestRequest($server,'/modules/ventas/index.php?new=1',$ownCookie,$post+['salesperson_id'=>$sellers[2]]);aclAssert($code===403,'Crear permite cambiar a otro vendedor.');
    [$code]=authTestRequest($server,'/modules/ventas/index.php?new=1',$ownCookie,array_replace($post,['customer_id'=>$clients[2]]));aclAssert($code===403,'Crear permite asignar un cliente no autorizado.');
    [$code,,$location]=authTestRequest($server,'/modules/ventas/index.php?new=1',$ownCookie,$post);aclAssert($code===303&&preg_match('/id=(\d+)/',$location,$match),'No se pudo crear la venta propia.');
    aclAssert((int)loadSalesQuotation($db,(int)$match[1])['salesperson_id']===$sellers[1],'Crear no fija el vendedor del usuario.');
    [$code,$snapshot]=authTestRequest($server,'/modules/dashboard/resumen.php',$ownCookie);$json=json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);
    aclAssert($code===200&&$json['currencies']===[]&&!isset($json['dashboard']['metrics']['payables'])&&!isset($json['dashboard']['metrics']['unallocated'])&&!str_contains($snapshot,$prefix.'-client2')&&!str_contains($snapshot,$prefix.'-invoice2')&&count($json['commissions'])===1&&!$json['commissions'][0]['can_pay'],'Dashboard filtra mal fuentes, finanzas o comisiones.');
    [, $dashboard]=authTestRequest($server,'/modules/dashboard/index.php',$ownCookie);aclAssert(!str_contains($dashboard,'id="dashboard-tab-income"')&&str_contains($dashboard,'data-dashboard-filter')&&!str_contains($dashboard,'>Pagar comisión<'),'Las pestañas no respetan los permisos o pierden actualización automática.');
    if(in_array('--export-views',$argv,true)){
        foreach(['home'=>$home,'readonly-sale'=>$form,'dashboard'=>$dashboard] as $name=>$view){$view=str_replace(['href="../../css/','src="../../js/','href="css/','src="js/'],['href="http://localhost/project/css/','src="http://localhost/project/js/','href="http://localhost/project/css/','src="http://localhost/project/js/'],$view);file_put_contents(sys_get_temp_dir().'/nexo-phase4-'.$name.'.html',$view);}
        file_put_contents(sys_get_temp_dir().'/nexo-phase4-snapshot.json',$snapshot);
    }
    [, $configHome]=authTestRequest($server,'/index.php',$configCookie);aclAssert(str_contains($configHome,'/modules/inventario/configuracion.php'),'Un permiso de configuración no abre su módulo.');
    [$code,,$location]=authTestRequest($server,'/modules/inventario/index.php',$configCookie);aclAssert($code===303&&str_contains($location,'configuracion.php'),'El módulo no dirige a su primera sección permitida.');
    [, $categoryForm]=authTestRequest($server,'/modules/inventario/configuracion.php?section=categories',$configCookie);aclAssert(!str_contains($categoryForm,'new=1'),'Consulta de configuración permite crear.');
    [$code]=authTestRequest($server,'/modules/inventario/configuracion.php?section=categories&new=1',$configCookie);aclAssert($code===403,'Se puede configurar sin permiso.');
    // Leer Todos desde otro grupo nunca amplía Editar Propios.
    $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$own,$readGroup]);
    [$code]=authTestRequest($server,'/modules/ventas/index.php?id='.$sales[2],$ownCookie);aclAssert($code===200,'No se combinan los permisos de consulta.');
    [$code]=authTestRequest($server,'/modules/ventas/index.php?id='.$sales[2],$ownCookie,['csrf'=>$ownCsrf,'action'=>'save_quotation','version'=>'1']);aclAssert($code===403,'Consultar Todos amplía indebidamente Editar Propios.');
    $db->prepare('DELETE FROM auth_user_groups WHERE user_id=? AND group_id=?')->execute([$own,$readGroup]);
    $db->prepare('UPDATE access_groups SET active=0 WHERE id=?')->execute([$ownGroup]);
    [$code]=authTestRequest($server,'/modules/ventas/index.php',$ownCookie);aclAssert($code===403,'Archivar un grupo deja permisos antiguos en la sesión.');
    // Un administrador también respeta las casillas operativas desmarcadas.
    $adminCookie=$login(strtolower($prefix));$adminGroup=(int)$db->query("SELECT id FROM access_groups WHERE system_key='administrator'")->fetchColumn();
    $db->prepare("DELETE FROM access_group_permissions WHERE group_id=? AND permission_code='compras.orders.read'")->execute([$adminGroup]);
    [$code]=authTestRequest($server,'/modules/compras/orden.php?id=1',$adminCookie);aclAssert($code===403,'Administrador tiene un permiso operativo implícito.');
    echo "PASS: módulos, vistas, URL/POST, Propios/Todos, selectores, Dashboard, costos, configuración, grupos archivados y administrador sin excepciones operativas.\n";
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($server);}

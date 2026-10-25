<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function editHttpCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'edit-actions-');$readonlyCookie=tempnam(sys_get_temp_dir(),'edit-actions-readonly-');
try {
    $db=$s['db'];$token=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(12));$login='edit.'.bin2hex(random_bytes(5));
    $actor=createFirstAdministrator($db,['name'=>'Acciones de listas','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    [, $body]=authTestRequest($s,'/login.php',$cookie);
    editHttpCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($body),'login'=>$login,'password'=>$password])[0]===303,'No se inició la sesión.');
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.(int)$actor);
    $insert=static function(string $table,array $data)use($db):int {
        $names=array_keys($data);$q=$db->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');$q->execute(array_values($data));return (int)$db->lastInsertId();
    };
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $contact=$insert('contacts',['name'=>'Contacto para edición','type_id'=>$type]);
    $product=$insert('inventory_products',['name'=>'Producto para edición','sale_enabled'=>1]);
    $insert('inventory_products',['name'=>'Variante para edición','parent_product_id'=>$product,'sale_enabled'=>1]);
    $insert('sales_quotations',['name'=>'S-EDIT','customer_id'=>$contact,'order_date'=>date('Y-m-d')]);
    $supplierType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='supplier'")->fetchColumn();
    $supplier=$insert('contacts',['name'=>'Proveedor para edición','type_id'=>$supplierType]);
    $insert('purchase_orders',['name'=>'P-EDIT','supplier_id'=>$supplier,'order_date'=>date('Y-m-d')]);
    $insert('inventory_categories',['name'=>'Categoría para edición']);
    $warehouse=$insert('inventory_warehouses',['name'=>'Almacén para edición']);
    $location=$insert('inventory_locations',['name'=>'Ubicación para edición','warehouse_id'=>$warehouse]);
    foreach(['delivery','receipt','internal'] as $type)$insert('inventory_operations',['name'=>'OP-EDIT-'.$type,'operation_type'=>$type,'scheduled_date'=>date('Y-m-d'),'source_location_id'=>$location,'destination_location_id'=>$location]);
    foreach(['ventas','compras'] as $module)$insert('business_taxes',['name'=>'Impuesto para edición','module_key'=>$module,'rate'=>'16.00']);
    $account=$insert('accounting_payment_accounts',['name'=>'Cuenta para edición','account_type'=>'cash','currency_code'=>'USD']);
    $method=$insert('accounting_payment_methods',['name'=>'Medio para edición']);
    foreach(['customer'=>$contact,'supplier'=>$supplier] as $partner=>$id){
        $insert('accounting_invoices',['name'=>'F-EDIT-'.$partner,'invoice_type'=>$partner,'contact_id'=>$id,'invoice_date'=>date('Y-m-d'),'base_amount'=>'1.00','total_amount'=>'1.00']);
        $insert('accounting_payments',['name'=>'PAY-EDIT-'.$partner,'partner_type'=>$partner,'direction'=>'inbound','contact_id'=>$id,'amount'=>'1.00','currency_code'=>'USD','primary_code'=>'USD','alternate_code'=>'VES','payment_date'=>date('Y-m-d'),'reference'=>'Edición','account_id'=>$account,'method_id'=>$method]);
    }
    foreach(['income','expense','opening','transfer'] as $kind)$insert('accounting_treasury_documents',['name'=>'T-EDIT-'.$kind,'kind'=>$kind,'account_id'=>$account,'currency_code'=>'USD','amount'=>'1.00','primary_code'=>'USD','alternate_code'=>'VES','movement_date'=>date('Y-m-d')]);
    $area=$insert('restaurant_areas',['name'=>'Área para edición']);
    $insert('restaurant_tables',['name'=>'Mesa para edición','area_id'=>$area]);
    $routes=['contactos/index.php','contactos/configuracion.php?section=countries','contactos/configuracion.php?section=states',
        'contactos/configuracion.php?section=cities','contactos/configuracion.php?section=types','contactos/configuracion.php?section=document_types',
        'inventario/index.php','inventario/variantes.php','inventario/configuracion.php?section=categories',
        'inventario/configuracion.php?section=warehouses','inventario/configuracion.php?section=locations',
        'inventario/operaciones.php?type=delivery','inventario/operaciones.php?type=receipt','inventario/operaciones.php?type=internal',
        'ventas/index.php','compras/index.php','ventas/configuracion.php?section=taxes','compras/configuracion.php?section=taxes',
        'contabilidad/index.php?type=customer','contabilidad/index.php?type=supplier','contabilidad/pagos.php?type=customer','contabilidad/pagos.php?type=supplier',
        'contabilidad/configuracion.php?section=currencies','contabilidad/configuracion.php?section=payment_terms',
        'contabilidad/configuracion.php?section=accounts','contabilidad/configuracion.php?section=methods','contabilidad/configuracion.php?section=concepts',
        'contabilidad/tesoreria.php','contabilidad/tesoreria.php?kind=income','contabilidad/tesoreria.php?kind=expense','contabilidad/tesoreria.php?kind=opening','contabilidad/tesoreria.php?kind=transfer',
        'ajustes/usuarios.php','ajustes/grupos.php','ajustes/empresa.php',
        'restaurante/configuracion.php?section=restaurant_areas','restaurante/configuracion.php?section=restaurant_tables'];
    foreach($routes as $route) {
        echo 'RUN '.$route.PHP_EOL;
        [$status,$html]=authTestRequest($s,'/modules/'.$route,$cookie);
        editHttpCheck($status===200&&!preg_match('/Fatal error|Warning:/',$html),'No abrió '.$route);
        $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);libxml_clear_errors();
        $tables=$xp->query('//main//table[@data-record-edit-actions]');editHttpCheck($tables->length>0,'No se estandarizó '.$route);
        foreach($tables as $table) {
            $headers=$xp->query('./thead/tr/th',$table);$columns=$headers->length;
            editHttpCheck(trim($headers->item($columns-1)->textContent)==='Acciones','Acciones no está al final en '.$route);
            foreach($xp->query('./tbody/tr',$table) as $row) {
                $cells=$xp->query('./td',$row);
                if($cells->length===1&&$cells->item(0)->hasAttribute('colspan')){editHttpCheck((int)$cells->item(0)->getAttribute('colspan')===$columns,'Colspan incorrecto en '.$route);continue;}
                editHttpCheck($cells->length===$columns,'Fila desalineada en '.$route);
                editHttpCheck($xp->query('.//a[contains(@class,"record-edit-link")]',$row)->length===1,'Falta o se duplicó Editar en '.$route);
                editHttpCheck($xp->query('./td[last()]//a[contains(@class,"record-edit-link") and text()="Editar"]',$row)->length===1,'Editar no está al final en '.$route);
            }
        }
    }
    [, $html]=authTestRequest($s,'/modules/ventas/index.php?new=1',$cookie);
    editHttpCheck(!str_contains($html,'data-record-edit-actions'),'Se modificaron las líneas de una nueva cotización.');
    [, $html]=authTestRequest($s,'/modules/inventario/informes.php?view=stock',$cookie);
    editHttpCheck(!str_contains($html,'data-record-edit-actions'),'Se agregó edición a existencias calculadas.');
    [, $html]=authTestRequest($s,'/modules/contactos/index.php?q=ningun-registro-para-editar',$cookie);
    editHttpCheck(str_contains($html,'data-record-edit-actions'),'Un filtro vacío perdió la columna común.');
    $group=$insert('access_groups',['name'=>'Solo consultar contactos']);
    $insert('access_group_permissions',['group_id'=>$group,'permission_code'=>'contactos.contacts.read','record_scope'=>'all']);
    $readonlyLogin='read.'.bin2hex(random_bytes(5));
    $user=$insert('auth_users',['name'=>'Consulta de listas','login'=>$readonlyLogin,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>0]);
    $insert('auth_user_groups',['user_id'=>$user,'group_id'=>$group]);$insert('company_user_access',['user_id'=>$user,'company_id'=>1]);
    [, $body]=authTestRequest($s,'/login.php',$readonlyCookie);
    editHttpCheck(authTestRequest($s,'/login.php',$readonlyCookie,['csrf'=>authTestToken($body),'login'=>$readonlyLogin,'password'=>$password])[0]===303,'No inició sesión el usuario de consulta.');
    [$status,$html]=authTestRequest($s,'/modules/contactos/index.php',$readonlyCookie);
    editHttpCheck($status===200&&str_contains($html,'data-record-edit-actions')&&!str_contains($html,'record-edit-link'),'Se ofreció Editar a un usuario de solo consulta.');
    echo 'PASS: '.count($routes).' listas con Editar al final, sin duplicados; empresa, filtros, fichas y tablas de líneas conservados.'.PHP_EOL;
} finally { foreach([$cookie,$readonlyCookie] as $file)if(is_file($file))unlink($file);stopAuthTestServer($s); }

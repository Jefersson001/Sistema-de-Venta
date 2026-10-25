<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/record-actions-schema.php';
require_once dirname(__DIR__).'/php/record-actions.php';
require_once dirname(__DIR__).'/php/document-print.php';
require_once dirname(__DIR__).'/php/company.php';
require_once dirname(__DIR__).'/php/bcv-sync.php';
function actionCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function actionReject(callable $work):void{try{$work();}catch(InvalidArgumentException|PDOException){return;}throw new RuntimeException('Se aceptó una acción inválida.');}
function actionPath(string $source,string $action,array $extra=[]):string{return '/acciones.php?'.http_build_query(['source'=>'/project/'.$source,'action'=>$action]+$extra);}
echo "RUN isolated actions and document templates\n";$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'nexo-actions-');$guest=tempnam(sys_get_temp_dir(),'nexo-actions-guest-');
try{
    $raw=$s['db'];installRecordActions($raw);installRecordActions($raw);
    $login='actions.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(12));$token=prepareFirstAdministrator($raw);
    $actor=createFirstAdministrator($raw,['name'=>'Administrador de acciones','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $GLOBALS['permission_user']=loadAuthUser($raw,$actor);
    $c=require dirname(__DIR__).'/php/config/database.php';$tables=$raw->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    $connect=static fn(array $selected,int $active)=>new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$s['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],$selected,$active,$actor,$tables);
    $db=$connect([1],1);$prefix=bin2hex(random_bytes(5));
    bcvApplyPublication($raw,['rate'=>'100.00','effective_date'=>commerceToday(),'source'=>'bcv']);
    $insert=static function(string $table,array $data)use($db):int{return transferWriteMaster($db,$table,$data,null);};
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();$supplierType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='supplier'")->fetchColumn();
    $customer=$insert('contacts',['name'=>'Cliente <seguro> '.$prefix,'type_id'=>$type]);$supplier=$insert('contacts',['name'=>'Proveedor '.$prefix,'type_id'=>$supplierType]);
    $product=$insert('inventory_products',['name'=>'Producto '.$prefix,'sale_enabled'=>1,'purchase_enabled'=>1,'product_type'=>'service','sale_price'=>'10.00','currency_code'=>'USD']);
    $spare=$insert('inventory_products',['name'=>'Sin uso '.$prefix]);
    $line=['product_id'=>$product,'product_name'=>'Producto '.$prefix,'quantity'=>'2.00','unit_price'=>'10.00','tax_rate'=>'16.00','tax_amount'=>'3.20','base_amount'=>'20.00','total_amount'=>'23.20','position'=>0];
    $sale=$insert('sales_quotations',['name'=>'S-REPORT-'.$prefix,'customer_id'=>$customer,'order_date'=>commerceToday(),'base_amount'=>'20.00','tax_amount'=>'3.20','total_amount'=>'23.20','invoice_address'=>'Dirección de prueba']);
    $saleLine=$insert('sales_quotation_lines',$line+['quotation_id'=>$sale]);
    $purchase=$insert('purchase_orders',['name'=>'P-REPORT-'.$prefix,'supplier_id'=>$supplier,'order_date'=>commerceToday(),'base_amount'=>'20.00','tax_amount'=>'3.20','total_amount'=>'23.20']);
    $insert('purchase_order_lines',$line+['order_id'=>$purchase]);
    $warehouse=$insert('inventory_warehouses',['name'=>'Almacén '.$prefix]);$location=$insert('inventory_locations',['name'=>'Estante '.$prefix,'warehouse_id'=>$warehouse]);
    $operations=[];foreach(['delivery','receipt','internal'] as $operationType){$op=$insert('inventory_operations',['name'=>'OP-'.$operationType.'-'.$prefix,'operation_type'=>$operationType,'scheduled_date'=>commerceToday(),'source_location_id'=>$location,'destination_location_id'=>$location,'notes'=>'Recibir con cuidado']);$insert('inventory_operation_lines',['operation_id'=>$op,'product_id'=>$product,'demand'=>'2.00','quantity'=>'1.00','position'=>0]);$operations[$operationType]=$op;}
    $invoices=[];foreach(['customer','supplier'] as $invoiceType){$id=$insert('accounting_invoices',['name'=>'F-'.$invoiceType.'-'.$prefix,'invoice_type'=>$invoiceType,'contact_id'=>$invoiceType==='customer'?$customer:$supplier,'invoice_date'=>commerceToday(),'sale_id'=>$invoiceType==='customer'?$sale:null,'purchase_id'=>$invoiceType==='supplier'?$purchase:null,'base_amount'=>'20.00','tax_amount'=>'3.20','total_amount'=>'23.20']);$insert('accounting_invoice_lines',$line+['invoice_id'=>$id]);$invoices[$invoiceType]=$id;}
    $credit=$insert('accounting_invoices',['name'=>'NC-'.$prefix,'invoice_type'=>'customer','document_type'=>'credit','reversal_of_id'=>$invoices['customer'],'contact_id'=>$customer,'invoice_date'=>commerceToday(),'base_amount'=>'20.00','tax_amount'=>'3.20','total_amount'=>'23.20']);$insert('accounting_invoice_lines',$line+['invoice_id'=>$credit]);
    $r=authTestRequest($s,'/login.php',$cookie);$r=authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password]);actionCheck($r[0]===303,'No inicia sesión.');
    $documents=[['modules/ventas/index.php?id='.$sale,'Cotización'],['modules/compras/index.php?id='.$purchase,'Solicitud de cotización'],['modules/contabilidad/index.php?type=customer&id='.$invoices['customer'],'Factura de cliente'],['modules/contabilidad/index.php?type=supplier&id='.$invoices['supplier'],'Factura de proveedor'],['modules/contabilidad/index.php?type=customer&id='.$credit,'Nota de crédito de cliente']];
    foreach($operations as $type=>$id)$documents[]=['modules/inventario/operaciones.php?type='.$type.'&id='.$id,['delivery'=>'Orden de entrega','receipt'=>'Orden de recepción','internal'=>'Traslado interno'][$type]];
    foreach($documents as [$source,$title]){$r=authTestRequest($s,actionPath($source,'print'),$cookie);actionCheck($r[0]===200&&str_contains($r[1],$title)&&str_contains($r[1],'data-print-document'),'No imprime '.$title.': '.mb_substr(strip_tags($r[1]),-500));actionCheck(!str_contains($r[1],'Cliente <seguro>'),'Texto sin escapar en el reporte.');}
    $db->prepare("UPDATE sales_quotations SET status='sale' WHERE id=?")->execute([$sale]);$r=authTestRequest($s,actionPath('modules/ventas/index.php?id='.$sale,'print'),$cookie);actionCheck(str_contains($r[1],'Orden de venta'),'No cambia el tipo de venta.');
    $db->prepare("UPDATE purchase_orders SET status='purchase' WHERE id=?")->execute([$purchase]);actionCheck(str_contains(authTestRequest($s,actionPath('modules/compras/index.php?id='.$purchase,'print'),$cookie)[1],'Orden de compra'),'No cambia el tipo de compra.');
    actionCheck(authTestRequest($s,actionPath('modules/ventas/index.php?id='.$sale,'print'),$guest)[0]===303,'Se imprimió sin sesión.');
    actionCheck(authTestRequest($s,actionPath('modules/ventas/index.php?id=99999999','print'),$cookie)[0]===404,'Imprime un documento inexistente.');
    echo "RUN shared menus, navigation and list selection\n";
    $r=authTestRequest($s,'/modules/inventario/index.php',$cookie);actionCheck($r[0]===200&&str_contains($r[1],'data-record-select-all')&&str_contains($r[1],'data-record-select'),'Falta selección múltiple.');
    $r=authTestRequest($s,'/modules/inventario/index.php?id='.$product,$cookie);actionCheck($r[0]===200&&str_contains($r[1],'Navegar entre registros')&&str_contains($r[1],'Duplicar'),'Faltan acciones en la ficha.');
    echo "RUN duplicates, idempotency and no stock/payment side effects\n";
    $models=transferModels();$row=recordActionRow($db,$models['sale_orders'],$sale);$snap=[$sale=>recordActionSnapshot($db,$models['sale_orders'],$row)];$key=bin2hex(random_bytes(32));
    $copy=recordApplyAction($db,$models['sale_orders'],'duplicate',$snap,$key);actionCheck(recordApplyAction($db,$models['sale_orders'],'duplicate',$snap,$key)===$copy,'Se duplicó el reintento.');
    $new=recordActionRow($db,$models['sale_orders'],$copy[0]);actionCheck($new['status']==='draft'&&$new['name']!==$row['name'],'La copia conserva el estado o correlativo.');
    $q=$db->prepare('SELECT COUNT(*) FROM inventory_operations WHERE quotation_id=?');$q->execute([$new['id']]);actionCheck((int)$q->fetchColumn()===0,'La copia generó una entrega.');
    $row=recordActionRow($db,$models['products'],$product);$productCopy=recordApplyAction($db,$models['products'],'duplicate',[$product=>recordActionSnapshot($db,$models['products'],$row)],bin2hex(random_bytes(32)))[0];actionCheck($productCopy!==$product,'No duplica el producto.');
    $row=recordActionRow($db,$models['contacts'],$customer);$contactCopy=recordApplyAction($db,$models['contacts'],'duplicate',[$customer=>recordActionSnapshot($db,$models['contacts'],$row)],bin2hex(random_bytes(32)))[0];actionCheck($contactCopy!==$customer,'No duplica el contacto.');
    $goods=$insert('inventory_products',['name'=>'Mercancía '.$prefix,'product_type'=>'goods']);$secondLocation=$insert('inventory_locations',['name'=>'Destino '.$prefix,'warehouse_id'=>$warehouse]);
    $db->prepare('UPDATE inventory_operations SET destination_location_id=? WHERE id=?')->execute([$secondLocation,$operations['internal']]);$db->prepare('UPDATE inventory_operation_lines SET product_id=? WHERE operation_id=?')->execute([$goods,$operations['internal']]);
    $row=recordActionRow($db,$models['internal_operations'],$operations['internal']);$operationCopy=recordApplyAction($db,$models['internal_operations'],'duplicate',[$row['id']=>recordActionSnapshot($db,$models['internal_operations'],$row)],bin2hex(random_bytes(32)))[0];
    $newOperation=recordActionRow($db,$models['internal_operations'],$operationCopy);$q=$db->prepare('SELECT quantity FROM inventory_operation_lines WHERE operation_id=?');$q->execute([$operationCopy]);actionCheck($newOperation['status']==='draft'&&$q->fetchColumn()==='0.00','La copia conserva cantidades realizadas.');
    $row=recordActionRow($db,$models['customer_invoices'],$invoices['customer']);$invoiceCopy=recordApplyAction($db,$models['customer_invoices'],'duplicate',[$row['id']=>recordActionSnapshot($db,$models['customer_invoices'],$row)],bin2hex(random_bytes(32)))[0];
    $copyInvoice=recordActionRow($db,$models['customer_invoices'],$invoiceCopy);actionCheck($copyInvoice['status']==='draft'&&$copyInvoice['sale_id']===null&&$copyInvoice['payment_state']==='not_paid','La copia de factura conserva vínculos o pagos.');
    actionCheck(authTestRequest($s,'/modules/contabilidad/index.php?type=customer&id='.$invoiceCopy,$cookie)[0]===200,'No abre una factura duplicada sin orden.');
    echo "RUN delete rollback, stale confirmation and HTTP replay\n";
    $spareRow=recordActionRow($db,$models['products'],$spare);$productRow=recordActionRow($db,$models['products'],$product);
    actionReject(fn()=>recordApplyAction($db,$models['products'],'delete',[$spare=>recordActionSnapshot($db,$models['products'],$spareRow),$product=>recordActionSnapshot($db,$models['products'],$productRow)],bin2hex(random_bytes(32))));
    actionCheck(recordActionRow($db,$models['products'],$spare)['id']===$spare,'La eliminación parcial no se revirtió.');
    $snap=[$spare=>recordActionSnapshot($db,$models['products'],$spareRow)];$db->prepare('UPDATE inventory_products SET name=? WHERE id=?')->execute(['Modificado '.$prefix,$spare]);actionReject(fn()=>recordApplyAction($db,$models['products'],'delete',$snap,bin2hex(random_bytes(32))));
    $path=actionPath('modules/inventario/index.php?q=Modificado+'.$prefix,'delete',['ids'=>[$spare]]);$r=authTestRequest($s,$path,$cookie);actionCheck($r[0]===200&&str_contains($r[1],'Sí, eliminar'),'No abre confirmación desde listado filtrado.');
    $post=['csrf'=>authTestToken($r[1]),'company_scope'=>authTestToken($r[1],'company_scope'),'request_key'=>authTestToken($r[1],'request_key')];
    foreach([1,2] as $attempt){$r=authTestRequest($s,$path,$cookie,$post);actionCheck($r[0]===200&&str_contains($r[1],'Registros eliminados.'),'Falló eliminación o reintento HTTP: '.mb_substr(strip_tags($r[1]),-500));}
    actionReject(fn()=>recordActionRow($db,$models['products'],$spare));
    actionReject(fn()=>recordApplyAction($db,$models['sale_orders'],'delete',[$sale=>recordActionSnapshot($db,$models['sale_orders'],recordActionRow($db,$models['sale_orders'],$sale))],bin2hex(random_bytes(32))));
    echo "RUN cross-company boundary\n";
    $otherId=createBusinessCompany($raw,['name'=>'Empresa ajena '.$prefix],$actor);$other=$connect([$otherId],$otherId);$foreign=transferWriteMaster($other,'inventory_products',['name'=>'Ajeno '.$prefix],null);actionReject(fn()=>recordActionRow($db,$models['products'],$foreign));
    $selected=$connect([1,$otherId],1);$foreignRow=recordActionRow($selected,$models['products'],$foreign);actionReject(fn()=>recordApplyAction($selected,$models['products'],'delete',[$foreign=>recordActionSnapshot($selected,$models['products'],$foreignRow)],bin2hex(random_bytes(32))));
    echo "RUN read-only permissions and protected records\n";
    $group=transferWriteMaster($raw,'access_groups',['name'=>'Solo consultar '.$prefix],null);$raw->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'inventario.products.read','all')")->execute([$group]);
    $reader='reader.'.bin2hex(random_bytes(5));$readerId=transferWriteMaster($raw,'auth_users',['name'=>'Lector','login'=>$reader,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>0],null);$raw->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$readerId,$group]);$raw->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$readerId]);
    $r=authTestRequest($s,'/login.php',$guest);$r=authTestRequest($s,'/login.php',$guest,['csrf'=>authTestToken($r[1]),'login'=>$reader,'password'=>$password]);actionCheck($r[0]===303,'No inicia sesión el lector.');
    actionCheck(authTestRequest($s,actionPath('modules/inventario/index.php?id='.$productCopy,'delete'),$guest)[0]===403,'El lector puede eliminar.');
    $r=authTestRequest($s,'/modules/inventario/index.php',$guest);actionCheck(!str_contains($r[1],'data-record-select')&&str_contains($r[1],'aria-disabled="true"'),'La vista ofrece borrado sin permiso.');
    $systemGroup=(int)$raw->query("SELECT id FROM access_groups WHERE system_key='administrator'")->fetchColumn();$adminGroup=recordActionRow($db,$models['groups'],$systemGroup);actionReject(fn()=>recordApplyAction($db,$models['groups'],'delete',[$systemGroup=>recordActionSnapshot($db,$models['groups'],$adminGroup)],bin2hex(random_bytes(32))));
    $r=authTestRequest($s,actionPath('modules/inventario/index.php?id='.$productCopy,'delete'),$cookie);$badPost=['csrf'=>'invalid','company_scope'=>authTestToken($r[1],'company_scope'),'request_key'=>authTestToken($r[1],'request_key')];actionCheck(authTestRequest($s,actionPath('modules/inventario/index.php?id='.$productCopy,'delete'),$cookie,$badPost)[0]===409,'Se acepta CSRF inválido.');
    if(isset($argv[1])){if(!is_dir($argv[1]))mkdir($argv[1],0700,true);foreach(['commercial'=>actionPath('modules/contabilidad/index.php?type=customer&id='.$invoices['customer'],'print'),'stock'=>actionPath('modules/inventario/operaciones.php?type=internal&id='.$operations['internal'],'print')] as $name=>$path)file_put_contents($argv[1].'/'.$name.'.html',authTestRequest($s,$path,$cookie)[1]);}
    echo "PASS: documentos, selección, navegación, duplicados en borrador, idempotencia, borrado atómico, conflictos, relaciones y empresas.\n";
}finally{unset($GLOBALS['permission_user']);foreach([$cookie,$guest] as $file)if(is_file($file))unlink($file);stopAuthTestServer($s);}

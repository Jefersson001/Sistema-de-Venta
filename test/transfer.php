<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';

function transferTestCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function transferTestScope(string $html): string {if(!preg_match('/name="company_scope" value="([a-f0-9]+)"/',$html,$m))throw new RuntimeException('Falta contexto de empresa.');return $m[1];}
function transferTestPath(string $key,string $action,string $source,array $extra=[]): string {return '/datos.php?'.http_build_query(['model'=>$key,'action'=>$action,'source'=>'/project/'.$source]+$extra);}
function transferTestUpload(array $s,string $path,string $cookie,string $file,string $csrf,string $scope): array
{
    $c=curl_init($s['url'].$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>20,
        CURLOPT_POSTFIELDS=>['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'preview','csv'=>new CURLFile($file,'text/csv','import.csv')]]);
    $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    if(!is_string($body)||preg_match('/Fatal error|Warning(?:<\/b>)?:|Notice(?:<\/b>)?:/',$body))throw new RuntimeException('Falló la carga CSV: '.mb_substr(strip_tags((string)$body),0,1800).' '.mb_substr(strip_tags((string)$body),-1200));
    return [$code,$body];
}
function transferTestPreviewToken(string $html): string {if(!preg_match('/name="token" value="([a-f0-9]+)"/',$html,$m))throw new RuntimeException('No se validó el archivo: '.mb_substr(strip_tags($html),-2400));return $m[1];}
function transferTestImport(array $s,string $path,string $cookie,string $file,string $csv,string $csrf,string $scope): array
{
    file_put_contents($file,$csv);$preview=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    $done=authTestRequest($s,$path,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token]);
    transferTestCheck($done[0]===200&&str_contains($done[1],'registros guardados'),'No se confirmó el lote: '.mb_substr(strip_tags($done[1]),0,1200));return $done;
}

$s=startAuthTestServer();$cookies=[];$files=[];
try {
    echo "RUN transfer menus and schema templates\n";
    $db=$s['db'];$password=bin2hex(random_bytes(12));$login='transfer.'.bin2hex(random_bytes(5));$setup=prepareFirstAdministrator($db);
    $admin=createFirstAdministrator($db,['name'=>'Administrador CSV','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'transfer-cookie-');$cookies[]=$cookie;$r=authTestRequest($s,'/login.php',$cookie);
    transferTestCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password])[0]===303,'Falló login.');
    foreach(['/modules/contactos/index.php','/modules/contactos/index.php?new=1','/modules/inventario/index.php','/modules/ventas/index.php','/modules/compras/index.php','/modules/contabilidad/index.php','/modules/ajustes/usuarios.php','/modules/ajustes/empresa.php','/modules/inventario/configuracion.php?section=categories'] as $path) {
        $r=authTestRequest($s,$path,$cookie);transferTestCheck($r[0]===200&&str_contains($r[1],'data-model-options=')&&str_contains($r[1],'Descargar plantilla'),'No aparece el menú común en '.$path);
    }
    $source='modules/contactos/index.php';$path=transferTestPath('contacts','import',$source);$r=authTestRequest($s,$path,$cookie);$csrf=authTestToken($r[1]);$scope=transferTestScope($r[1]);
    $template=authTestRequest($s,transferTestPath('contacts','template',$source),$cookie);
    transferTestCheck($template[0]===200&&str_starts_with($template[1],"\xEF\xBB\xBFid;")&&str_contains($template[1],'type_id')&&!str_contains($template[1],'created_by'),'La plantilla incluye campos indebidos.');
    foreach(['sale_orders'=>'ventas/index.php','purchase_orders'=>'compras/index.php','customer_invoices'=>'contabilidad/index.php','supplier_invoices'=>'contabilidad/index.php?type=supplier',
        'supplier_payments'=>'contabilidad/pagos.php?type=supplier','delivery_operations'=>'inventario/operaciones.php','receipt_operations'=>'inventario/operaciones.php?type=receipt','internal_operations'=>'inventario/operaciones.php?type=internal',
        'income'=>'contabilidad/tesoreria.php?kind=income','expense'=>'contabilidad/tesoreria.php?kind=expense','transfer'=>'contabilidad/tesoreria.php?kind=transfer','opening'=>'contabilidad/tesoreria.php?kind=opening',
        'treasury'=>'contabilidad/tesoreria.php','stock'=>'inventario/informes.php?view=stock','movements'=>'inventario/informes.php','sold'=>'inventario/informes.php?view=sold','purchased'=>'inventario/informes.php?view=purchased',
        'commissions'=>'ventas/comisiones.php','commission_payments'=>'ventas/pagos-comisiones.php','cash_flow'=>'contabilidad/ingresos-egresos.php',
        'countries'=>'contactos/configuracion.php?section=countries','currencies'=>'contabilidad/configuracion.php?section=currencies','currency_settings'=>'contabilidad/configuracion.php?section=currency_settings'] as $modelKey=>$modelSource) {
        $r=authTestRequest($s,transferTestPath($modelKey,'export','modules/'.$modelSource,['download'=>'1','fields'=>[$modelKey==='stock'?'product_id':'id']]),$cookie);
        transferTestCheck($r[0]===200&&str_starts_with($r[1],"\xEF\xBB\xBF"),'No se pudo exportar '.$modelKey.': '.mb_substr(strip_tags($r[1]),-600));
    }
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();$prefix='CSV-'.bin2hex(random_bytes(5));
    $file=tempnam(sys_get_temp_dir(),'transfer-data-');$files[]=$file;
    file_put_contents($file,"name;entity_kind;type_id;email;notes\n$prefix A;person;$type;a@example.com;=1+1\n$prefix B;person;$type;invalido;Nota\n");
    $q=$db->prepare('SELECT COUNT(*) FROM contacts WHERE name LIKE ?');$q->execute([$prefix.'%']);$before=(int)$q->fetchColumn();
    $bad=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);transferTestCheck(str_contains($bad[1],'Fila 3:')&&!str_contains($bad[1],'name="token"'),'No se identificó la fila inválida.');
    echo "RUN preview and atomic confirmation\n";
    $q->execute([$prefix.'%']);transferTestCheck((int)$q->fetchColumn()===$before,'Una validación fallida guardó registros.');
    file_put_contents($file,"name;entity_kind;type_id;email;notes\n$prefix A;person;$type;a@example.com;=1+1\n$prefix B;person;$type;b@example.com;Nota\n");
    $preview=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    $q->execute([$prefix.'%']);transferTestCheck((int)$q->fetchColumn()===$before,'La vista previa guardó registros.');
    $confirm=['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token];
    $done=authTestRequest($s,$path,$cookie,$confirm);transferTestCheck($done[0]===200&&str_contains($done[1],'2 registros guardados'),'No se confirmó la importación: '.mb_substr(strip_tags($done[1]),-1000));
    $q->execute([$prefix.'%']);transferTestCheck((int)$q->fetchColumn()===$before+2,'No se guardó el lote completo.');
    transferTestCheck(authTestRequest($s,$path,$cookie,$confirm)[0]===409,'Un reenvío duplicó el lote.');
    $exportSource=$source.'?q='.rawurlencode($prefix);
    echo "RUN export and concurrent updates\n";
    $r=authTestRequest($s,transferTestPath('contacts','export',$exportSource,['download'=>'1','fields'=>['id','name','email','notes','company_name']]),$cookie);
    transferTestCheck($r[0]===200&&substr_count($r[1],$prefix)===2&&str_contains($r[1],"'=1+1"),'La exportación no aplica filtros o no protege fórmulas.');
    $first=(int)$db->query("SELECT id FROM contacts WHERE name=".$db->quote($prefix.' A'))->fetchColumn();
    file_put_contents($file,"id;name;email\n$first;$prefix actualizado;a@example.com\n");
    $preview=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    $db->prepare('UPDATE contacts SET phone=? WHERE id=?')->execute(['Cambio concurrente',$first]);
    transferTestCheck(authTestRequest($s,$path,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token])[0]===409,'Se sobrescribió un registro cambiado tras la vista previa.');
    // Draft documents use nested repository transactions; an invalid second row rolls back the first.
    $productSource='modules/inventario/index.php';$productPath=transferTestPath('products','import',$productSource);
    echo "RUN products and document lines\n";
    $r=authTestRequest($s,$productPath,$cookie);$csrf=authTestToken($r[1]);$scope=transferTestScope($r[1]);
    file_put_contents($file,"name;sale_enabled;purchase_enabled;sale_price;currency_code;product_type;invoice_policy\n$prefix servicio;1;1;5;USD;service;ordered\n");
    $preview=transferTestUpload($s,$productPath,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    transferTestCheck(authTestRequest($s,$productPath,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token])[0]===200,'No se importó el producto.');
    $product=(int)$db->query('SELECT id FROM inventory_products WHERE name='.$db->quote($prefix.' servicio'))->fetchColumn();
    $salePath=transferTestPath('sale_orders','import','modules/ventas/index.php');$r=authTestRequest($s,$salePath,$cookie);$csrf=authTestToken($r[1]);$scope=transferTestScope($r[1]);
    file_put_contents($file,"document_key;customer_id;order_date;order_type;currency_code;line.product_id;line.quantity;line.unit_price\nV1;$first;2026-10-23;invoice;USD;$product;2;5\nV1;$first;2026-10-23;invoice;USD;$product;1;7\n");
    $preview=transferTestUpload($s,$salePath,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    transferTestCheck(authTestRequest($s,$salePath,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token])[0]===200,'No se importó el documento con líneas.');
    $q=$db->prepare('SELECT id,status,total_amount FROM sales_quotations WHERE customer_id=? ORDER BY id DESC LIMIT 1');$q->execute([$first]);$sale=$q->fetch();
    transferTestCheck($sale&&$sale['status']==='draft'&&$sale['total_amount']==='17.00','La importación omitió las reglas de importes o confirmó una venta.');
    $r=authTestRequest($s,transferTestPath('sale_orders','export','modules/ventas/index.php?id='.$sale['id'],['download'=>'1','fields'=>['id','document_key','line.product_id','line.quantity']]),$cookie);
    transferTestCheck($r[0]===200&&substr_count($r[1],'documento-'.$sale['id'])===2,'No se exportaron las líneas de la ficha.');
    echo "RUN nested rollback, accounts and private fields\n";
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,sale_price,product_type,invoice_policy,currency_code) VALUES(?,1,1,5,'service','ordered','USD')")->execute([$prefix.' segundo']);$secondProduct=(int)$db->lastInsertId();
    file_put_contents($file,"document_key;customer_id;order_date;order_type;currency_code;line.product_id;line.quantity;line.unit_price\nV2;$first;2026-10-23;invoice;USD;$product;1;5\nV3;$first;2026-10-23;invoice;USD;$secondProduct;1;5\n");
    $preview=transferTestUpload($s,$salePath,$cookie,$file,$csrf,$scope);$token=transferTestPreviewToken($preview[1]);
    $db->prepare('UPDATE inventory_products SET sale_enabled=0 WHERE id=?')->execute([$secondProduct]);
    $failed=authTestRequest($s,$salePath,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$token]);
    $q=$db->prepare('SELECT COUNT(*) FROM sales_quotations WHERE customer_id=?');$q->execute([$first]);
    transferTestCheck(!str_contains($failed[1],'registros guardados')&&(int)$q->fetchColumn()===1,'Una confirmación fallida dejó documentos parciales tras un commit anidado.');
    $account=(int)$db->query("SELECT id FROM accounting_payment_accounts WHERE system_key='default_cash' AND company_id=1")->fetchColumn();$method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual' AND company_id=1")->fetchColumn();
    $paymentPath=transferTestPath('customer_payments','import','modules/contabilidad/pagos.php?type=customer');
    transferTestImport($s,$paymentPath,$cookie,$file,"contact_id;amount;payment_date;currency_code;account_id;method_id;reference\n$first;12.30;2026-10-23;USD;$account;$method;$prefix pago\n",$csrf,$scope);
    $q=$db->prepare('SELECT status FROM accounting_payments WHERE reference=?');$q->execute([$prefix.' pago']);transferTestCheck($q->fetchColumn()==='draft','Importar un pago lo confirmó indebidamente.');
    $expensePath=transferTestPath('expense','import','modules/contabilidad/tesoreria.php?kind=expense');
    $concept=(int)$db->query("SELECT id FROM accounting_cash_concepts WHERE company_id=1 AND active=1 AND direction IN('outbound','both') AND (system_key IS NULL OR system_key<>'sales_commission') LIMIT 1")->fetchColumn();
    transferTestImport($s,$expensePath,$cookie,$file,"account_id;method_id;concept_id;amount;movement_date;currency_code;reference\n$account;$method;$concept;1.00;2026-10-23;USD;$prefix egreso\n",$csrf,$scope);
    $q=$db->prepare('SELECT id,status FROM accounting_treasury_documents WHERE reference=?');$q->execute([$prefix.' egreso']);$expense=$q->fetch();
    transferTestCheck($expense&&$expense['status']==='draft','La importación de tesorería no conserva el borrador.');
    $expenseSource='modules/contabilidad/tesoreria.php?id='.$expense['id'];$r=authTestRequest($s,'/'.$expenseSource,$cookie);
    transferTestCheck(str_contains($r[1],'data-model-options="expense"'),'La ficha sin kind no detecta su modelo real.');
    $r=authTestRequest($s,transferTestPath('expense','export',$expenseSource,['download'=>'1','fields'=>['reference']]),$cookie);
    transferTestCheck($r[0]===200&&str_contains($r[1],$prefix.' egreso'),'No se exporta la ficha de egreso por ID.');
    $limitedGroup=saveAccessGroup($db,['name'=>$prefix.' consulta','active'=>'1','permissions'=>['inventario.products.read','contactos.contacts.read'],'scopes'=>['inventario.products'=>'all','contactos.contacts'=>'all']],null,null,bin2hex(random_bytes(32)));
    $userLogin='csv.'.bin2hex(random_bytes(4));$usersSource='modules/ajustes/usuarios.php';$usersPath=transferTestPath('users','import',$usersSource);
    transferTestImport($s,$usersPath,$cookie,$file,"name;login;password;groups;company_ids\n$prefix usuario;$userLogin;$password;$limitedGroup;1\n",$csrf,$scope);
    $q=$db->prepare('SELECT id,must_change_password FROM auth_users WHERE login=?');$q->execute([$userLogin]);$accountUser=$q->fetch();transferTestCheck($accountUser&&(int)$accountUser['must_change_password']===1,'La importación de usuarios no conserva la contraseña temporal.');
    $private=authTestRequest($s,transferTestPath('users','export',$usersSource,['download'=>'1','fields'=>['password_hash']]),$cookie);transferTestCheck(!str_contains($private[1],'$2y$')&&str_contains($private[1],'no está autorizado'),'Se exportó un hash de contraseña.');
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$accountUser['id']]);
    $limitedCookie=tempnam(sys_get_temp_dir(),'transfer-limited-');$cookies[]=$limitedCookie;$r=authTestRequest($s,'/login.php',$limitedCookie);
    authTestRequest($s,'/login.php',$limitedCookie,['csrf'=>authTestToken($r[1]),'login'=>$userLogin,'password'=>$password]);
    transferTestCheck(authTestRequest($s,$productPath,$limitedCookie)[0]===403,'Un usuario de consulta pudo importar.');
    $private=authTestRequest($s,transferTestPath('products','export',$productSource,['download'=>'1','fields'=>['name','cost_price']]),$limitedCookie);transferTestCheck(str_contains($private[1],'no está autorizado'),'La exportación permitió un costo sin permiso.');
    echo "RUN company imports and context isolation\n";
    $companyPath=transferTestPath('companies','import','modules/ajustes/empresa.php');
    transferTestImport($s,$companyPath,$cookie,$file,"name;tax_id\n$prefix empresa;J-00000000-0\n",$csrf,$scope);
    $q=$db->prepare('SELECT id FROM business_company WHERE name=?');$q->execute([$prefix.' empresa']);$company=(int)$q->fetchColumn();transferTestCheck($company>1,'No se creó la empresa con sus catálogos.');
    $q=$db->prepare('SELECT COUNT(*) FROM accounting_currency_settings WHERE company_id=?');$q->execute([$company]);transferTestCheck((int)$q->fetchColumn()===1,'La empresa importada quedó sin configuración básica.');
    file_put_contents($file,"name;entity_kind;type_id\n$prefix pendiente;person;$type\n");$pending=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);$pendingToken=transferTestPreviewToken($pending[1]);
    transferTestCheck(authTestRequest($s,'/empresas.php',$cookie,['csrf'=>$csrf,'companies'=>[1,$company],'active_company'=>$company])[0]===303,'No cambió la empresa principal.');
    transferTestCheck(authTestRequest($s,$path,$cookie,['csrf'=>$csrf,'company_scope'=>$scope,'phase'=>'confirm','token'=>$pendingToken])[0]===409,'Se confirmó un lote de otra selección de empresas.');
    $r=authTestRequest($s,$path,$cookie);$csrf=authTestToken($r[1]);$scope=transferTestScope($r[1]);
    transferTestImport($s,$path,$cookie,$file,"name;entity_kind;type_id\n$prefix multi;person;$type\n",$csrf,$scope);
    $q=$db->prepare('SELECT company_id FROM contacts WHERE name=?');$q->execute([$prefix.' multi']);transferTestCheck((int)$q->fetchColumn()===$company,'El registro importado pertenece a otra empresa.');
    file_put_contents($file,"id;name\n$first;No debe cambiar\n");$bad=transferTestUpload($s,$path,$cookie,$file,$csrf,$scope);transferTestCheck(!str_contains($bad[1],'name="token"'),'Se permitió actualizar una ficha de otra empresa seleccionada.');
    echo "PASS: menú común en tree/form, plantilla por campos, CSV UTF-8, errores por fila, vista previa sin persistencia, carga atómica, reenvíos, exportación filtrada, fórmulas, concurrencia y documentos en borrador con varias líneas.\n";
}finally{foreach(array_merge($files,$cookies) as $file)if(is_file($file))unlink($file);stopAuthTestServer($s);}

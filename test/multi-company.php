<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/company-repository.php';
require_once dirname(__DIR__).'/php/company-connection.php';

function mcCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function mcReject(callable $action): void
{try{$action();}catch(InvalidArgumentException|PDOException|LogicException){return;}throw new RuntimeException('Se aceptó una operación entre empresas no válida.');}
function mcDb(array $s,array $ids,int $active,int $actor): CompanyConnection
{
    $c=require dirname(__DIR__).'/php/config/database.php';
    return new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$s['database'].';charset=utf8mb4',$c['username'],$c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],
        $ids,$active,$actor,companyTables());
}
echo "RUN multi-company migration and isolated HTTP server\n";
$s=startAuthTestServer();$cookies=[];
try {
    echo "RUN company fixtures\n";
    $db=$s['db'];$password=bin2hex(random_bytes(12));$login='multi.'.bin2hex(random_bytes(5));
    // The real installation may legitimately have deleted its initial company.
    // Recreate this test's fixed baseline only inside the disposable database.
    if(!$db->query('SELECT id FROM business_company WHERE id=1')->fetchColumn()) {
        $db->exec("INSERT INTO business_company(id,name) VALUES(1,'Empresa inicial de prueba')");
        companySeedDefaults($db,1);
    }
    $token=prepareFirstAdministrator($db);$actor=createFirstAdministrator($db,['name'=>'Multiempresa test','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $before=companyImpact($db,1);
    installMultiCompany($db);mcCheck($before===companyImpact($db,1),'Reinstalar alteró los datos.');
    echo "RUN company creation\n";
    $second=createBusinessCompany($db,['name'=>'Segunda empresa de prueba'],$actor);
    $a=mcDb($s,[1],1,$actor);$b=mcDb($s,[$second],$second,$actor);$all=mcDb($s,[1,$second],1,$actor);
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $a->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Cliente idéntico',$type]);$first=(int)$a->lastInsertId();
    $b->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Cliente idéntico',$type]);$other=(int)$b->lastInsertId();
    $q=$a->prepare('SELECT * FROM contacts WHERE id=?');$q->execute([$other]);mcCheck(!$q->fetch(),'Se filtró una ficha ajena.');
    $q=$all->prepare('SELECT COUNT(*) FROM contacts WHERE id IN (?,?)');$q->execute([$first,$other]);mcCheck((int)$q->fetchColumn()===2,'La consulta conjunta no combina empresas.');
    $q=$a->prepare('SELECT c.id,(SELECT COUNT(*) FROM contacts sub WHERE sub.id=?) AS hidden FROM contacts c WHERE c.id=?');$q->execute([$other,$first]);mcCheck((int)$q->fetch()['hidden']===0,'Se filtraron datos por una subconsulta.');
    $q=$b->prepare('SELECT * FROM contacts WHERE id=?');$q->execute([$other]);$row=$q->fetch();
    mcCheck((int)$row['company_id']===$second&&(int)$row['created_by']===$actor&&$row['created_at']!==null,'Faltan propiedad o autoría de creación.');
    $a->prepare('UPDATE contacts SET name=? WHERE id=?')->execute(['No debe cambiar',$other]);
    $q=$b->prepare('SELECT name FROM contacts WHERE id=?');$q->execute([$other]);mcCheck($q->fetchColumn()==='Cliente idéntico','UPDATE modificó otra empresa.');
    mcReject(fn()=>$b->prepare('UPDATE contacts SET salesperson_id=? WHERE id=?')->execute([$first,$other]));
    mcReject(fn()=>$b->prepare('UPDATE contacts SET company_id=1 WHERE id=?')->execute([$other]));
    mcReject(fn()=>$all->query('SELECT c.id FROM contacts c, inventory_products p'));
    mcReject(fn()=>$all->query('SELECT * FROM unregistered_business_table'));
    $all->prepare('DELETE FROM contacts WHERE id=?')->execute([$other]);
    $q=$b->prepare('SELECT COUNT(*) FROM contacts WHERE id=?');$q->execute([$other]);mcCheck((int)$q->fetchColumn()===1,'DELETE modificó otra empresa.');
    // A newer rate in another selected company must not change active-company conversions.
    require_once dirname(__DIR__).'/php/currency-repository.php';
    foreach([[$a,'2026-01-01','10.00000000'],[$b,'2026-10-01','99.00000000']] as [$rateDb,$date,$rate]) {
        $codes=$rateDb->query('SELECT code,id FROM accounting_currencies')->fetchAll(PDO::FETCH_KEY_PAIR);
        $rateDb->prepare('INSERT INTO accounting_currency_rates(currency_id,base_currency_id,rate_date,rate) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate)')->execute([$codes['VES'],$codes['USD'],$date,$rate]);
    }
    mcCheck(currencyRateForPair($all,'USD','VES','2026-01-01')==='10.00000000','La tasa de otra empresa afectó la conversión.');
    mcCheck(currencyRateForPair($all,'USD','VES','2026-10-01')!=='99.00000000','Una tasa más reciente de otra empresa afectó la conversión.');
    // Populate interconnected domain records to exercise deletion order and cycles.
    $b->exec("INSERT INTO inventory_products(name,sale_price) VALUES('Producto empresa 2',5)");$product=(int)$b->lastInsertId();
    $b->prepare("INSERT INTO sales_quotations(customer_id,invoice_address,delivery_address,order_date) VALUES(?,'','','2026-10-01')")->execute([$other]);$sale=(int)$b->lastInsertId();
    $b->prepare("INSERT INTO sales_quotation_lines(quotation_id,position,product_id,product_name,quantity,unit_price,base_amount,total_amount) VALUES(?,0,?,'Producto',1,5,5,5)")->execute([$sale,$product]);$saleLine=(int)$b->lastInsertId();
    $b->prepare("INSERT INTO accounting_invoices(invoice_type,sale_id,contact_id,invoice_address,invoice_date,base_amount,total_amount) VALUES('customer',?,?,'','2026-10-01',5,5)")->execute([$sale,$other]);$invoice=(int)$b->lastInsertId();
    $b->prepare("INSERT INTO accounting_invoice_lines(invoice_id,position,product_id,product_name,quantity,unit_price,base_amount,total_amount,sale_line_id) VALUES(?,0,?,'Producto',1,5,5,5,?)")->execute([$invoice,$product,$saleLine]);
    $b->prepare("INSERT INTO inventory_operations(operation_type,scheduled_date,contact_id,quotation_id,notes) VALUES('delivery','2026-10-01',?,?,'')")->execute([$other,$sale]);$operation=(int)$b->lastInsertId();
    $b->prepare('INSERT INTO inventory_operation_lines(operation_id,position,product_id,demand,quantity,sale_line_id) VALUES(?,0,?,1,1,?)')->execute([$operation,$product,$saleLine]);
    $b->exec("INSERT INTO inventory_categories(name) VALUES('Padre A'),('Padre B')");
    $parents=$b->query('SELECT name,id FROM inventory_categories WHERE parent_id IS NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b->prepare("INSERT INTO inventory_categories(name,parent_id) VALUES('Repetida',?),('Repetida',?)")->execute([$parents['Padre A'],$parents['Padre B']]);
    $b->exec("INSERT INTO inventory_warehouses(name) VALUES('Almacén A'),('Almacén B')");
    $warehouses=$b->query('SELECT name,id FROM inventory_warehouses')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b->prepare("INSERT INTO inventory_locations(name,warehouse_id) VALUES('Existencias',?),('Existencias',?)")->execute([$warehouses['Almacén A'],$warehouses['Almacén B']]);
    // Locking reads must still lock the physical row after company scoping.
    $b->beginTransaction();$q=$b->prepare('SELECT * FROM contacts WHERE id=? FOR UPDATE');$q->execute([$other]);$q->fetchAll();
    $competitor=mcDb($s,[$second],$second,$actor);$rawCompetitor=new PDO('mysql:host=127.0.0.1;dbname='.$s['database'],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $rawCompetitor->exec('SET innodb_lock_wait_timeout=1, @nexo_company_id='.$second);
    mcReject(fn()=>$rawCompetitor->prepare('UPDATE contacts SET name=name WHERE id=?')->execute([$other]));$b->rollBack();
    $cookie=tempnam(sys_get_temp_dir(),'multi-company-');$cookies[]=$cookie;
    $r=authTestRequest($s,'/login.php',$cookie);
    mcCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password])[0]===303,'Falló login.');
    $r=authTestRequest($s,'/modules/ajustes/empresa.php',$cookie);mcCheck($r[0]===200&&str_contains($r[1],'Nueva empresa'),'No abrió la administración de empresas.');
    $csrf=authTestToken($r[1]);preg_match('/name="company_scope" value="([a-f0-9]+)"/',$r[1],$scope);
    mcCheck(authTestRequest($s,'/empresas.php',$cookie,['csrf'=>$csrf,'companies'=>[1,$second],'active_company'=>$second])[0]===303,'No cambió la selección.');
    $r=authTestRequest($s,'/modules/contactos/index.php',$cookie);mcCheck($r[0]===200,'No abrió la consulta conjunta.');
    mcCheck(str_contains($r[1],'Segunda empresa de prueba')&&str_contains($r[1],'>Empresa</th>'),'El listado no identifica las empresas.');
    $r=authTestRequest($s,'/modules/contactos/index.php?id='.$other,$cookie);mcCheck($r[0]===200&&str_contains($r[1],'Control del registro'),'La ficha no muestra auditoría.');
    foreach(['/modules/ventas/index.php','/modules/compras/index.php','/modules/inventario/index.php','/modules/contabilidad/index.php','/modules/dashboard/resumen.php'] as $path)mcCheck(authTestRequest($s,$path,$cookie)[0]===200,'Falló la consulta multiempresa de '.$path);
    mcCheck(authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,['csrf'=>$csrf,'company_scope'=>$scope[1],'action'=>'save_contact'])[0]===409,'Se aceptó un formulario de la selección anterior.');
    mcCheck(authTestRequest($s,'/empresas.php',$cookie,['csrf'=>$csrf,'companies'=>[99999999],'active_company'=>99999999])[0]===403,'Se aceptó una empresa no autorizada.');
    $readonly=(int)$db->query("SELECT id FROM access_groups WHERE system_key='readonly'")->fetchColumn();
    $restrictedLogin='restricted.'.bin2hex(random_bytes(4));
    $restricted=saveAuthUser($db,['name'=>'Usuario limitado','login'=>$restrictedLogin,'active'=>'1','groups'=>[(string)$readonly],
        'password'=>$password,'password_confirmation'=>$password],null,null,$actor,bin2hex(random_bytes(32)));
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$restricted]);
    $restrictedCookie=tempnam(sys_get_temp_dir(),'multi-restricted-');$cookies[]=$restrictedCookie;
    $r=authTestRequest($s,'/login.php',$restrictedCookie);authTestRequest($s,'/login.php',$restrictedCookie,['csrf'=>authTestToken($r[1]),'login'=>$restrictedLogin,'password'=>$password]);
    $r=authTestRequest($s,'/modules/contactos/index.php',$restrictedCookie);mcCheck($r[0]===200,'El usuario no puede consultar su empresa inicial.');
    $restrictedCsrf=authTestToken($r[1]);
    mcCheck(authTestRequest($s,'/modules/contactos/index.php?id='.$other,$restrictedCookie)[0]===404,'Una ficha revela datos de una empresa sin autorización.');
    mcCheck(authTestRequest($s,'/empresas.php',$restrictedCookie,['csrf'=>$restrictedCsrf,'companies'=>[$second],'active_company'=>$second])[0]===403,'El usuario pudo ampliar sus empresas autorizadas.');
    mcReject(fn()=>saveCompanyUsers($db,$second,[$restricted],1,$restricted));
    // Companies are managed from the same user form as module permissions.
    $r=authTestRequest($s,'/modules/ajustes/usuarios.php?id='.$restricted,$cookie);
    mcCheck($r[0]===200&&str_contains($r[1],'Empresas autorizadas')&&str_contains($r[1],'name="company_ids[]"'),'La ficha del usuario no permite asignar empresas.');
    $userInput=['csrf'=>authTestToken($r[1]),'action'=>'save_user','name'=>'Usuario limitado','login'=>$restrictedLogin,'email'=>'','active'=>'1',
        'contact_id'=>'','groups'=>[(string)$readonly],'companies_present'=>'1','company_ids'=>['1',(string)$second]];
    $userInput['version']=(string)loadAuthUser($db,$restricted)['version'];
    mcCheck(authTestRequest($s,'/modules/ajustes/usuarios.php?id='.$restricted,$cookie,$userInput)[0]===303,'No se guardaron las empresas desde Usuarios.');
    $q=$db->prepare('SELECT COUNT(*) FROM company_user_access WHERE user_id=? AND company_id=?');$q->execute([$restricted,$second]);mcCheck((int)$q->fetchColumn()===1,'La asignación no quedó sincronizada.');
    $salespersonType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='salesperson'")->fetchColumn();
    $b->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Vendedor de prueba',$salespersonType]);$seller=(int)$b->lastInsertId();
    $db->prepare('UPDATE company_user_access SET contact_id=? WHERE user_id=? AND company_id=?')->execute([$seller,$restricted,$second]);
    $userInput['version']=(string)loadAuthUser($db,$restricted)['version'];
    mcCheck(authTestRequest($s,'/modules/ajustes/usuarios.php?id='.$restricted,$cookie,$userInput)[0]===303,'No se pudo volver a guardar el usuario.');
    $q=$db->prepare('SELECT contact_id FROM company_user_access WHERE user_id=? AND company_id=?');$q->execute([$restricted,$second]);mcCheck((int)$q->fetchColumn()===$seller,'Editar el usuario borró su vendedor por empresa.');
    $r=authTestRequest($s,'/login.php',$restrictedCookie);authTestRequest($s,'/login.php',$restrictedCookie,['csrf'=>authTestToken($r[1]),'login'=>$restrictedLogin,'password'=>$password]);
    $r=authTestRequest($s,'/modules/contactos/index.php',$restrictedCookie);
    mcCheck(authTestRequest($s,'/empresas.php',$restrictedCookie,['csrf'=>authTestToken($r[1]),'companies'=>[$second],'active_company'=>$second])[0]===303,'El acceso asignado desde Usuarios no habilitó el selector.');
    mcCheck(authTestRequest($s,'/modules/contactos/index.php?id='.$other,$restrictedCookie)[0]===200,'La empresa autorizada no permite consultar sus registros.');
    $invalidInput=$userInput;$invalidInput['company_ids']=['99999999'];$invalidInput['version']=(string)loadAuthUser($db,$restricted)['version'];
    mcCheck(authTestRequest($s,'/modules/ajustes/usuarios.php?id='.$restricted,$cookie,$invalidInput)[0]===200,'La empresa inválida no produjo validación de formulario.');
    $userInput['company_ids']=['1'];$userInput['version']=(string)loadAuthUser($db,$restricted)['version'];
    mcCheck(authTestRequest($s,'/modules/ajustes/usuarios.php?id='.$restricted,$cookie,$userInput)[0]===303,'No se revocó la empresa desde Usuarios.');
    mcCheck(authTestRequest($s,'/modules/contactos/index.php?id='.$other,$restrictedCookie)[0]===303,'Revocar la empresa no invalidó la sesión previa.');
    // Exercise a complete company deletion, including its defaults and contacts.
    $protected=companyImpact($db,1);$impact=companyImpact($db,$second);
    $companyVersion=(int)$db->query('SELECT version FROM business_company WHERE id='.$second)->fetchColumn();
    mcReject(fn()=>deleteBusinessCompany($db,$second,$companyVersion,'incorrecto',companyImpactHash($impact),$actor));
    deleteBusinessCompany($db,$second,$companyVersion,'Segunda empresa de prueba',companyImpactHash($impact),$actor);
    mcCheck(array_sum(companyImpact($db,$second))===0,'Quedaron registros huérfanos de la empresa eliminada.');
    mcCheck(companyImpact($db,1)===$protected,'Eliminar una empresa alteró otra.');
    mcCheck((int)$db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()===2,'Se eliminaron usuarios compartidos.');
    echo "PASS: migración repetible, aislamiento, consulta conjunta, auditoría, bloqueo concurrente, relaciones cruzadas, selector HTTP, formularios obsoletos y eliminación sin afectar otra empresa.\n";
}finally {foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

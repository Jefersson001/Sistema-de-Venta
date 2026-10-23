<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';require_once dirname(__DIR__).'/php/outstanding-repository.php';require_once dirname(__DIR__).'/php/company-repository.php';require_once dirname(__DIR__).'/php/company-connection.php';
function balanceCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function balanceDb(array $server,array $companies,int $actor):CompanyConnection{
    $c=require dirname(__DIR__).'/php/config/database.php';return new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$server['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],$companies,1,$actor,companyTables());
}
$s=startAuthTestServer();$cookies=[];
try{
    $db=$s['db'];$prefix='Bal'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(12));$login=strtolower($prefix);$today=commerceToday();$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $setup=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>$prefix,'login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));$second=createBusinessCompany($db,['name'=>$prefix.' empresa 2'],$admin);
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);$people=[];
    foreach(['client','supplier','salesperson','salesperson2'] as $key){$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.' '.$key,$types[str_starts_with($key,'salesperson')?'salesperson':$key]]);$people[$key]=(int)$db->lastInsertId();}
    $insert=static function(string $type,string $suffix,string $amount,string $code='USD',?string $due=null,string $status='posted',?int $original=null,?int $seller=null)use($db,$prefix,$people,$today):int{
        $db->prepare('INSERT INTO accounting_invoices(name,invoice_type,contact_id,invoice_address,invoice_date,due_date,base_amount,total_amount,status,currency_code,document_type,reversal_of_id,salesperson_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$prefix.$suffix,$type,$people[$type==='customer'?'client':'supplier'],'',$today,$due,$amount,$amount,$status,$code,$original?'credit':'invoice',$original,$seller]);return (int)$db->lastInsertId();
    };
    $a=$insert('customer','A','100.00','USD',$yesterday,'posted',null,$people['salesperson']);$insert('customer','CREDIT','20.00','USD',$today,'posted',$a);
    $insert('customer','B','35.00','USD',$tomorrow,'posted',null,$people['salesperson2']);$insert('customer','VES','1000.00','VES');
    $supplier=$insert('supplier','SUP','80.00','USD',$tomorrow);$paid=$insert('customer','PAID','11.00');
    $insert('customer','DRAFT','999.00','USD',null,'draft');$insert('customer','CANCEL','888.00','USD',null,'cancel');
    $reverse=$insert('customer','REVERSED','777.00');$db->prepare("UPDATE accounting_invoices SET payment_state='reversed' WHERE id=?")->execute([$reverse]);
    foreach([[$a,'30.00','settled'],[$a,'10.00','pending'],[$a,'999.00','cancel'],[$supplier,'10.00','settled'],[$paid,'11.00','settled']] as [$id,$amount,$state])$db->prepare('INSERT INTO accounting_invoice_payments(invoice_id,amount,payment_date,reference,status) VALUES(?,?,?,?,?)')->execute([$id,$amount,$today,$prefix,$state]);
    $one=balanceDb($s,[1],$admin);$filters=outstandingFilters(['q'=>$prefix]);$report=loadOutstandingReport($one,$filters);$customer=array_column($report['customer']['currencies'],null,'code');
    balanceCheck($customer['USD']['balance']==='85.00'&&$customer['USD']['overdue']==='50.00'&&$customer['USD']['pending']==='10.00'&&$customer['VES']['balance']==='1000.00','El saldo no descuenta pagos/notas, mezcla monedas o incluye borradores/reversiones.');
    balanceCheck($report['supplier']['currencies'][0]['balance']==='70.00','Saldo por pagar incorrecto.');
    $overdue=loadOutstandingReport($one,outstandingFilters(['q'=>$prefix,'due'=>'overdue']));balanceCheck($overdue['customer']['count']===1&&$overdue['supplier']['count']===0,'El filtro de vencidas incluye documentos no vencidos.');
    $db->exec('SET @nexo_company_id='.$second);$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.' empresa 2',$types['client']]);$otherContact=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_invoices(name,invoice_type,contact_id,invoice_address,invoice_date,base_amount,total_amount,status,currency_code) VALUES(?,'customer',?,'',?,200,200,'posted','USD')")->execute([$prefix.'OTHER',$otherContact,$today]);
    $all=loadOutstandingReport(balanceDb($s,[1,$second],$admin),$filters);$allAmounts=array_column($all['customer']['currencies'],null,'code');balanceCheck($allAmounts['USD']['balance']==='285.00'&&count($all['customer']['groups'])===3,'No se agregan las empresas seleccionadas conservando moneda y contacto.');
    balanceCheck(array_column(loadOutstandingReport($one,$filters)['customer']['currencies'],null,'code')['USD']['balance']==='85.00','Una empresa aislada ve deuda de otra.');
    $cookie=tempnam(sys_get_temp_dir(),'balance-admin-');$cookies[]=$cookie;[, $html]=authTestRequest($s,'/login.php',$cookie);balanceCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'Falló login.');
    [, $home]=authTestRequest($s,'/index.php',$cookie);balanceCheck(authTestRequest($s,'/empresas.php',$cookie,['csrf'=>authTestToken($home),'companies'=>[1],'active_company'=>1])[0]===303,'No se seleccionó la empresa de prueba.');
    $path='/modules/contabilidad/cuentas-pendientes.php';[$status,$html]=authTestRequest($s,$path.'?q='.$prefix,$cookie);
    balanceCheck(str_contains($html,'data-live-filters')&&str_contains($html,'js/live-report.js')&&!str_contains($html,'>Consultar</button>'),'El informe requiere un botón para aplicar la búsqueda.');
    balanceCheck($status===200&&strpos($html,'id="accounts-customer"')<strpos($html,'id="accounts-supplier"')&&str_contains($html,'data-outstanding-invoice="'.$a.'"')&&!str_contains($html,$prefix.'OTHER')&&str_contains($html,'data-model-options="outstanding"'),'La vista no conserva orden, detalle, empresas o menú común. HTTP '.$status.' '.mb_substr(strip_tags($html),-1600));
    [$status,$csv]=authTestRequest($s,'/datos.php?'.http_build_query(['model'=>'outstanding','action'=>'export','source'=>'/project'.$path.'?q='.$prefix,'download'=>1,'fields'=>['name','balance','pending','currency_code']]),$cookie);
    balanceCheck($status===200&&str_starts_with($csv,"\xEF\xBB\xBF")&&str_contains($csv,$prefix.'A;50.00;10.00;USD')&&!str_contains($csv,$prefix.'PAID'),'La exportación no usa los saldos reales o los filtros.');
    [, $snapshotBody]=authTestRequest($s,'/modules/dashboard/resumen.php',$cookie);$snapshot=json_decode($snapshotBody,true);balanceCheck($snapshot['dashboard']['balances']['customer']!==null&&$snapshot['dashboard']['balances']['supplier']!==null,'El dashboard no expone ambos resúmenes.');
    balanceCheck(!isset($snapshot['dashboard']['balances']['customer']['groups']),'El dashboard recibe detalles en lugar de solo cantidades.');
    [, $dashboardHtml]=authTestRequest($s,'/modules/dashboard/index.php',$cookie);
    balanceCheck(str_contains($dashboardHtml,'data-outstanding-totals="customer"')&&!str_contains($dashboardHtml,'data-outstanding-partner=')&&!str_contains($dashboardHtml,'data-outstanding-invoice='),'El dashboard muestra el detalle de las cuentas.');
    $db->exec('SET @nexo_company_id=1');$group=saveAccessGroup($db,['name'=>$prefix.' own','active'=>'1','permissions'=>['contabilidad.customer_invoices.read','dashboard.business.read'],'scopes'=>['contabilidad.customer_invoices'=>'own','dashboard.business'=>'own']],null,null,bin2hex(random_bytes(32)));
    $userLogin=$login.'.own';$user=saveAuthUser($db,['name'=>$prefix.' vendedor','login'=>$userLogin,'active'=>'1','groups'=>[$group],'company_ids'=>[1],'contact_id'=>$people['salesperson'],'password'=>$password,'password_confirmation'=>$password],null,null,$admin,bin2hex(random_bytes(32)));$db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$user]);
    $limited=tempnam(sys_get_temp_dir(),'balance-own-');$cookies[]=$limited;[, $html]=authTestRequest($s,'/login.php',$limited);authTestRequest($s,'/login.php',$limited,['csrf'=>authTestToken($html),'login'=>$userLogin,'password'=>$password]);
    [$status,$html]=authTestRequest($s,$path.'?q='.$prefix,$limited);balanceCheck($status===200&&str_contains($html,'data-outstanding-invoice="'.$a.'"')&&!str_contains($html,'data-outstanding-invoice="'.$supplier.'"')&&!str_contains($html,'id="accounts-supplier"'),'Los permisos propios o de proveedores no se respetan.');
    [, $body]=authTestRequest($s,'/modules/dashboard/resumen.php',$limited);$own=json_decode($body,true)['dashboard']['balances'];balanceCheck($own['customer']['currencies'][0]['balance']==='50.00'&&$own['supplier']===null,'El dashboard expone saldos ajenos.');
    [$status]=authTestRequest($s,$path.'?due=invalid',$cookie);balanceCheck($status===422,'Se aceptaron filtros inválidos.');
    echo "PASS: cuentas por cobrar arriba/pagar abajo, pagos parciales y en proceso, notas de crédito, vencidos, monedas, empresas, permisos propios, dashboard y exportación.\n";
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

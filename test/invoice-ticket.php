<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/company-repository.php';
function ticketCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'ticket-qa-');$guest=tempnam(sys_get_temp_dir(),'ticket-guest-');
try{
    $db=$s['db'];$login='ticket.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(12));$token=prepareFirstAdministrator($db);
    $actor=createFirstAdministrator($db,['name'=>'Usuario ticket QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));$db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$actor);
    $insert=static function(string $table,array $data)use($db):int{$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return (int)$db->lastInsertId();};
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $product=$insert('inventory_products',['name'=>'Producto ticket','product_type'=>'service']);
    $invoices=[];
    foreach(['customer'=>'client','supplier'=>'supplier'] as $type=>$kind){
        $contact=$insert('contacts',['name'=>'Contacto <seguro> '.$type,'type_id'=>$types[$kind],'phone'=>'04121234567']);
        $id=$insert('accounting_invoices',['name'=>'TICKET-'.$type,'invoice_type'=>$type,'contact_id'=>$contact,'invoice_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d'),'status'=>'posted','payment_state'=>'not_paid','currency_code'=>'USD','base_amount'=>'20.00','tax_amount'=>'3.20','total_amount'=>'23.20']);$invoices[$type]=$id;
        $insert('accounting_invoice_lines',['invoice_id'=>$id,'product_id'=>$product,'product_name'=>'Producto con descripción larga <segura> para comprobar el ajuste en papel estrecho','quantity'=>'2','unit_price'=>'10','tax_name'=>'IVA','tax_rate'=>'16','tax_amount'=>'3.20','base_amount'=>'20.00','total_amount'=>'23.20','position'=>0]);
    }
    $account=$insert('accounting_payment_accounts',['name'=>'Caja ticket QA','account_type'=>'cash','currency_code'=>'VES']);$method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual' LIMIT 1")->fetchColumn();
    foreach(['settled'=>'10.00','pending'=>'5.00'] as $state=>$amount){$payment=$insert('accounting_payments',['name'=>'PAGO-TICKET-'.$state,'direction'=>'inbound','contact_id'=>$db->query('SELECT contact_id FROM accounting_invoices WHERE id='.$invoices['customer'])->fetchColumn(),'account_id'=>$account,'method_id'=>$method,'payment_date'=>date('Y-m-d'),'currency_code'=>'VES','amount'=>bcmul($amount,'100',2),'status'=>$state]);$insert('accounting_invoice_payments',['payment_id'=>$payment,'invoice_id'=>$invoices['customer'],'amount'=>$amount,'payment_amount'=>bcmul($amount,'100',2),'payment_date'=>date('Y-m-d'),'status'=>$state]);}
    $db->exec("UPDATE accounting_invoices SET payment_state='partial' WHERE id=".$invoices['customer']);
    [, $loginForm]=authTestRequest($s,'/login.php',$cookie);ticketCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($loginForm),'login'=>$login,'password'=>$password])[0]===303,'Login failed.');
    $path=static fn(string $type,int $id,string $width='80',string $format='ticket'):string=>'/acciones.php?'.http_build_query(['source'=>'/project/modules/contabilidad/index.php?type='.$type.'&id='.$id,'action'=>'print','format'=>$format,'width'=>$width]);
    foreach($invoices as $type=>$id){
        foreach(['80','58'] as $width){[$code,$html]=authTestRequest($s,$path($type,$id,$width),$cookie);ticketCheck($code===200&&str_contains($html,'data-print-ticket')&&str_contains($html,'data-ticket-width="'.$width.'"'),'Ticket missing '.$type.' '.$width);ticketCheck(!str_contains($html,'Contacto <seguro>')&&str_contains($html,'&lt;seguro&gt;'),'Unescaped contact.');ticketCheck(str_contains($html,$type==='customer'?'TICKET DE VENTA':'TICKET DE COMPRA'),'Wrong ticket title.');ticketCheck(str_contains($html,'IVA (16.00 %)')&&str_contains($html,'23,20'),'Tax or total incorrect.');}
        [, $a4]=authTestRequest($s,$path($type,$id,'80','a4'),$cookie);ticketCheck(str_contains($a4,'document-paper')&&str_contains($a4,'Ticket 80 / 58 mm'),'A4 changed or ticket link missing.');
        [, $form]=authTestRequest($s,'/modules/contabilidad/index.php?type='.$type.'&id='.$id,$cookie);ticketCheck(str_contains($form,'Imprimir ticket'),'Invoice action missing.');
    }
    [, $html]=authTestRequest($s,$path('customer',$invoices['customer']),$cookie);ticketCheck(str_contains($html,'Pago confirmado')&&str_contains($html,'Pago en revisión')&&str_contains($html,'13,20 USD'),'Pending payment reduced balance or allocated amounts incorrect.');
    ticketCheck(authTestRequest($s,$path('customer',$invoices['customer'],'900'),$cookie)[0]===400,'Invalid width accepted.');
    ticketCheck(authTestRequest($s,$path('customer',$invoices['customer']),$guest)[0]===303,'Guest printed invoice.');
    ticketCheck(authTestRequest($s,$path('customer',99999999),$cookie)[0]===404,'Missing invoice printed.');
    $other=createBusinessCompany($db,['name'=>'Empresa ajena ticket'],$actor);$db->exec('SET @nexo_company_id='.$other);$foreignContact=$insert('contacts',['name'=>'Contacto ajeno','type_id'=>$types['client']]);$foreign=$insert('accounting_invoices',['name'=>'FACTURA-AJENA','invoice_type'=>'customer','contact_id'=>$foreignContact,'invoice_date'=>date('Y-m-d')]);$db->exec('SET @nexo_company_id=1');
    ticketCheck(authTestRequest($s,$path('customer',$foreign),$cookie)[0]===404,'Foreign company ticket exposed.');
    if(in_array('--browser',$argv,true)){
        $env=getenv();foreach(['NEXO_TEST_BASE_URL'=>$s['url'],'NEXO_TEST_LOGIN'=>$login,'NEXO_TEST_PASSWORD'=>$password,'NEXO_TICKET_INVOICE'=>$invoices['customer']] as $key=>$value)$env[$key]=(string)$value;
        $p=proc_open(['node',__DIR__.'/invoice-ticket-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);ticketCheck(proc_close($p)===0,'Ticket browser check failed.');
    }
    echo "PASS: tickets de compra/venta 58 y 80 mm, A4 conservado, importes aplicados en moneda de factura, pagos confirmados/en revisión, saldo, texto escapado, sesión y separación de empresas.\n";
}finally{foreach([$cookie,$guest] as $file)if(is_file($file))unlink($file);stopAuthTestServer($s);}

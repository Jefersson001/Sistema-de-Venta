<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/commission-repository.php';
function cgCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function cgReject(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid general commission payment accepted.');}
$server=null;$cookie=tempnam(sys_get_temp_dir(),'cg-qa-');
try{
    $server=startAuthTestServer();$db=$server['db'];$password=bin2hex(random_bytes(14));$login='cg.'.bin2hex(random_bytes(5));
    $token=prepareFirstAdministrator($db);$actor=createFirstAdministrator($db,['name'=>'Comisiones generales QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));$db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$actor);
    $migration=require dirname(__DIR__).'/php/migrations/versioned/20261125234805_pagos_generales_comisiones.php';$migration($db);$migration($db);
    $insert=static function(string $table,array $data)use($db):int{$db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$db->lastInsertId();};
    $types=$db->query('SELECT system_key,id FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);$today=commerceToday();$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $seller=$insert('contacts',['name'=>'Vendedor general QA','type_id'=>$types['salesperson']]);$other=$insert('contacts',['name'=>'Otro vendedor QA','type_id'=>$types['salesperson']]);$client=$insert('contacts',['name'=>'Cliente general QA','type_id'=>$types['client']]);
    $product=$insert('inventory_products',['name'=>'Articulo comision QA','product_type'=>'service']);$account=$insert('accounting_payment_accounts',['name'=>'Caja comisiones QA','account_type'=>'cash']);
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE company_id=1 AND system_key='manual'")->fetchColumn();
    $invoice=static function(string $commission,string $date,string $currency='USD',?int $sellerId=null,string $status='posted')use($insert,$seller,$client,$product):int{
        $id=$insert('accounting_invoices',['name'=>'CG-'.bin2hex(random_bytes(5)),'invoice_type'=>'customer','contact_id'=>$client,'salesperson_id'=>$sellerId??$seller,'invoice_address'=>'QA','invoice_date'=>$date,'base_amount'=>'100','total_amount'=>'100','status'=>$status,'currency_code'=>$currency,'currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100']);
        $insert('accounting_invoice_lines',['invoice_id'=>$id,'position'=>0,'product_id'=>$product,'product_name'=>'QA','quantity'=>'1','unit_price'=>'100','base_amount'=>'100','total_amount'=>'100','commission_reference_price'=>'0','commission_unit_amount'=>$commission]);return $id;
    };
    $funds=treasuryInsertDocument($db,['kind'=>'opening','status'=>'done','account_id'=>$account,'currency_code'=>'USD','amount'=>'1000.00','primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>'100','movement_date'=>$yesterday,'reference'=>'QA']);
    treasuryEntry($db,['account_id'=>$account,'currency_code'=>'USD','movement_date'=>$yesterday,'amount'=>'1000.00','category'=>'opening','document_id'=>$funds]);
    $vesFunds=treasuryInsertDocument($db,['kind'=>'opening','status'=>'done','account_id'=>$account,'currency_code'=>'VES','amount'=>'100000.00','primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>'100','movement_date'=>$yesterday,'reference'=>'QA']);
    treasuryEntry($db,['account_id'=>$account,'currency_code'=>'VES','movement_date'=>$yesterday,'amount'=>'100000.00','category'=>'opening','document_id'=>$vesFunds]);
    $a=$invoice('20.00',$yesterday);$b=$invoice('10.00',$yesterday);$invoice('999.00',$today,'USD',null,'draft');$otherInvoice=$invoice('99.00',$today,'USD',$other);
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='30.00','Uncollected commissions not payable or draft/foreign seller included.');
    $input=['amount'=>'25.00','movement_date'=>$today,'currency_code'=>'USD','exchange_rate'=>'100.00','account_id'=>$account,'method_id'=>$method,'reference'=>'Pago general QA'];$key=bin2hex(random_bytes(32));
    $document=paySellerCommission($db,$seller,'USD',$input,$key);$allocations=commissionPaymentAllocations($db,$document);
    cgCheck(count($allocations)===2&&$allocations[0]['amount']==='20.00'&&$allocations[1]['amount']==='5.00','General payment was not distributed oldest first.');
    cgCheck($db->query('SELECT SUM(amount) FROM accounting_treasury_entries WHERE document_id='.$document)->fetchColumn()==='-25.00','General payment duplicated the real cash expense.');
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='5.00','General payment did not reduce accumulated balance.');
    cgCheck(paySellerCommission($db,$seller,'USD',$input,$key)===$document,'Retry created another cash expense.');
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'26.00']),$key));
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['currency_code'=>'VES']),$key));
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['exchange_rate'=>'110.00']),$key));
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['reference'=>'Changed']),$key));
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'5.01']),bin2hex(random_bytes(32))));
    $history=salesCommissionPaymentHistory($db,null,$seller);cgCheck($history['total']===1&&$history['rows'][0]['amount']==='25.00'&&$history['rows'][0]['payment_amount']==='25.00','History duplicated a batch or showed its first allocation only.');
    $c=$invoice('25.00',$today);cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='30.00','New day commissions did not accumulate with old debt.');
    $second=paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'15.00']),bin2hex(random_bytes(32)));
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='15.00','Partial general payment incorrect.');
    $db->prepare("INSERT INTO accounting_invoice_payments(invoice_id,amount,payment_date,reference,status) VALUES(?,100,?,'QA','settled')")->execute([$a,$today]);
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='15.00','Customer collection made an already-paid commission payable again.');
    $q=$db->query('SELECT * FROM accounting_treasury_documents WHERE id='.$second);$d=$q->fetch();reverseTreasuryDocument($db,$second,(int)$d['version'],['reason'=>'QA reversal','movement_date'=>$today]);
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='30.00','Reversal did not release cumulative commission balance.');
    // A return creates an offset against later commissions, without inventing a cash movement.
    $credit=$insert('accounting_invoices',['name'=>'CG-CREDIT','invoice_type'=>'customer','document_type'=>'credit','reversal_of_id'=>$a,'contact_id'=>$client,'invoice_address'=>'QA','invoice_date'=>$today,'base_amount'=>'100','total_amount'=>'100','status'=>'posted']);
    cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='10.00','Returned commission paid in advance was not offset against new commissions.');
    $third=paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'10.00']),bin2hex(random_bytes(32)));cgCheck(sellerCommissionBalances($db,$seller)['USD']['pending']==='0.00','Offset plus later payment exceeded net generated total.');
    cgReject(fn()=>paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'0.01']),bin2hex(random_bytes(32))));
    $new=$invoice('12.00',$today);$cross=paySellerCommission($db,$seller,'USD',array_replace($input,['amount'=>'2.00','currency_code'=>'VES','exchange_rate'=>'110.00']),bin2hex(random_bytes(32)));
    cgCheck($db->query('SELECT amount FROM accounting_treasury_documents WHERE id='.$cross)->fetchColumn()==='220.00'&&sellerCommissionBalances($db,$seller)['USD']['pending']==='10.00','Cross-currency commission changed debt units or actual cash.');
    $snapshot=$db->query('SELECT * FROM sales_commission_payments ORDER BY id')->fetchAll();$migration($db);cgCheck($snapshot===$db->query('SELECT * FROM sales_commission_payments ORDER BY id')->fetchAll(),'Migration retry changed payment history.');

    [, $form]=authTestRequest($server,'/login.php',$cookie);cgCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($form),'login'=>$login,'password'=>$password])[0]===303,'QA login failed.');
    $path='/modules/ventas/pagos-comisiones.php?new=1&seller_id='.$seller.'&commission_currency=USD';
    [$status,$html]=authTestRequest($server,$path.'&period=custom&date_from=2000-01-01&date_to=2000-01-02',$cookie);
    cgCheck($status===200&&str_contains($html,'Disponible para pagar')&&str_contains($html,'10.00 USD')&&!str_contains($html,'name="invoice_id"'),'General form depends on date period or requires invoice.');
    $post=['csrf'=>authTestToken($html),'action'=>'pay_commission','creation_key'=>authTestToken($html,'creation_key')]+array_replace($input,['amount'=>'3.00']);
    $result=authTestRequest($server,$path,$cookie,$post);cgCheck($result[0]===303,'General HTTP payment failed: '.mb_substr(strip_tags($result[1]),-800));
    $url=str_replace($server['url'],'',$result[2]);[$status,$html]=authTestRequest($server,$url,$cookie);cgCheck($status===200&&str_contains($html,'Distribución del pago general'),'General payment detail did not render.');
    cgCheck(authTestRequest($server,$path,$cookie,$post)[0]===303&&sellerCommissionBalances($db,$seller)['USD']['pending']==='7.00','HTTP retry duplicated payment.');
    cgCheck(authTestRequest($server,$path,$cookie,array_replace($post,['csrf'=>'invalid']))[0]===403,'General payment accepted invalid CSRF.');
    $legacy=paySalesCommission($db,$otherInvoice,array_replace($input,['amount'=>'1.00']),bin2hex(random_bytes(32)));
    cgCheck(commissionPaymentAllocations($db,$legacy)[0]['payment_scope']==='invoice','Invoice-specific payment stopped working.');
    $db->prepare("INSERT INTO accounting_invoices(name,invoice_type,document_type,reversal_of_id,contact_id,invoice_address,invoice_date,base_amount,total_amount,status) VALUES('CG-OTHER-CREDIT','customer','credit',?,?,'QA',?,100,100,'posted')")->execute([$otherInvoice,$client,$today]);
    cgCheck(sellerCommissionBalances($db,$other)['USD']['recoverable']==='1.00','Excess after return is not carried forward.');
    $invoice('3.00',$today,'USD',$other);
    cgCheck(sellerCommissionBalances($db,$other)['USD']['pending']==='2.00','New generation did not offset an old excess.');
    $groups=summarizeSalesCommissions(loadSalesCommissions($db,null,$other));cgCheck($groups[0]['commission_overpaid']==='0.00','Summary still reports a compensated excess as recoverable.');
    // Accounts with only consultation permissions must never create a commission expense.
    $group=saveAccessGroup($db,['name'=>'CG read only','active'=>'1','permissions'=>['ventas.commissions.read','ventas.commission_payments.read'],'scopes'=>['ventas.commissions'=>'all','ventas.commission_payments'=>'all']],null,null,bin2hex(random_bytes(32)));
    $limitedLogin=$login.'.read';$user=saveAuthUser($db,['name'=>'CG reader','login'=>$limitedLogin,'active'=>'1','groups'=>[$group],'company_ids'=>[1],'password'=>$password,'password_confirmation'=>$password],null,null,$actor,bin2hex(random_bytes(32)));
    $db->prepare('UPDATE auth_users SET must_change_password=0 WHERE id=?')->execute([$user]);
    $limited=tempnam(sys_get_temp_dir(),'cg-reader-');
    try{
        [, $form]=authTestRequest($server,'/login.php',$limited);authTestRequest($server,'/login.php',$limited,['csrf'=>authTestToken($form),'login'=>$limitedLogin,'password'=>$password]);
        cgCheck(authTestRequest($server,$path,$limited)[0]===403,'Read-only user opened the payment form.');
        cgCheck(authTestRequest($server,'/modules/ventas/pagos-comisiones.php',$limited)[0]===200,'Read-only history is inaccessible.');
    }finally{if(is_file($limited))unlink($limited);}
    $usd=(int)$db->query("SELECT id FROM accounting_currencies WHERE company_id=1 AND code='USD'")->fetchColumn();
    $ves=(int)$db->query("SELECT id FROM accounting_currencies WHERE company_id=1 AND code='VES'")->fetchColumn();
    $db->prepare('DELETE FROM accounting_currency_rates WHERE company_id=1 AND rate_date IN (?,?)')->execute([$yesterday,$today]);
    foreach([$yesterday=>'100.00',$today=>'110.00'] as $date=>$rate)$insert('accounting_currency_rates',['base_currency_id'=>$usd,'currency_id'=>$ves,'rate_date'=>$date,'rate'=>$rate,'source'=>'manual']);
    foreach([$path,'/modules/ventas/comisiones.php?id='.$b,'/modules/ventas/pagos-comisiones.php?new=1&invoice_id='.$b] as $route){
        [$code,$body]=authTestRequest($server,$route.'&payment_rate_date='.$yesterday,$cookie);$snapshot=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        cgCheck($code===200&&$snapshot['exchange_rate']==='100.00'&&$snapshot['rate_date']===$yesterday,'Commission rate lookup ignored the payment date.');
        cgCheck(authTestRequest($server,$route.'&payment_rate_date=invalid',$cookie)[0]===422,'Commission rate lookup accepted an invalid date.');
    }
    $historicalSeller=$insert('contacts',['name'=>'Historico QA','type_id'=>$types['salesperson']]);$historicalInvoice=$invoice('5.00',$yesterday,'USD',$historicalSeller);
    $historicalInput=array_replace($input,['amount'=>'2.00','movement_date'=>$yesterday,'currency_code'=>'VES']);unset($historicalInput['exchange_rate']);
    $historic=paySellerCommission($db,$historicalSeller,'USD',$historicalInput,bin2hex(random_bytes(32)));
    $historicRow=$db->query('SELECT * FROM accounting_treasury_documents WHERE id='.$historic)->fetch();
    cgCheck($historicRow['amount']==='200.00'&&$historicRow['exchange_rate']==='100.00'&&$historicRow['movement_date']===$yesterday&&substr($historicRow['created_at'],0,10)===$today,'Backdated commission did not preserve payment date, historical rate and actual registration date.');
    if(in_array('--browser',$argv,true)){
        $env=getenv();$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_TEST_SELLER']=(string)$historicalSeller;$env['NEXO_TEST_INVOICE']=(string)$historicalInvoice;$env['NEXO_TEST_PAYMENT_DATE']=$yesterday;
        $browser=proc_open(['node',__DIR__.'/commission-general-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
        fclose($pipes[0]);cgCheck(proc_close($browser)===0,'General commission browser verification failed.');
    }
    echo "PASS: uncollected generated commissions, capped cumulative partial payments, oldest-first batches, one cash expense, idempotency, new sales, returns/offsets, reversal, USD/VES, migration retry and HTTP form/history.\n";
}finally{if($server)stopAuthTestServer($server);if(is_file($cookie))unlink($cookie);}

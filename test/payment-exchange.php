<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/payments-repository.php';
require_once dirname(__DIR__).'/php/payment-proofs.php';
function fxCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$server=null;$cookie=tempnam(sys_get_temp_dir(),'fx-qa-');$guest=tempnam(sys_get_temp_dir(),'fx-guest-');
try{
    $server=startAuthTestServer();$db=$server['db'];
    // Exercise real DDL against the pre-change schema, never against the source database.
    fxCheck($db->query('SELECT DATABASE()')->fetchColumn()===$server['database']&&preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',$server['database'])===1,'Migration fixture is not isolated.');
    foreach(['accounting_invoice_payments'=>['usd_reference_amount','fx_currency_code','fx_invoice_rate','fx_payment_rate','fx_historical_amount','fx_settlement_amount','fx_difference'],
        'accounting_invoices'=>['usd_reference_amount'],'accounting_payments'=>['exchange_rate_date','exchange_rate_source']] as $table=>$columns){
        foreach($columns as $column)if($db->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch())$db->exec("ALTER TABLE $table DROP COLUMN $column");
    }
    $login='fx.'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(14));$token=prepareFirstAdministrator($db);
    $actor=createFirstAdministrator($db,['name'=>'Diferencial QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$actor);
    $insert=static function(string $table,array $data)use($db):int{
        $db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$db->lastInsertId();
    };
    $client=$insert('contacts',['name'=>'Cliente diferencial QA','type_id'=>$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn()]);
    $account=$insert('accounting_payment_accounts',['name'=>'Caja diferencial QA','account_type'=>'cash']);
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual' AND company_id=1 LIMIT 1")->fetchColumn();
    $usdReady=false;
    $invoice=static function(string $amount='100.00',string $currency='USD')use($insert,$client,$db,&$usdReady):int{
        $id=$insert('accounting_invoices',['name'=>'FX-'.bin2hex(random_bytes(6)),'invoice_type'=>'customer','contact_id'=>$client,'invoice_address'=>'QA',
            'invoice_date'=>'2026-11-01','status'=>'posted','base_amount'=>$amount,'total_amount'=>$amount,'currency_code'=>$currency,'currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100.00','exchange_rate_date'=>'2026-11-01','exchange_rate_source'=>'manual']);
        if($usdReady){$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$id]);invoiceFreezeUsdDebt($db,$q->fetch());}return $id;
    };
    $read=static function(int $id)use($db):array{$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$id]);return $q->fetch();};
    $base=['partner_type'=>'customer','direction'=>'inbound','contact_id'=>$client,'account_id'=>$account,'method_id'=>$method,'reference'=>'FX QA','currency_code'=>'VES','primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>'110.00','payment_date'=>'2026-11-08'];
    // A pre-existing reconciliation must be enriched without changing its amounts or state.
    $legacyInvoice=$invoice();$legacy=$insert('accounting_payments',$base+['name'=>'FX-LEGACY','amount'=>'4400','received_amount'=>'4400','status'=>'pending']);
    $legacyAllocation=$insert('accounting_invoice_payments',['payment_id'=>$legacy,'invoice_id'=>$legacyInvoice,'amount'=>'40','payment_amount'=>'4400','payment_date'=>'2026-11-08','reference'=>'FX QA','status'=>'pending']);
    $before=$db->query('SELECT amount,payment_amount,status FROM accounting_invoice_payments WHERE id='.$legacyAllocation)->fetch();
    $migration=require dirname(__DIR__).'/php/migrations/versioned/20261122163654_diferencial_cambiario_por_abono.php';$migration($db);
    fxCheck($before===$db->query('SELECT amount,payment_amount,status FROM accounting_invoice_payments WHERE id='.$legacyAllocation)->fetch(),'Backfill changed legacy amounts.');
    fxCheck($db->query('SELECT fx_difference FROM accounting_invoice_payments WHERE id='.$legacyAllocation)->fetchColumn()==='400.00','Legacy differential not derived from frozen rates.');
    fxCheck((int)$db->query('SELECT @nexo_actor_id')->fetchColumn()===$actor,'Migration did not restore company actor context.');
    $snapshot=$db->query('SELECT * FROM accounting_invoice_payments WHERE id='.$legacyAllocation)->fetch();$migration($db);fxCheck($snapshot===$db->query('SELECT * FROM accounting_invoice_payments WHERE id='.$legacyAllocation)->fetch(),'Repeated migration changed stored snapshot.');

    $legacyVes=$invoice('1000.00','VES');$legacyVesPayment=$insert('accounting_payments',array_replace($base,['name'=>'FX-LEGACY-VES','amount'=>'400.00','received_amount'=>'400.00','status'=>'settled']));
    $legacyVesAllocation=$insert('accounting_invoice_payments',['payment_id'=>$legacyVesPayment,'invoice_id'=>$legacyVes,'amount'=>'400.00','payment_amount'=>'400.00','payment_date'=>'2026-11-08','reference'=>'VES previo','status'=>'settled']);
    $usdMigration=require dirname(__DIR__).'/php/migrations/versioned/20261122173002_facturas_ves_referencia_usd.php';$usdMigration($db);$usdReady=true;
    fxCheck($db->query('SELECT amount FROM accounting_invoice_payments WHERE id='.$legacyVesAllocation)->fetchColumn()==='400.00'&&paymentDebtPosition($db,$read($legacyVes))['unpaid']==='6.00','VES migration rewrote prior payments or lost their original coverage.');
    $target=$invoice();$payments=[];
    foreach([['4400.00','110.00','40.00','400.00','2026-11-08'],['3600.00','120.00','30.00','600.00','2026-11-15'],['3900.00','130.00','30.00','900.00','2026-11-22']] as [$amount,$rate,$covered,$delta,$date]){
        $i=$read($target);paymentFromInvoice($db,$target,(int)$i['version'],'register_payment',array_replace($base,['amount'=>$amount,'exchange_rate'=>$rate,'payment_date'=>$date]));
        $rows=invoicePaymentDetails($db,$target)['payments'];$allocation=end($rows);$p=loadAccountingPayment($db,(int)$allocation['payment_id']);$payments[]=$p['id'];
        fxCheck($allocation['amount']===$covered&&$allocation['fx_difference']===$delta&&$p['available']==='0.00','Partial payment has wrong USD coverage, difference or false surplus.');
        fxCheck($allocation['fx_historical_amount']===bcmul($covered,'100',2),'Historical value uses full invoice instead of the applied portion.');
        $sumBefore=paymentExchangeTotals($rows)['VES']??'0.00';transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');
        fxCheck((paymentExchangeTotals(invoicePaymentDetails($db,$target)['payments'])['VES']??'0.00')===bcadd($sumBefore,$delta,2),'Pending was included in confirmed total or confirmation duplicated the difference.');
    }
    fxCheck($read($target)['payment_state']==='paid'&&invoicePaymentDetails($db,$target)['settled']==='100.00','Three partial payments did not settle fixed USD debt.');
    fxCheck(paymentExchangeTotals(invoicePaymentDetails($db,$target)['payments'])['VES']==='1900.00','Cumulative exchange difference incorrect.');
    $q=$db->query('SELECT SUM(amount) FROM accounting_treasury_entries WHERE payment_id IN('.implode(',',$payments).')');fxCheck($q->fetchColumn()==='11900.00','Difference duplicated actual cash movements.');
    $p=loadAccountingPayment($db,$payments[0]);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'unapply_payment',['invoice_id'=>$target]);
    fxCheck(invoicePaymentDetails($db,$target)['settled']==='60.00'&&paymentExchangeTotals(invoicePaymentDetails($db,$target)['payments'])['VES']==='1500.00','Unapply did not release original USD portion and difference.');
    $p=loadAccountingPayment($db,$p['id']);fxCheck($p['available']==='4400.00','Unapply lost available payment.');
    transitionAccountingPayment($db,$p['id'],(int)$p['version'],'apply_payment',['invoice_id'=>$target,'amount'=>'4400.00']);
    fxCheck(paymentExchangeTotals(invoicePaymentDetails($db,$target)['payments'])['VES']==='1900.00','Reapply duplicated difference.');
    $p=loadAccountingPayment($db,$payments[2]);reverseAccountingPayment($db,$p['id'],(int)$p['version'],['reason'=>'Reversion QA','movement_date'=>'2026-11-22']);
    fxCheck($read($target)['payment_state']==='partial'&&invoicePaymentDetails($db,$target)['settled']==='70.00'&&paymentExchangeTotals(invoicePaymentDetails($db,$target)['payments'])['VES']==='1000.00','Reversal did not restore USD balance and active difference.');

    $over=$invoice('10.00');$i=$read($over);paymentFromInvoice($db,$over,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'1320.00','exchange_rate'=>'120.00']));
    $a=invoicePaymentDetails($db,$over)['payments'][0];$p=loadAccountingPayment($db,(int)$a['payment_id']);
    fxCheck($p['available']==='120.00'&&$a['fx_settlement_amount']==='1200.00'&&$a['fx_difference']==='200.00','True overpayment counted as exchange difference.');
    $same=$invoice('10.00');$i=$read($same);paymentFromInvoice($db,$same,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'10.00','currency_code'=>'USD']));
    fxCheck(invoicePaymentDetails($db,$same)['payments'][0]['fx_difference']===null,'USD against USD must not have operational conversion difference.');
    $low=paymentExchangeSnapshot($read($same),array_replace($base,['exchange_rate'=>'90.00']),'5.00','450.00');fxCheck($low['fx_difference']==='-50.00','Decreasing rate sign incorrect.');
    $reverse=paymentExchangeSnapshot($read($same),array_replace($base,['primary_code'=>'VES','alternate_code'=>'USD','exchange_rate'=>'0.01']),'5.00','500.00');fxCheck($reverse['fx_difference']==='0.00'&&$reverse['fx_payment_rate']==='100.0000000000','Inverse pair orientation incorrect.');
    $native=$invoice('1000.00','VES');$i=$read($native);paymentFromInvoice($db,$native,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'500.00']));
    fxCheck(invoicePaymentDetails($db,$native)['pending']==='455.00'&&paymentDebtPosition($db,$read($native))['pending']==='4.55','VES payment must cover its USD reference at the payment rate.');
    $unknown=$read($same);$unknown['exchange_rate']=null;fxCheck(paymentExchangeSnapshot($unknown,$base,'1.00','110.00')['fx_difference']===null,'Unknown historical rate was invented.');
    // Multiple allocations of one payment must snapshot only their respective portion.
    $splitFirst=$invoice('10.00');$splitSecond=$invoice('5.00');
    $splitPayment=insertAccountingPayment($db,$base+['amount'=>'1800.00','received_amount'=>'1800.00'],'pending');
    foreach([[$splitFirst,'550.00'],[$splitFirst,'550.00'],[$splitSecond,'550.00']] as [$invoiceId,$part]){
        $p=loadAccountingPayment($db,$splitPayment);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'apply_payment',['invoice_id'=>$invoiceId,'amount'=>$part]);
    }
    $split=loadAccountingPayment($db,$splitPayment);
    fxCheck(count($split['allocations'])===2&&$split['allocations'][0]['fx_difference']==='100.00'&&$split['allocations'][1]['fx_difference']==='50.00'&&$split['available']==='150.00','Repeated or split application duplicates the snapshot or includes unused credit.');

    // Test automatic dated rates and actual HTML/JSON paths in the isolated server.
    $usd=(int)$db->query("SELECT id FROM accounting_currencies WHERE company_id=1 AND code='USD'")->fetchColumn();$ves=(int)$db->query("SELECT id FROM accounting_currencies WHERE company_id=1 AND code='VES'")->fetchColumn();
    $db->prepare('UPDATE accounting_currency_settings SET primary_code=?,alternate_code=? WHERE company_id=1')->execute(['USD','VES']);
    $db->prepare('DELETE FROM accounting_currency_rates WHERE company_id=1 AND rate_date IN (?,?)')->execute(['2026-11-08','2026-11-15']);
    foreach(['2026-11-08'=>'110.00','2026-11-15'=>'120.00'] as $date=>$rate)$insert('accounting_currency_rates',['base_currency_id'=>$usd,'currency_id'=>$ves,'rate_date'=>$date,'rate'=>$rate,'source'=>'manual']);
    $db->beginTransaction();$dated=validateAccountingPayment($db,['amount'=>'110.00','currency_code'=>'VES','payment_date'=>'2026-11-08','account_id'=>$account,'method_id'=>$method,'contact_id'=>$client]);$db->rollBack();
    fxCheck($dated['exchange_rate']==='110.00'&&$dated['exchange_rate_date']==='2026-11-08','Default rate did not use payment date.');
    $db->beginTransaction();try{validateAccountingPayment($db,['amount'=>'110.00','currency_code'=>'VES','exchange_rate'=>'','payment_date'=>'2026-11-08','account_id'=>$account,'method_id'=>$method,'contact_id'=>$client]);throw new LogicException('Missing alternate-currency rate accepted.');}catch(InvalidArgumentException){}finally{$db->rollBack();}
    [, $loginForm]=authTestRequest($server,'/login.php',$cookie);fxCheck(authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($loginForm),'login'=>$login,'password'=>$password])[0]===303,'QA login failed.');
    $path='/modules/contabilidad/index.php?type=customer&id='.$target;
    [$status,$html]=authTestRequest($server,$path,$cookie);fxCheck($status===200&&str_contains($html,'Diferencial confirmado')&&str_contains($html,'data-payment-fx-preview'),'Invoice differential UI missing.');
    [$status,$html]=authTestRequest($server,'/modules/contabilidad/pagos.php?type=customer&id='.$payments[0],$cookie);fxCheck($status===200&&str_contains($html,'payment-exchange-detail'),'Payment differential UI missing.');
    foreach([$path,'/modules/contabilidad/pagos.php?type=customer&new=1'] as $route){
        [$status,$json]=authTestRequest($server,$route.'&payment_rate_date=2026-11-15',$cookie);$result=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        fxCheck($status===200&&$result['exchange_rate']==='120.00'&&$result['rate_date']==='2026-11-15','Dated payment rate endpoint failed.');
        fxCheck(authTestRequest($server,$route.'&payment_rate_date=invalid',$cookie)[0]===422,'Invalid date accepted.');
        fxCheck(authTestRequest($server,$route.'&payment_rate_date=2026-11-15',$guest)[0]===401,'Guest can read payment rates.');
    }
    require __DIR__.'/ves-reference-cases.php';
    if(in_array('--browser',$argv,true)){
        $env=getenv();foreach(['NEXO_TEST_BASE_URL'=>$server['url'],'NEXO_TEST_LOGIN'=>$login,'NEXO_TEST_PASSWORD'=>$password,'NEXO_FX_INVOICE'=>$target,'NEXO_FX_VES_INVOICE'=>$vesInvoice] as $key=>$value)$env[$key]=(string)$value;
        $process=proc_open(['node',__DIR__.'/payment-exchange-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);fxCheck(proc_close($process)===0,'FX browser checks failed.');
    }
    // Backfill uses each row's company explicitly, preserving the caller's context.
    $other=$insert('business_company',['name'=>'Empresa diferencial QA']);$db->exec('SET @nexo_company_id='.$other);
    $otherClient=$insert('contacts',['name'=>'Otro cliente FX','type_id'=>$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn()]);
    $otherAccount=$insert('accounting_payment_accounts',['name'=>'Otra caja FX','account_type'=>'cash']);$otherMethod=$insert('accounting_payment_methods',['name'=>'Otro medio FX']);
    $otherInvoice=$insert('accounting_invoices',['name'=>'FX-OTHER','invoice_type'=>'customer','contact_id'=>$otherClient,'invoice_address'=>'QA','invoice_date'=>'2026-11-01','status'=>'posted','base_amount'=>'10','total_amount'=>'10','currency_code'=>'USD','currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100']);
    $otherPayment=$insert('accounting_payments',array_replace($base,['name'=>'FX-OTHER','contact_id'=>$otherClient,'account_id'=>$otherAccount,'method_id'=>$otherMethod,'amount'=>'1400','received_amount'=>'1400','exchange_rate'=>'140.00','status'=>'pending']));
    $otherAllocation=$insert('accounting_invoice_payments',['payment_id'=>$otherPayment,'invoice_id'=>$otherInvoice,'amount'=>'10','payment_amount'=>'1400','payment_date'=>'2026-11-08','reference'=>'FX QA','status'=>'pending']);
    $otherVesInvoice=$insert('accounting_invoices',['name'=>'FX-OTHER-VES','invoice_type'=>'customer','contact_id'=>$otherClient,'invoice_address'=>'QA','invoice_date'=>'2026-11-01','status'=>'posted','base_amount'=>'1000','total_amount'=>'1000','currency_code'=>'VES','currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100']);
    $otherVesPayment=$insert('accounting_payments',array_replace($base,['name'=>'FX-OTHER-VES','contact_id'=>$otherClient,'account_id'=>$otherAccount,'method_id'=>$otherMethod,'amount'=>'1000','received_amount'=>'1000','exchange_rate'=>'110.00','status'=>'pending']));
    $otherVesAllocation=$insert('accounting_invoice_payments',['payment_id'=>$otherVesPayment,'invoice_id'=>$otherVesInvoice,'amount'=>'1000','payment_amount'=>'1000','payment_date'=>'2026-11-08','reference'=>'VES QA previo','status'=>'pending']);
    $db->exec('SET @nexo_company_id=1');$saved=invoicePaymentDetails($db,$target)['payments'];$migration($db);$usdMigration($db);
    fxCheck($db->query('SELECT usd_reference_amount FROM accounting_invoices WHERE id='.$otherVesInvoice)->fetchColumn()==='10.00'&&$db->query('SELECT usd_reference_amount FROM accounting_invoice_payments WHERE id='.$otherVesAllocation)->fetchColumn()==='10.00','VES reference backfill failed for another company.');
    fxCheck($saved===invoicePaymentDetails($db,$target)['payments'],'Migration rerun changed existing payment snapshots.');
    fxCheck($db->query('SELECT fx_difference FROM accounting_invoice_payments WHERE id='.$otherAllocation)->fetchColumn()==='400.00'&&(int)$db->query('SELECT @nexo_company_id')->fetchColumn()===1,'Cross-company backfill failed or leaked its context.');
    echo "PASS: isolated legacy backfill/retry, USD partial payments, dated VES rates, actual cash, differences, surplus, unapply/reapply, reversal, same-currency and JSON/UI guards.\n";
}finally{if($server)stopAuthTestServer($server);foreach([$cookie,$guest] as $file)if(is_file($file))unlink($file);}

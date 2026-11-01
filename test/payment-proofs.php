<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/payments-repository.php';
function proofExpect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function proofPost(array $s,string $path,string $cookie,array $fields):array{
    $c=curl_init($s['url'].$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_POSTFIELDS=>$fields,CURLOPT_TIMEOUT=>20]);
    $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);
    proofExpect(is_string($body)&&!preg_match('/Fatal error|Warning(?:<\/b>)?:|Notice(?:<\/b>)?:/',$body),'PHP error: '.strip_tags((string)$body));return[$code,$body,$redirect];
}
$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'proof-cookie-');$anon=tempnam(sys_get_temp_dir(),'proof-anon-');$files=[];
try{
    $db=$s['db'];$login='proof.admin.'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(14));$token=prepareFirstAdministrator($db);
    createFirstAdministrator($db,['name'=>'Proof admin','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    [, $form]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($form),'login'=>$login,'password'=>$password]);
    $db->exec("INSERT INTO contacts(name,type_id) SELECT 'Cliente comprobantes',id FROM contact_types WHERE system_key='client'");$client=(int)$db->lastInsertId();
    $db->exec("INSERT INTO accounting_payment_accounts(name,account_type) VALUES('Caja comprobantes','cash')");$account=(int)$db->lastInsertId();
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $settings=accountingCurrencies($db);$currency=$settings['primary_code'];
    $currencies=$db->query('SELECT code,id FROM accounting_currencies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->prepare("INSERT INTO accounting_currency_rates(currency_id,base_currency_id,rate_date,rate) VALUES(?,?,'2020-01-01',100)")->execute([$currencies[$settings['alternate_code']],$currencies[$currency]]);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDbcAAAAASUVORK5CYII=');
    foreach(['png'=>$png,'pdf'=>"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",'bad'=>'<?php echo 1; ?>']as$key=>$bytes){$files[$key]=tempnam(sys_get_temp_dir(),'proof-file-');file_put_contents($files[$key],$bytes);}
    [, $form]=authTestRequest($s,'/modules/contabilidad/pagos.php?type=customer&new=1',$cookie);
    proofExpect(str_contains($form,'data-payment-proof')&&str_contains($form,'multipart/form-data'),'Missing payment upload.');
    $common=['csrf'=>authTestToken($form),'company_scope'=>authTestToken($form,'company_scope')];
    $input=$common+['action'=>'save_payment','creation_key'=>authTestToken($form,'creation_key'),'partner_type'=>'customer','direction'=>'inbound','contact_id'=>$client,'amount'=>'12.00','currency_code'=>$currency,'payment_date'=>commerceToday(),'account_id'=>$account,'method_id'=>$method,'reference'=>'REF-123'];
    $upload=['proof_files[0]'=>new CURLFile($files['png'],'image/png','transferencia.png')];
    [$code,,$redirect]=proofPost($s,'/modules/contabilidad/pagos.php?type=customer&new=1',$cookie,$input+$upload);
    proofExpect($code===303,'Payment upload failed.');parse_str(parse_url($redirect,PHP_URL_QUERY),$query);$payment=(int)$query['id'];
    $proofs=loadPaymentProofs($db,'payment',$payment);proofExpect(count($proofs)===1,'Payment proof not saved.');$proof=(int)$proofs[0]['id'];
    // Resending a creation key must not duplicate payment or proof.
    proofPost($s,'/modules/contabilidad/pagos.php?type=customer&new=1',$cookie,$input+$upload);
    proofExpect(count(loadPaymentProofs($db,'payment',$payment))===1,'Creation retry duplicated proof.');
    $url='/modules/contabilidad/pagos.php?type=customer&id='.$payment;
    [$code,$body]=authTestRequest($s,$url,$cookie);proofExpect($code===200&&str_contains($body,'transferencia.png'),'Saved proof not visible.');
    [$code,$bytes]=authTestRequest($s,$url.'&proof='.$proof,$cookie);proofExpect($code===200&&$bytes===$png,'Proof viewing failed.');
    [$code]=authTestRequest($s,$url.'&proof='.$proof,$anon);proofExpect($code===303,'Anonymous proof exposed.');
    [$code]=authTestRequest($s,$url.'&proof=999999999',$cookie);proofExpect($code===404,'Wrong proof accepted.');
    transitionAccountingPayment($db,$payment,1,'post_payment');transitionAccountingPayment($db,$payment,2,'settle_payment');$before=loadAccountingPayment($db,$payment);
    [$code]=proofPost($s,$url,$cookie,$common+['action'=>'add_payment_proof','proof_files[0]'=>new CURLFile($files['pdf'],'application/pdf','recibo.pdf')]);
    $after=loadAccountingPayment($db,$payment);proofExpect($code===303&&count(loadPaymentProofs($db,'payment',$payment))===2&&$after['amount']===$before['amount']&&$after['status']===$before['status'],'Adding proof changed confirmed money.');
    [$code,$body]=proofPost($s,$url,$cookie,$common+['action'=>'add_payment_proof','proof_files[0]'=>new CURLFile($files['bad'],'image/png','falso.png')]);proofExpect($code===200&&str_contains($body,'formato no admitido')&&count(loadPaymentProofs($db,'payment',$payment))===2,'Fake proof accepted.');
    [$code]=proofPost($s,$url,$cookie,array_replace($common,['csrf'=>'bad'])+['action'=>'add_payment_proof']+$upload);proofExpect($code===403,'Invalid CSRF accepted.');
    // Invoice wizard: file and money are saved atomically and visible from invoice access.
    $db->prepare("INSERT INTO accounting_invoices(name,invoice_type,contact_id,invoice_address,invoice_date,base_amount,total_amount,status,currency_code) VALUES('PROOF-INV','customer',?,'',?,20,20,'posted',?)")->execute([$client,commerceToday(),$currency]);$invoice=(int)$db->lastInsertId();
    $invoiceUrl='/modules/contabilidad/index.php?type=customer&id='.$invoice;
    [$code,$form]=authTestRequest($s,$invoiceUrl,$cookie);proofExpect($code===200&&str_contains($form,'data-payment-proof'),'Invoice wizard upload missing.');
    $invoiceInput=$common+['action'=>'register_payment','version'=>'1','amount'=>'5.00','currency_code'=>$currency,'payment_date'=>commerceToday(),'account_id'=>$account,'method_id'=>$method,'reference'=>'INV-REF'];
    [$code]=proofPost($s,$invoiceUrl,$cookie,$invoiceInput+$upload);proofExpect($code===303,'Invoice payment with proof failed.');
    $invoicePayment=(int)$db->query('SELECT payment_id FROM accounting_invoice_payments WHERE invoice_id='.$invoice)->fetchColumn();$invoiceProof=loadPaymentProofs($db,'payment',$invoicePayment)[0]['id'];
    [$code,$bytes]=authTestRequest($s,$invoiceUrl.'&proof='.$invoiceProof,$cookie);proofExpect($code===200&&$bytes===$png,'Invoice proof access failed.');
    [$code]=authTestRequest($s,$invoiceUrl.'&proof='.$proof,$cookie);proofExpect($code===404,'Unrelated invoice proof exposed.');
    $count=(int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn();
    [$code]=proofPost($s,$invoiceUrl,$cookie,array_replace($invoiceInput,['version'=>'2','amount'=>'3.00','proof_files[0]'=>new CURLFile($files['bad'],'image/png','falso.png')]));proofExpect((int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn()===$count,'Rejected proof created money.');
    // Treasury uses the same optional attachment, with an independent owner.
    [, $form]=authTestRequest($s,'/modules/contabilidad/tesoreria.php?new=1&kind=income',$cookie);
    $concept=treasuryDefaultConcept($db,'customer','inbound');
    [$code,,$redirect]=proofPost($s,'/modules/contabilidad/tesoreria.php?new=1&kind=income',$cookie,$common+['action'=>'save_treasury','creation_key'=>authTestToken($form,'creation_key'),'kind'=>'income','account_id'=>$account,'amount'=>'10.00','currency_code'=>$currency,'movement_date'=>commerceToday(),'concept_id'=>$concept,'method_id'=>$method,'reference'=>'Tesorería']+$upload);
    proofExpect($code===303,'Treasury upload failed.');parse_str(parse_url($redirect,PHP_URL_QUERY),$query);$treasury=(int)$query['id'];proofExpect(count(loadPaymentProofs($db,'treasury',$treasury))===1,'Treasury proof missing.');
    // Existing financial suites run against the isolated, historically seeded fixture.
    $env=getenv();$env['NEXO_DATABASE_NAME']=$s['database'];$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;
    $browser=proc_open(['node',__DIR__.'/payment-proofs-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$browserPipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($browserPipes[0]);proofExpect(proc_close($browser)===0,'Payment browser test failed.');
    foreach(['accounting-payments','treasury','sales-commissions']as$test){$p=proc_open([PHP_BINARY,__DIR__.'/'.$test.'.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);proofExpect(proc_close($p)===0,'Regression failed: '.$test);}
    echo "PASS: private optional proofs, invoice payments, treasury, confirmed-payment attachments, CSRF, file validation, rollback and idempotent creation.\n";
}finally{foreach(array_merge($files,[$cookie,$anon])as$file)if(is_file($file))unlink($file);stopAuthTestServer($s);}

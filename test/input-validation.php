<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/contacts-repository.php';
require_once dirname(__DIR__).'/php/settings-repository.php';
require_once dirname(__DIR__).'/php/inventory-repository.php';
require_once dirname(__DIR__).'/php/inventory-stock-repository.php';
require_once dirname(__DIR__).'/php/tax-repository.php';
require_once dirname(__DIR__).'/php/sales-repository.php';
function inputCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['0212-5550101','+58 (412) 555-0202','5550101','+1 202 555 0101',''] as $phone)inputCheck(phoneFormatError($phone)===null,'Valid telephone rejected: '.$phone);
foreach(['abc','0412ABC0202','+58+4125550202','123456','1234567890123456','(0412-5550202','0412)-5550202'] as $phone)inputCheck(phoneFormatError($phone)!==null,'Invalid telephone accepted: '.$phone);
foreach([['V','00123456'],['E','00001234'],['J','00123456-7'],['J','001234567'],['P','AB001234'],['P','A-001234']] as [$code,$number])inputCheck(documentFormatError($number,$code)===null,'Valid document rejected.');
foreach([['V','ABC123'],['E','1e3'],['J','ABC123'],['V','12.3'],['J','123-45'],['P','ABC@123']] as [$code,$number])inputCheck(documentFormatError($number,$code)!==null,'Invalid document accepted.');
foreach(['abc','1e3','12abc','1.2.3','-1',[]] as $number){inputCheck(salesDecimal($number,10,2)===null&&inventoryQuantity($number)===null,'Invalid numeric value accepted.');}
inputCheck(salesDecimal('001,25',10,2)==='1.25'&&inventoryQuantity('0,50')==='0.50','Comma decimals are not supported.');
[, $errors]=validateInventoryProduct(['name'=>'Cost type QA','cost_price'=>['bad']]);inputCheck((bool)$errors,'Malformed cost became zero.');
[, $errors]=validateBusinessTax(['name'=>'Tax type QA','rate'=>['bad']]);inputCheck((bool)$errors,'Malformed tax became zero.');
foreach([['phone'=>'letters'],['tax_id'=>'J-ABC']] as $invalid){$rejected=false;try{validateBusinessCompany(['name'=>'Company QA']+$invalid);}catch(InvalidArgumentException){$rejected=true;}inputCheck($rejected,'Invalid company document/telephone accepted.');}
echo "PASS: document formats, phone formatting/length, numeric validators, comma decimals and malformed numeric values.\n";
$s=startAuthTestServer();$cookie=null;
try{
    $db=$s['db'];$password=bin2hex(random_bytes(12));$login='input.'.bin2hex(random_bytes(4));$setup=prepareFirstAdministrator($db);createFirstAdministrator($db,['name'=>'Input validation QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'input-cookie-');[, $body]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($body),'login'=>$login,'password'=>$password]);
    [, $body]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie);$token=authTestToken($body);
    $types=$db->query('SELECT code,id FROM contact_document_types')->fetchAll(PDO::FETCH_KEY_PAIR);$contactType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $base=['csrf'=>$token,'action'=>'save_contact','name'=>'Input validation '.bin2hex(random_bytes(5)),'entity_kind'=>'person','type_id'=>$contactType,'document_type_id'=>$types['V'],'tax_id'=>'00123456','phone'=>'+58 (412) 555-0101','mobile'=>'0412-5550202'];
    foreach([['tax_id'=>'ABC123'],['tax_id'=>'1e3'],['tax_id'=>['bad']],['phone'=>'0412abc1234'],['mobile'=>'++584125550202']] as $bad){
        $count=(int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn();[$code,$html]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,array_replace($base,$bad));
        inputCheck($code===200&&str_contains($html,'notice-error')&&(int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===$count,'HTTP bypass persisted invalid contact data.');
    }
    [$code,$html]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,$base);inputCheck($code===303,'Valid contact could not be created: '.mb_substr(strip_tags($html),-900));
    $q=$db->prepare('SELECT tax_id,phone FROM contacts WHERE name=?');$q->execute([$base['name']]);$saved=$q->fetch();inputCheck($saved['tax_id']==='00123456'&&$saved['phone']==='+58 (412) 555-0101','Leading zeros or phone formatting changed.');
    [$code]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,array_replace($base,['name'=>$base['name'].' passport','document_type_id'=>$types['P'],'tax_id'=>'A-001234']));inputCheck($code===303,'Alphanumeric passport blocked.');
    [$code]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie,array_replace($base,['name'=>$base['name'].' tax','document_type_id'=>$types['J'],'tax_id'=>'J-00123456-7']));inputCheck($code===303,'Valid prefixed fiscal document blocked.');
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_INPUT_V']=(string)$types['V'];$env['NEXO_INPUT_P']=(string)$types['P'];
    $process=proc_open(['node',__DIR__.'/input-validation-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);inputCheck(proc_close($process)===0,'Input validation browser review failed.');
    echo "PASS: HTTP rejects malformed documents/phones without saving; valid formatted and alphanumeric documents preserved.\n";
}finally{if($cookie&&is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

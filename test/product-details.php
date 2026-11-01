<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/inventory-repository.php';
require_once dirname(__DIR__).'/php/company-repository.php';
require_once dirname(__DIR__).'/php/company-connection.php';
function detailCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function detailReject(callable $fn):void{try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid product detail accepted.');}
function detailImport(array $s,string $cookie,string $csv,bool $valid=true):void{
    $path='/datos.php?'.http_build_query(['model'=>'products','action'=>'import','source'=>'/project/modules/inventario/index.php']);
    [, $html]=authTestRequest($s,$path,$cookie);$csrf=authTestToken($html);preg_match('/name="company_scope" value="([a-f0-9]+)"/',$html,$scope);
    $file=tempnam(sys_get_temp_dir(),'product-details-csv-');
    try{
        file_put_contents($file,$csv);$c=curl_init($s['url'].$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>['csrf'=>$csrf,'company_scope'=>$scope[1],'phase'=>'preview','csv'=>new CURLFile($file,'text/csv','import.csv')]]);$html=curl_exec($c);curl_close($c);
        $hasToken=preg_match('/name="token" value="([a-f0-9]+)"/',(string)$html,$token)===1;
        detailCheck($hasToken===$valid,'CSV preview: '.mb_substr(strip_tags((string)$html),-1000));
        if($valid){[$code,$html]=authTestRequest($s,$path,$cookie,['csrf'=>$csrf,'company_scope'=>$scope[1],'phase'=>'confirm','token'=>$token[1]]);detailCheck($code===200&&str_contains($html,'registros guardados'),'CSV confirmation failed.');}
    }finally{unlink($file);}
}
foreach([['weight_kg'=>'-1'],['width_cm'=>'1.001'],['height_cm'=>'1e2'],['depth_cm'=>['2']],['barcode'=>'a b'],['barcode'=>str_repeat('a',101)],['color'=>"Rojo\nAzul"]]as$bad){[, $errors]=validateInventoryProductDetails($bad);detailCheck((bool)$errors,'Invalid optional detail accepted.');}
$s=startAuthTestServer();$cookies=[];
try{
    $db=$s['db'];$count=(int)$db->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn();installProductDetails($db);installProductDetails($db);detailCheck((int)$db->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn()===$count,'Migration changed products.');
    $password=bin2hex(random_bytes(12));$login='details.'.bin2hex(random_bytes(5));$setup=prepareFirstAdministrator($db);$admin=createFirstAdministrator($db,['name'=>'Product QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'details-cookie-');$cookies[]=$cookie;[, $html]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password]);
    [, $html]=authTestRequest($s,'/modules/inventario/index.php?new=1',$cookie);$csrf=authTestToken($html);detailCheck(str_contains($html,'product-page-details'),'Details tab missing.');
    $base=['csrf'=>$csrf,'action'=>'save_product','name'=>'Product details QA','sale_enabled'=>'1','purchase_enabled'=>'1','sale_price'=>'12.50','cost_price'=>'5.00','currency_code'=>'USD','product_type'=>'goods','invoice_policy'=>'ordered'];
    $input=$base+['barcode'=>'0000123456789','color'=>'Azul marino','weight_kg'=>'1,25','width_cm'=>'0','height_cm'=>'25.75','depth_cm'=>'12'];
    [$code,$html]=authTestRequest($s,'/modules/inventario/index.php?new=1',$cookie,$input);detailCheck($code===303,'Product not saved: '.mb_substr(strip_tags($html),-1000));
    $id=(int)$db->query("SELECT id FROM inventory_products WHERE barcode='0000123456789'")->fetchColumn();detailCheck($id>0,'Barcode leading zeros lost.');
    $load=static function(int $id)use($db):array{$q=$db->prepare('SELECT * FROM inventory_products WHERE id=?');$q->execute([$id]);return $q->fetch();};
    $p=$load($id);detailCheck($p['weight_kg']==='1.25'&&$p['width_cm']==='0.00'&&$p['color']==='Azul marino','Product values not normalized.');
    [$code,$html]=authTestRequest($s,'/modules/inventario/index.php?new=1',$cookie,array_replace($input,['name'=>'Duplicate QA']));detailCheck($code===200&&str_contains($html,'ya está asignado'),'Duplicate barcode not explained.');
    [, $html]=authTestRequest($s,'/modules/inventario/variantes.php?new=1&parent_id='.$id,$cookie);
    detailCheck(str_contains($html,'name="weight_kg" inputmode="decimal" maxlength="11" value="1.25"')&&str_contains($html,'name="barcode" maxlength="100" value=""'),'Variant inheritance copied barcode or lost measurements.');
    $variantInput=array_replace($base,['parent_product_id'=>(string)$id,'name'=>'Blue variant QA','barcode'=>'0000123456790']);[$code]=authTestRequest($s,'/modules/inventario/variantes.php?new=1',$cookie,$variantInput);detailCheck($code===303,'Variant not saved.');
    $variant=(int)$db->query("SELECT id FROM inventory_products WHERE barcode='0000123456790'")->fetchColumn();detailCheck($load($variant)['weight_kg']==='1.25'&&$load($variant)['color']==='Azul marino','Variant optional values not inherited.');
    $simple=inventoryCreateVariant($db,['parent_product_id'=>$id,'name'=>'Simple variant QA']);detailCheck($load($simple)['barcode']===null&&$load($simple)['weight_kg']==='1.25','Simple variant inherited identifying barcode.');
    [, $html]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$cookie);$revision=authTestToken($html,'product_revision');
    [$code]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$cookie,$base+['product_revision'=>$revision]);detailCheck($code===303&&$load($id)['barcode']==='0000123456789','Omitted fields erased existing attributes.');
    [, $html]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$cookie);$revision=authTestToken($html,'product_revision');
    $db->prepare('UPDATE inventory_products SET color=? WHERE id=?')->execute(['Verde',$id]);
    [$code]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$cookie,$input+['product_revision'=>$revision]);detailCheck($code===409&&$load($id)['color']==='Verde','Concurrent characteristics change overwritten.');
    foreach([[$id,'/modules/inventario/index.php?q=0000123456789'],[$variant,'/modules/inventario/variantes.php?q=0000123456790']] as [$match,$path]){[$code,$html]=authTestRequest($s,$path,$cookie);detailCheck($code===200&&str_contains($html,'index.php?id='.$match),'Barcode search failed.');}
    $export='/datos.php?'.http_build_query(['model'=>'products','action'=>'export','source'=>'/project/modules/inventario/index.php?q=0000123456789','download'=>'1','fields'=>['barcode','color','weight_kg']]);[$code,$csv]=authTestRequest($s,$export,$cookie);detailCheck($code===200&&str_contains($csv,'0000123456789;Verde;1.25')&&!str_contains($csv,'0000123456790'),'CSV export ignored barcode filter or precision.');
    detailImport($s,$cookie,"name;barcode;color;weight_kg;width_cm;height_cm;depth_cm\nCSV optional QA;0000000098765;Blanco;0,75;10;20;30\n");
    $csvId=(int)$db->query("SELECT id FROM inventory_products WHERE barcode='0000000098765'")->fetchColumn();detailCheck($csvId>0&&$load($csvId)['weight_kg']==='0.75','CSV import lost optional attributes.');
    detailImport($s,$cookie,"id;name\n$csvId;CSV updated QA\n");detailCheck($load($csvId)['barcode']==='0000000098765','CSV partial update erased barcode.');
    $before=(int)$db->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn();detailImport($s,$cookie,"name;barcode\nCSV rollback QA;123456789\nCSV duplicate QA;0000000098765\n",false);detailCheck((int)$db->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn()===$before,'Failed CSV left partial products.');
    [$data,$errors]=validateInventoryProduct(array_replace($base,['sale_enabled'=>'1','purchase_enabled'=>'1']));detailCheck(!$errors,'Optional values became required.');$blank=saveInventoryProduct($db,$data,null);detailCheck($load($blank)['weight_kg']===null&&$load($blank)['barcode']===null,'Blank fields fabricated values.');
    $data['barcode']='0000123456789';detailReject(fn()=>saveInventoryProduct($db,$data,null));
    $config=require dirname(__DIR__).'/php/config/database.php';$tables=$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);$otherId=createBusinessCompany($db,['name'=>'Other details company QA'],$admin);
    $other=new CompanyConnection('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$s['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[$otherId],$otherId,$admin,$tables);
    $otherProduct=saveInventoryProduct($other,$data,null);detailCheck($otherProduct>0,'Barcode uniqueness leaked across companies.');
    $db->exec('SET @nexo_company_id=1');
    $db->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Details reader QA','Read products']);$group=(int)$db->lastInsertId();$db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'inventario.products.read','all')")->execute([$group]);
    $db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Details reader QA','details.reader',password_hash($password,PASSWORD_DEFAULT)]);$reader=(int)$db->lastInsertId();$db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$reader,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$reader]);
    $readerCookie=tempnam(sys_get_temp_dir(),'details-reader-');$cookies[]=$readerCookie;[, $html]=authTestRequest($s,'/login.php',$readerCookie);authTestRequest($s,'/login.php',$readerCookie,['csrf'=>authTestToken($html),'login'=>'details.reader','password'=>$password]);
    [$code,$html]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$readerCookie);detailCheck($code===200&&str_contains($html,'name="barcode"'),'Reader cannot view characteristics.');[$code]=authTestRequest($s,'/modules/inventario/index.php?id='.$id,$readerCookie,array_replace($input,['csrf'=>authTestToken($html),'product_revision'=>authTestToken($html,'product_revision')]));detailCheck($code===403&&$load($id)['color']==='Verde','Reader changed characteristics.');
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_DETAILS_PRODUCT']=(string)$id;
    $process=proc_open(['node',__DIR__.'/product-details-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);detailCheck(proc_close($process)===0,'Product details browser test failed.');
    echo "PASS: optional product attributes, leading zeros, duplicate barcodes, variant inheritance, concurrent editing, company isolation, read-only permissions, CSV validation/rollback and mobile forms.\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

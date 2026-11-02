<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/inventory-operations-repository.php';
require_once dirname(__DIR__).'/php/operation-status-repository.php';
function trackingAssert(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function trackingReject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException($message);}
$s=startAuthTestServer();$cookie=null;
try{
    $db=$s['db'];installInventoryTracking($db);installInventoryTracking($db);
    $product=static function(string $mode,bool $expiry=false)use($db):int{
        [$data,$errors]=validateInventoryProduct(['name'=>'Tracking QA '.bin2hex(random_bytes(5)),'sale_enabled'=>'1','purchase_enabled'=>'1','product_type'=>'goods','sale_price'=>'10','cost_price'=>'5','currency_code'=>'USD','tracking_mode'=>$mode,'expiration_enabled'=>$expiry?'1':'0','alert_days'=>'30']);
        trackingAssert(!$errors,implode(' ',$errors));return saveInventoryProduct($db,$data,null);
    };
    $db->exec("INSERT INTO inventory_warehouses(name) VALUES('Tracking warehouse QA')");$warehouse=(int)$db->lastInsertId();$locations=[];
    foreach(['A'=>'internal','B'=>'internal','Supplier'=>'supplier','Customer'=>'customer'] as $name=>$kind){$db->prepare('INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,?)')->execute(['Tracking '.$name,$kind==='internal'?$warehouse:null,$kind]);$locations[$name]=(int)$db->lastInsertId();}
    $contacts=[];foreach(['Supplier'=>'supplier','Customer'=>'client'] as $name=>$type){$q=$db->prepare('SELECT id FROM contact_types WHERE system_key=?');$q->execute([$type]);$db->prepare('INSERT INTO contacts(name,type_id,partner_location_id) VALUES(?,?,?)')->execute(['Tracking '.$name,$q->fetchColumn(),$locations[$name]]);$contacts[$name]=(int)$db->lastInsertId();}
    $operation=static function(string $type,int $product,string $qty,array $tracking=[],?int $source=null,?int $destination=null)use($db,$warehouse,$locations,$contacts):int{
        $source??=$locations[$type==='receipt'?'Supplier':'A'];$destination??=$locations[$type==='delivery'?'Customer':'A'];
        return saveInventoryOperation($db,['operation_type'=>$type,'scheduled_date'=>trackingToday(),'contact_id'=>$type==='internal'?null:$contacts[$type==='receipt'?'Supplier':'Customer'],'notes'=>'Tracking QA','source_location_id'=>$source,'destination_location_id'=>$destination,'source_warehouse_id'=>in_array($source,[$locations['A'],$locations['B']],true)?$warehouse:null,'destination_warehouse_id'=>in_array($destination,[$locations['A'],$locations['B']],true)?$warehouse:null],null,null,[['product_id'=>$product,'demand'=>$qty,'quantity'=>$qty,'tracking'=>$tracking]]);
    };
    $ready=static function(int $id)use($db):void{transitionInventoryOperation($db,$id,1,'confirm_operation');transitionInventoryOperation($db,$id,2,'check_ready');};
    $finish=static function(int $id)use($db,$ready):void{$ready($id);validateInventoryStockOperation($db,$id,3);};
    $lotProduct=$product('lot',true);$serialProduct=$product('serial',true);$plainProduct=$product('none');
    $date1=(new DateTimeImmutable(trackingToday()))->modify('+10 days')->format('Y-m-d');$date2=(new DateTimeImmutable(trackingToday()))->modify('+60 days')->format('Y-m-d');
    $receipt=$operation('receipt',$lotProduct,'30',[['name'=>'LOT-EARLY','quantity'=>'10','expiration_date'=>$date1],['name'=>'LOT-LATE','quantity'=>'20','expiration_date'=>$date2]]);$finish($receipt);
    $q=$db->prepare('SELECT id FROM inventory_lots WHERE product_id=? AND name=?');$q->execute([$lotProduct,'LOT-EARLY']);$early=(int)$q->fetchColumn();$q->execute([$lotProduct,'LOT-LATE']);$late=(int)$q->fetchColumn();
    $transfer=$operation('internal',$lotProduct,'5',[],null,$locations['B']);$finish($transfer);
    $rows=trackingLoadLine($db,(int)loadInventoryOperationLines($db,$transfer)[0]['id']);trackingAssert(count($rows)===1&&(int)$rows[0]['lot_id']===$early,'FEFO did not choose earliest lot for transfer.');
    $delivery=$operation('delivery',$lotProduct,'16');$ready($delivery);
    $rows=trackingLoadLine($db,(int)loadInventoryOperationLines($db,$delivery)[0]['id']);trackingAssert(count($rows)===2&&$rows[0]['quantity']==='5.00'&&$rows[1]['quantity']==='11.00','FEFO did not split across lots.');
    trackingAssert(trackingReserved($db,$early,$locations['A'])==='5.00','Lot reservation missing.');
    validateInventoryStockOperation($db,$delivery,3);trackingAssert(trackingReserved($db,$early,$locations['A'])==='0.00','Completed delivery kept a reservation.');
    $return=createStockReturn($db,$delivery,4);$line=loadInventoryOperationLines($db,$return)[0];
    $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$return]);$record=$q->fetch();$data=array_intersect_key($record,array_flip(['operation_type','scheduled_date','contact_id','notes','source_location_id','destination_location_id','source_warehouse_id','destination_warehouse_id']));
    $line['quantity']='2.00';$line['tracking']=[['lot_id'=>$early,'quantity'=>'2','name'=>'LOT-EARLY','expiration_date'=>$date1]];
    saveInventoryOperation($db,$data,$return,1,[$line]);transitionInventoryOperation($db,$return,2,'confirm_operation');transitionInventoryOperation($db,$return,3,'check_ready');validateInventoryStockOperation($db,$return,4);
    $secondReturn=createStockReturn($db,$delivery,4);$line=loadInventoryOperationLines($db,$secondReturn)[0];$q->execute([$secondReturn]);$record=$q->fetch();$data=array_intersect_key($record,$data);
    $line['quantity']='4.00';$line['tracking']=[['lot_id'=>$early,'name'=>'LOT-EARLY','quantity'=>'4','expiration_date'=>$date1]];saveInventoryOperation($db,$data,$secondReturn,1,[$line]);transitionInventoryOperation($db,$secondReturn,2,'confirm_operation');
    trackingReject(fn()=>transitionInventoryOperation($db,$secondReturn,3,'check_ready'),'A lot return exceeded original lot quantity.');
    $serialReceipt=$operation('receipt',$serialProduct,'2',[['name'=>'SER-001','quantity'=>'1','expiration_date'=>$date2],['name'=>'SER-002','quantity'=>'1','expiration_date'=>$date2]]);$finish($serialReceipt);
    $duplicate=$operation('receipt',$serialProduct,'1',[['name'=>'SER-001','quantity'=>'1','expiration_date'=>$date2]]);transitionInventoryOperation($db,$duplicate,1,'confirm_operation');trackingReject(fn()=>transitionInventoryOperation($db,$duplicate,2,'check_ready'),'Duplicate serial accepted into stock.');
    trackingReject(fn()=>$operation('receipt',$serialProduct,'2',[['name'=>'SER-BAD','quantity'=>'2','expiration_date'=>$date2]]),'Serial quantity greater than one accepted.');
    $out=$operation('delivery',$serialProduct,'1');$ready($out);$taken=trackingLoadLine($db,(int)loadInventoryOperationLines($db,$out)[0]['id'])[0]['lot_id'];
    $other=$operation('delivery',$serialProduct,'1',[['lot_id'=>$taken,'name'=>'','quantity'=>'1','expiration_date'=>$date2]]);transitionInventoryOperation($db,$other,1,'confirm_operation');trackingReject(fn()=>transitionInventoryOperation($db,$other,2,'check_ready'),'Reserved serial assigned twice.');
    cancelStockOperation($db,$out,3);transitionInventoryOperation($db,$other,2,'check_ready');validateInventoryStockOperation($db,$other,3);
    $expired=(new DateTimeImmutable(trackingToday()))->modify('-1 day')->format('Y-m-d');$expiredReceipt=$operation('receipt',$lotProduct,'3',[['name'=>'EXPIRED','quantity'=>'3','expiration_date'=>$expired]]);$finish($expiredReceipt);
    $expiredOut=$operation('delivery',$lotProduct,'20');transitionInventoryOperation($db,$expiredOut,1,'confirm_operation');trackingReject(fn()=>transitionInventoryOperation($db,$expiredOut,2,'check_ready'),'Expired stock was offered by FEFO.');
    $initialProduct=$product('serial');$db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,2)')->execute([$initialProduct,$locations['A']]);
    trackingReject(fn()=>trackingInitialize($db,$initialProduct,$locations['A'],[['name'=>'INITIAL-1','quantity'=>'1']]),'Incomplete initial allocation accepted.');
    trackingInitialize($db,$initialProduct,$locations['A'],[['name'=>'INITIAL-1','quantity'=>'1'],['name'=>'INITIAL-2','quantity'=>'1']]);
    trackingReject(fn()=>trackingInitialize($db,$initialProduct,$locations['A'],[['name'=>'INITIAL-3','quantity'=>'1']]),'Initial stock loaded twice.');
    trackingAssert(loadInventoryProductStock($db,$initialProduct)['total']==='2.00','Initial identification changed aggregate stock.');
    [$change,$errors]=validateInventoryProduct(['name'=>'Tracking mode guard','sale_enabled'=>'1','purchase_enabled'=>'1','product_type'=>'goods','sale_price'=>'10','cost_price'=>'5','currency_code'=>'USD','tracking_mode'=>'serial']);
    trackingReject(fn()=>saveInventoryProduct($db,$change,$lotProduct),'Tracking mode changed after lot creation.');
    trackingReject(fn()=>saveInventoryProduct($db,$change+['expiration_enabled'=>1],$initialProduct),'Expiration was enabled while existing serials had no date.');
    $pendingProduct=$product('none');$pendingReceipt=$operation('receipt',$pendingProduct,'1');$ready($pendingReceipt);
    trackingReject(fn()=>saveInventoryProduct($db,$change,$pendingProduct),'Tracking mode changed while an operation was ready.');
    foreach([$lotProduct,$serialProduct,$initialProduct] as $pid){
        $a=$db->prepare("SELECT COALESCE(SUM(s.quantity),0) FROM inventory_stock s JOIN inventory_locations l ON l.id=s.location_id WHERE s.product_id=? AND l.location_type='internal'");$a->execute([$pid]);
        $b=$db->prepare("SELECT COALESCE(SUM(s.quantity),0) FROM inventory_lot_stock s JOIN inventory_locations l ON l.id=s.location_id WHERE s.product_id=? AND l.location_type='internal'");$b->execute([$pid]);trackingAssert(bccomp((string)$a->fetchColumn(),(string)$b->fetchColumn(),2)===0,'Lot balances diverged from aggregate inventory.');
    }
    $finish($operation('receipt',$plainProduct,'2'));$finish($operation('delivery',$plainProduct,'1'));
    $raceProduct=$product('serial');$finish($operation('receipt',$raceProduct,'2',[['name'=>'RACE-1','quantity'=>'1'],['name'=>'RACE-2','quantity'=>'1']]));
    $q=$db->prepare('SELECT id FROM inventory_lots WHERE product_id=? AND name=?');$q->execute([$raceProduct,'RACE-1']);$raceLot=(int)$q->fetchColumn();$jobs=[];
    foreach([1,2] as $attempt){$job=$operation('delivery',$raceProduct,'1',[['lot_id'=>$raceLot,'name'=>'RACE-1','quantity'=>'1']]);transitionInventoryOperation($db,$job,1,'confirm_operation');$jobs[]=$job;}
    $workerEnv=getenv();$workerEnv['NEXO_DATABASE_NAME']=$s['database'];$workers=[];
    foreach($jobs as $job){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/inventory-tracking-worker.php',(string)$job],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$workerEnv,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);$workers[]=[$process,$pipes];}
    $results=[];foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);trackingAssert($error===''&&in_array($exit,[0,2],true),'Concurrent reservation failed: '.$error.$output);$results[]=$exit;}sort($results);trackingAssert($results===[0,2],'Concurrent deliveries reserved the same serial.');
    echo "PASS: competing processes cannot reserve the same serial even when aggregate stock is sufficient.\n";
    echo "PASS: migration, receipts, FEFO, split lots, transfers, reservations, cancellations, serial uniqueness, expiry blocking, partial returns, initial stock and untracked products.\n";
    $password=bin2hex(random_bytes(12));$login='tracking.'.bin2hex(random_bytes(4));$setup=prepareFirstAdministrator($db);createFirstAdministrator($db,['name'=>'Tracking QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'tracking-cookie-');[, $html]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password]);
    foreach(['/modules/inventario/lotes.php','/modules/inventario/lotes.php?id='.$early,'/modules/inventario/lotes.php?initialize=1','/modules/inventario/lotes.php?new=1','/modules/inventario/index.php?id='.$lotProduct,'/modules/inventario/operaciones.php?type=receipt&id='.$receipt] as $path){[$code,$html]=authTestRequest($s,$path,$cookie);trackingAssert($code===200&&!str_contains($html,'Warning:')&&!str_contains($html,'Fatal error'),$path.' failed: '.mb_substr(strip_tags($html),-1000));}
    [$code,$print]=authTestRequest($s,'/acciones.php?'.http_build_query(['source'=>'/project/modules/inventario/operaciones.php?type=receipt&id='.$receipt,'action'=>'print']),$cookie);trackingAssert($code===200&&str_contains($print,'LOT-EARLY')&&str_contains($print,$date1),'Printed operation lost lot/expiry information.');
    [, $productForm]=authTestRequest($s,'/modules/inventario/index.php?id='.$serialProduct,$cookie);$p=trackingProduct($db,$serialProduct);
    [$code,$html]=authTestRequest($s,'/modules/inventario/index.php?id='.$serialProduct,$cookie,['csrf'=>authTestToken($productForm),'product_revision'=>authTestToken($productForm,'product_revision'),'action'=>'save_product','name'=>$p['name'],'sale_enabled'=>'1','purchase_enabled'=>'1','sale_price'=>'10','cost_price'=>'5','product_type'=>'goods','currency_code'=>'USD','tracking_mode'=>'serial','expiration_enabled'=>'1','expiration_days'=>'0','alert_days'=>'30','removal_days'=>'0','best_before_days'=>'0']);trackingAssert($code===303,'Product tracking settings could not be saved by HTTP.');
    [$code,$html]=authTestRequest($s,'/modules/inventario/lotes.php?new=1',$cookie,['action'=>'save_lot','csrf'=>'invalid','product_id'=>$lotProduct,'name'=>'CSRF-INVALID','expiration_date'=>$date2]);trackingAssert($code===403,'CSRF accepted.');
    $db->exec("INSERT INTO access_groups(name) VALUES('Tracking read-only QA')");$group=(int)$db->lastInsertId();$db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'inventario.lots.read','all')")->execute([$group]);
    $limited='tracking.read.'.bin2hex(random_bytes(4));$db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Tracking reader',$limited,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();$db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$user,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$user]);
    $readCookie=tempnam(sys_get_temp_dir(),'tracking-reader-');
    try{
        [, $html]=authTestRequest($s,'/login.php',$readCookie);authTestRequest($s,'/login.php',$readCookie,['csrf'=>authTestToken($html),'login'=>$limited,'password'=>$password]);
        [$code,$html]=authTestRequest($s,'/modules/inventario/lotes.php?id='.$early,$readCookie);trackingAssert($code===200&&!str_contains($html,'>Guardar lote / serie<'),'Read-only lot view permits editing.');
        trackingAssert(authTestRequest($s,'/modules/inventario/lotes.php?new=1',$readCookie)[0]===403,'Read-only user can open creation.');
        trackingAssert(authTestRequest($s,'/modules/inventario/lotes.php?initialize=1',$readCookie,['action'=>'initialize_tracking','csrf'=>authTestToken($html),'product_id'=>$initialProduct,'location_id'=>$locations['A'],'tracking'=>'[]'])[0]===403,'Read-only user can initialize stock.');
    }finally{unlink($readCookie);}
    // New business tables must be isolated by the same company-aware connection.
    $db->exec("INSERT INTO business_company(name) VALUES('Foreign tracking company QA')");$foreignCompany=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id='.$foreignCompany);
    $foreignProduct=$product('serial');$db->prepare("INSERT INTO inventory_lots(product_id,name,tracking_mode) VALUES(?,'FOREIGN-SERIAL','serial')")->execute([$foreignProduct]);$foreignLot=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id=1');
    require_once dirname(__DIR__).'/php/company-connection.php';$config=require dirname(__DIR__).'/php/config/database.php';
    $scoped=new CompanyConnection('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$s['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[1],1,$user,$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN));
    $find=$scoped->prepare('SELECT id FROM inventory_lots WHERE id=?');$find->execute([$foreignLot]);trackingAssert(!$find->fetchColumn(),'Lot leaked across companies.');
    trackingReject(fn()=>trackingProduct($scoped,$foreignProduct,true),'Foreign-company tracking product accepted.');
    trackingAssert(authTestRequest($s,'/modules/inventario/lotes.php?id='.$foreignLot,$cookie)[0]===404,'Foreign-company lot HTTP route leaked.');
    echo "PASS: read-only permissions, CSRF, company-scoped queries and foreign-record protection.\n";
    $uiOperation=$operation('receipt',$serialProduct,'1');
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_TRACK_PRODUCT']=(string)$serialProduct;$env['NEXO_TRACK_OPERATION']=(string)$uiOperation;
    $process=proc_open(['node',__DIR__.'/inventory-tracking-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);trackingAssert(proc_close($process)===0,'Tracking browser tests failed.');
}finally{if($cookie&&is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

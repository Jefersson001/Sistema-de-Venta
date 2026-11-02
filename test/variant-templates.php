<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/inventory-operations-repository.php';
require_once dirname(__DIR__).'/php/operation-status-repository.php';
require_once dirname(__DIR__).'/php/commerce-repository.php';
require_once dirname(__DIR__).'/php/transfer-service.php';
function templateCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function templateReject(callable $work,string $message):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException($message);}
function templateDocument(string $html):DOMXPath{$dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($old);return new DOMXPath($dom);}
$s=startAuthTestServer();$cookie=null;
try{
    $db=$s['db'];$rootData=['name'=>'Torta plantilla QA','sale_enabled'=>1,'purchase_enabled'=>1,'sale_price'=>'10.00','cost_price'=>'2.00','currency_code'=>'USD','category_id'=>null,'sale_tax_id'=>null,'purchase_tax_id'=>null,'product_type'=>'goods','invoice_policy'=>'delivered','commission_type'=>'none','commission_value'=>'0'];
    $parent=saveInventoryProduct($db,$rootData,null);$simple=saveInventoryProduct($db,array_replace($rootData,['name'=>'Producto sin variantes QA']),null);
    $db->exec("INSERT INTO inventory_warehouses(name) VALUES('Plantillas QA')");$warehouse=(int)$db->lastInsertId();$locations=[];
    foreach(['internal','supplier','customer'] as $type){$db->prepare('INSERT INTO inventory_locations(name,location_type,warehouse_id) VALUES(?,?,?)')->execute(['Plantillas '.$type,$type,$type==='internal'?$warehouse:null]);$locations[$type]=(int)$db->lastInsertId();}
    $contacts=[];foreach(['supplier'=>'supplier','customer'=>'client'] as $key=>$type){$db->prepare('INSERT INTO contacts(name,type_id,partner_location_id) SELECT ?,id,? FROM contact_types WHERE system_key=?')->execute(['Plantillas '.$key,$locations[$key],$type]);$contacts[$key]=(int)$db->lastInsertId();}
    $movement=static function(int $product,string $qty,string $type='receipt')use($db,$contacts,$locations,$warehouse):int{
        return saveInventoryOperation($db,['operation_type'=>$type,'contact_id'=>$contacts[$type==='receipt'?'supplier':'customer'],'scheduled_date'=>trackingToday(),'notes'=>'','source_location_id'=>$locations[$type==='receipt'?'supplier':'internal'],'destination_location_id'=>$locations[$type==='receipt'?'internal':'customer'],'source_warehouse_id'=>$type==='receipt'?null:$warehouse,'destination_warehouse_id'=>$type==='receipt'?$warehouse:null],null,null,[['product_id'=>$product,'demand'=>$qty,'quantity'=>$qty]]);
    };
    $finish=static function(int $id,int $version=1)use($db):void{transitionInventoryOperation($db,$id,$version,'confirm_operation');transitionInventoryOperation($db,$id,$version+1,'check_ready');validateInventoryStockOperation($db,$id,$version+2);};
    $order=static function(int $product,string $qty,bool $purchase=false)use($db,$contacts,$warehouse,$locations):int{
        $p=trackingProduct($db,$product);$amount=bcmul($qty,'10',2);
        $header=['order_date'=>trackingToday(),'payment_term_id'=>null,'invoice_address'=>'Dirección QA','currency_code'=>'USD','currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100','base_amount'=>$amount,'tax_amount'=>'0','total_amount'=>$amount];
        if($purchase)$header+=['supplier_id'=>$contacts['supplier'],'order_type'=>'receipt','destination_location_id'=>$locations['internal']];
        else $header+=['customer_id'=>$contacts['customer'],'salesperson_id'=>null,'delivery_address'=>'Dirección QA','warehouse_id'=>$warehouse,'location_id'=>$locations['internal'],'storage_label'=>'','order_type'=>'delivery'];
        $line=['product_id'=>$product,'product_name'=>$p['name'],'quantity'=>$qty,'unit_price'=>'10','tax_id'=>null,'tax_name'=>null,'tax_rate'=>'0','tax_amount'=>'0','base_amount'=>$amount,'total_amount'=>$amount,'product_type'=>'goods','invoice_policy'=>'delivered','unit_cost'=>'2'];
        return $purchase?savePurchaseOrder($db,$header,[$line],null,null):saveSalesQuotation($db,$header,[$line],null,null);
    };
    // Historical stock and confirmed orders predate the first variant.
    $originalReceipt=$movement($parent,'3');$finish($originalReceipt);
    $oldSale=$order($parent,'1');transitionSalesQuotation($db,$oldSale,1,'sale');
    $oldPurchase=$order($parent,'1',true);transitionPurchaseOrder($db,$oldPurchase,1,'purchase');
    $oldDraft=$order($parent,'1');$oldManual=$movement($parent,'1');
    $strawberry=inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'Torta de fresa QA']);$chocolate=inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'Torta de chocolate QA']);
    $finish($movement($strawberry,'10'));$finish($movement($chocolate,'10'));
    templateCheck(loadInventoryProductStock($db,$parent)['total']==='20.00','Template must sum 10 strawberry + 10 chocolate.');
    templateCheck(loadInventoryProductStock($db,$parent,true)['total']==='3.00','Legacy stock must remain separate, without duplication.');
    templateReject(fn()=>$movement($parent,'1'),'Manual receipt accepted a template.');
    templateReject(fn()=>$movement($parent,'1','delivery'),'Manual delivery accepted a template.');
    templateReject(fn()=>$order($parent,'1'),'Sales save accepted a template.');
    templateReject(fn()=>$order($parent,'1',true),'Purchase save accepted a template.');
    templateReject(fn()=>transitionSalesQuotation($db,$oldDraft,1,'sale'),'Old draft confirmed a template after variant creation.');
    templateReject(fn()=>transitionInventoryOperation($db,$oldManual,1,'confirm_operation'),'Old unlinked draft confirmed a template.');
    foreach([loadSalesCatalogs($db,null),loadPurchaseCatalogs($db,null)] as $catalog){$ids=array_map('intval',array_column($catalog['products'],'id'));templateCheck(!in_array($parent,$ids,true)&&in_array($strawberry,$ids,true)&&in_array($chocolate,$ids,true),'Order catalogs did not distinguish templates and variants.');}
    $sale=$order($strawberry,'3');transitionSalesQuotation($db,$sale,1,'sale');$delivery=createLinkedStockOperation($db,'sale',$sale);
    $saveQuantity=static function(int $id,string $quantity)use($db):void{$q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$id]);$record=$q->fetch();$data=array_intersect_key($record,array_flip(['operation_type','scheduled_date','contact_id','notes','source_location_id','destination_location_id','source_warehouse_id','destination_warehouse_id']));$lines=loadInventoryOperationLines($db,$id);foreach($lines as &$line)$line['quantity']=$quantity;unset($line);saveInventoryOperation($db,$data,$id,(int)$record['version'],$lines);};
    $saveQuantity($delivery,'3');transitionInventoryOperation($db,$delivery,2,'confirm_operation');transitionInventoryOperation($db,$delivery,3,'check_ready');
    $stock=loadInventoryProductStock($db,$parent);templateCheck($stock['total']==='20.00'&&$stock['reserved']==='3.00'&&$stock['available']==='17.00','Template reservation totals disagree with variants.');
    validateInventoryStockOperation($db,$delivery,4);templateCheck(loadInventoryProductStock($db,$parent)['total']==='17.00'&&loadInventoryProductStock($db,$strawberry)['total']==='7.00','Selling strawberry must reduce only strawberry.');
    $return=createStockReturn($db,$delivery,5);$saveQuantity($return,'1');$finish($return,2);templateCheck(loadInventoryProductStock($db,$parent)['total']==='18.00','Variant return did not update the family total.');
    // Confirmed original orders and real returns retain their concrete historical record.
    $legacyDelivery=createLinkedStockOperation($db,'sale',$oldSale);$saveQuantity($legacyDelivery,'1');$finish($legacyDelivery,2);
    $legacyReceipt=createLinkedStockOperation($db,'purchase',$oldPurchase);$saveQuantity($legacyReceipt,'1');$finish($legacyReceipt,2);
    $legacyReturn=createStockReturn($db,$originalReceipt,4);$saveQuantity($legacyReturn,'1');$finish($legacyReturn,2);
    templateCheck(loadInventoryProductStock($db,$parent,true)['total']==='2.00'&&loadInventoryProductStock($db,$parent)['total']==='18.00','Legacy processing mixed parent and variant inventory.');
    $purchase=$order($chocolate,'2',true);transitionPurchaseOrder($db,$purchase,1,'purchase');$indicators=loadInventoryProductIndicators($db,$parent);
    templateCheck($indicators['sold']==='3.00'&&$indicators['purchased']==='2.00','Template commercial indicators include legacy parent documents or miss variants.');
    foreach([false=>'18.00',true=>'2.00'] as $exact=>$expected){[$where,$args]=transferDomain($db,transferModels()['stock'],['product_id'=>$parent,'exact'=>$exact?'1':'0']);$q=$db->prepare('SELECT COALESCE(SUM(r.quantity),0) FROM inventory_stock r WHERE '.implode(' AND ',$where));$q->execute($args);templateCheck((string)$q->fetchColumn()===$expected,'CSV/report family domain disagrees with the product total.');}
    $finish($movement($simple,'1'));templateCheck(loadInventoryProductStock($db,$simple)['total']==='1.00','A product without variants stopped working.');
    echo "PASS: 10+10 rollup, independent stock, reservations, sales/returns, new-operation guards, confirmed historical documents and simple products.\n";
    $password=bin2hex(random_bytes(12));$login='template.'.bin2hex(random_bytes(4));$setup=prepareFirstAdministrator($db);createFirstAdministrator($db,['name'=>'Template QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'template-cookie-');[, $html]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password]);
    [$code,$html]=authTestRequest($s,'/modules/inventario/index.php?id='.$parent,$cookie);$xpath=templateDocument($html);
    templateCheck($code===200&&trim($xpath->query('//*[@data-product-stat="on-hand"]/*[contains(@class,"product-stat-value")]')->item(0)->textContent)==='18.00'&&str_contains($html,'Existencias anteriores del principal'),'Template form lost aggregate or legacy warning.');
    foreach(['stock','movements','sold','purchased'] as $view){[$code,$html]=authTestRequest($s,'/modules/inventario/informes.php?view='.$view.'&product_id='.$parent,$cookie);templateCheck($code===200&&str_contains($html,'reúne las variantes'),'Family report unavailable.');$xpath=templateDocument($html);templateCheck($xpath->query('//table//a[@href="index.php?id='.$parent.'"]')->length===0,'Family report included the legacy template as a variant.');}
    [$code,$html]=authTestRequest($s,'/modules/inventario/informes.php?view=stock&product_id='.$parent.'&exact=1',$cookie);templateCheck($code===200&&str_contains($html,'sin sumar variantes'),'Original stock cannot be inspected.');
    foreach([['/modules/ventas/index.php?new=1','data-line-product'],['/modules/compras/index.php?new=1','data-order-product'],['/modules/inventario/operaciones.php?type=receipt&new=1','data-operation-product']] as [$path,$attribute]){[$code,$html]=authTestRequest($s,$path,$cookie);$xpath=templateDocument($html);templateCheck($code===200&&$xpath->query('//select[@'.$attribute.']/option[@value="'.$parent.'"]')->length===0&&$xpath->query('//select[@'.$attribute.']/option[@value="'.$strawberry.'"]')->length>0,'New form offered template or omitted variants: '.$path);}
    [$code,$html]=authTestRequest($s,'/modules/ventas/producto.php?id='.$parent,$cookie);templateCheck($code===422,'Sales snapshot accepted a template.');
    echo "PASS: real template form, variant breakdown, family reports, legacy view, filtered sales/purchases/receipt selectors and pricing endpoint.\n";
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_TEMPLATE_ID']=(string)$parent;$env['NEXO_TEMPLATE_VARIANT']=(string)$strawberry;
    $process=proc_open(['node',__DIR__.'/variant-templates-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);templateCheck(proc_close($process)===0,'Template UI review failed.');
}finally{if($cookie&&is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

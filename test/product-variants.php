<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/product-variants-schema.php';
require_once dirname(__DIR__).'/php/inventory-repository.php';
require_once dirname(__DIR__).'/php/inventory-stock-repository.php';
require_once dirname(__DIR__).'/php/company-connection.php';
require_once dirname(__DIR__).'/php/company-repository.php';
require_once dirname(__DIR__).'/php/restaurant-schema.php';
require_once dirname(__DIR__).'/php/restaurant-controller.php';
require_once dirname(__DIR__).'/php/bcv-sync.php';
function variantCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function variantReject(callable $work):void{try{$work();}catch(InvalidArgumentException|PDOException|RuntimeException){return;}throw new RuntimeException('Se aceptó una relación de variante inválida.');}
echo "RUN isolated variants migration\n";$s=startAuthTestServer();$cookies=[];$fixtures=null;
try{
    $raw=$s['db'];$before=(int)$raw->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn();
    echo "RUN first schema application\n";installProductVariants($raw);echo "RUN repeatable schema application\n";installProductVariants($raw);
    variantCheck((int)$raw->query('SELECT COUNT(*) FROM inventory_products')->fetchColumn()===$before,'La migración alteró productos.');
    $password=bin2hex(random_bytes(12));$login='variants.'.bin2hex(random_bytes(5));$setup=prepareFirstAdministrator($raw);
    $actor=createFirstAdministrator($raw,['name'=>'Administrador de variantes','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    installRestaurant($raw);
    $config=require dirname(__DIR__).'/php/config/database.php';$tables=$raw->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    $connect=static fn(array $selected,int $active)=>new CompanyConnection('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$s['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],$selected,$active,$actor,$tables);
    $db=$connect([1],1);$prefix=bin2hex(random_bytes(5));
    $db->prepare('INSERT INTO inventory_categories(name,restaurant_enabled) VALUES(?,1)')->execute(['Pastelería '.$prefix]);$category=(int)$db->lastInsertId();
    $currency=accountingCurrencies($db)['primary_code'];
    $rootData=['name'=>'Torta '.$prefix,'sale_enabled'=>1,'purchase_enabled'=>1,'sale_price'=>'10.00','cost_price'=>'2.00','currency_code'=>$currency,
        'category_id'=>$category,'sale_tax_id'=>null,'purchase_tax_id'=>null,'product_type'=>'goods','invoice_policy'=>'ordered','commission_type'=>'fixed','commission_value'=>'1.0000'];
    $parent=saveInventoryProduct($db,$rootData,null);$otherParent=saveInventoryProduct($db,array_replace($rootData,['name'=>'Otra torta '.$prefix]),null);
    echo "RUN free-name variants, inheritance, duplicate and hierarchy checks\n";
    $chocolate=inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'Torta de chocolate '.$prefix]);
    $strawberry=inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'Torta de fresa '.$prefix,'sale_price'=>'12.50']);
    $q=$db->prepare('SELECT * FROM inventory_products WHERE id=?');$q->execute([$chocolate]);$chocolateRow=$q->fetch();
    variantCheck($chocolateRow['sale_price']==='10.00'&&$chocolateRow['cost_price']==='2.00'&&(int)$chocolateRow['parent_product_id']===$parent&&(int)$chocolateRow['category_id']===$category&&$chocolateRow['commission_type']==='fixed','No se heredaron correctamente los datos del principal.');
    $q->execute([$strawberry]);variantCheck($q->fetch()['sale_price']==='12.50','No se respetó el precio propio de la variante.');
    variantReject(fn()=>inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'Torta de chocolate '.$prefix]));
    variantReject(fn()=>inventoryCreateVariant($db,['parent_product_id'=>$chocolate,'name'=>'Variante anidada']));
    variantReject(fn()=>inventoryCreateVariant($db,['parent_product_id'=>$parent,'name'=>'']));
    variantReject(fn()=>saveInventoryProduct($db,array_replace($rootData,['parent_product_id'=>$parent]),$parent));
    variantReject(fn()=>saveInventoryProduct($db,array_replace($rootData,['parent_product_id'=>$otherParent]),$chocolate));
    $updated=array_replace($rootData,['name'=>$chocolateRow['name'],'sale_price'=>'11.00']);saveInventoryProduct($db,$updated,$chocolate);
    $q->execute([$chocolate]);variantCheck((int)$q->fetch()['parent_product_id']===$parent,'Editar sin indicar principal perdió la relación.');
    variantReject(fn()=>uninstallEmptyProductVariants($raw));
    echo "RUN independent stock and existing restaurant sale/invoice/payment flow\n";
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES(?)')->execute(['Almacén variantes '.$prefix]);$warehouse=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_locations(name,warehouse_id,location_type) VALUES(?,?,'internal')")->execute(['Pastelería '.$prefix,$warehouse]);$location=(int)$db->lastInsertId();
    foreach([$parent=>'5',$chocolate=>'3',$strawberry=>'7'] as $productId=>$stock)$db->prepare('INSERT INTO inventory_stock(product_id,location_id,quantity) VALUES(?,?,?)')->execute([$productId,$location,$stock]);
    $stock=static function(int $id)use($db,$location):string{$q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$q->execute([$id,$location]);return (string)$q->fetchColumn();};
    variantCheck(bccomp($stock($parent),'5',3)===0&&bccomp($stock($chocolate),'3',3)===0&&bccomp($stock($strawberry),'7',3)===0,'Las existencias se mezclaron.');
    bcvApplyPublication($raw,['rate'=>'100.00000000','effective_date'=>commerceToday(),'source'=>'bcv']);
    $db->prepare("INSERT INTO contacts(name,type_id) SELECT ?,id FROM contact_types WHERE system_key='client'")->execute(['Cliente variantes '.$prefix]);$customer=(int)$db->lastInsertId();
    $area=restaurantSaveArea($db,['name'=>'Variantes '.$prefix,'active'=>1]);$table=restaurantSaveTable($db,['area_id'=>$area,'name'=>'Mesa de variantes','capacity'=>4,'active'=>1]);
    $account=restaurantOpenAccount($db,['table_id'=>$table,'table_version'=>1,'waiter_id'=>$actor,'guests'=>1,'request_key'=>bin2hex(random_bytes(32))]);
    restaurantCartCommand($db,$account,['action'=>'pos_add','version'=>1,'product_id'=>$chocolate,'request_key'=>bin2hex(random_bytes(32))]);
    $cash=(int)$db->query("SELECT id FROM accounting_payment_accounts WHERE account_type='cash' AND (currency_code IS NULL OR currency_code=".$db->quote($currency).') LIMIT 1')->fetchColumn();
    $method=(int)$db->query('SELECT id FROM accounting_payment_methods LIMIT 1')->fetchColumn();
    restaurantCheckout($db,$account,['version'=>2,'customer_id'=>$customer,'location_id'=>$location,'account_id'=>$cash,'method_id'=>$method,'request_key'=>bin2hex(random_bytes(32))]);
    variantCheck(bccomp($stock($chocolate),'2',3)===0&&bccomp($stock($parent),'5',3)===0&&bccomp($stock($strawberry),'7',3)===0,'Vender una variante descontó otras existencias.');
    echo "RUN HTTP menus, simple creation, edition and filtering\n";
    $cookie=tempnam(sys_get_temp_dir(),'nexo-variants-cookie-');$cookies[]=$cookie;$r=authTestRequest($s,'/login.php',$cookie);$r=authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($r[1]),'login'=>$login,'password'=>$password]);variantCheck($r[0]===303,'No inicia sesión el administrador.');
    $r=authTestRequest($s,'/modules/inventario/variantes.php?new=1&parent_id='.$parent,$cookie);variantCheck($r[0]===200&&str_contains($r[1],'Nombre de la variante'),'No abre la creación de variantes.');$csrf=authTestToken($r[1]);
    $variantName='Torta de limón '.$prefix;
    $createPost=array_replace($rootData,['csrf'=>$csrf,'action'=>'save_product','parent_product_id'=>$parent,'name'=>$variantName,'sale_price'=>'14.25','cost_price'=>'3.25']);
    $r=authTestRequest($s,'/modules/inventario/variantes.php?new=1',$cookie,$createPost);variantCheck($r[0]===303,'No se crea una variante desde la ficha completa.');
    $q=$db->prepare('SELECT id FROM inventory_products WHERE parent_product_id=? AND name=?');$q->execute([$parent,$variantName]);$lemon=(int)$q->fetchColumn();variantCheck($lemon>0,'No se guardó la variante HTTP.');
    $q=$db->prepare('SELECT sale_price,cost_price FROM inventory_products WHERE id=?');$q->execute([$lemon]);$createdValues=$q->fetch();variantCheck($createdValues['sale_price']==='14.25'&&$createdValues['cost_price']==='3.25','La ficha completa no respeta los valores propios de la variante.');
    $duplicate=authTestRequest($s,'/modules/inventario/variantes.php?new=1',$cookie,$createPost);variantCheck($duplicate[0]===200&&str_contains($duplicate[1],'Ya existe una variante'),'El duplicado no informa un error de validación.');
    $r=authTestRequest($s,'/modules/inventario/variantes.php?parent_id='.$parent,$cookie);variantCheck($r[0]===200&&str_contains($r[1],$variantName),'No aparecen las variantes del principal.');
    $r=authTestRequest($s,'/modules/inventario/index.php?q='.urlencode($prefix),$cookie);variantCheck($r[0]===200&&str_contains($r[1],'Variantes del producto')&&!str_contains($r[1],$variantName),'La lista principal mezcla variantes o no tiene menú.');
    $r=authTestRequest($s,'/modules/inventario/index.php?id='.$parent,$cookie);variantCheck($r[0]===200&&str_contains($r[1],$variantName)&&str_contains($r[1],'Nueva variante'),'El principal no muestra sus variantes.');
    $r=authTestRequest($s,'/modules/inventario/index.php?id='.$lemon,$cookie);variantCheck($r[0]===200&&str_contains($r[1],'Editar variante')&&str_contains($r[1],'Producto principal:'),'La ficha no identifica a su principal.');
    $r=authTestRequest($s,'/modules/inventario/index.php?id='.$lemon,$cookie,['csrf'=>$csrf,'action'=>'save_product','name'=>$variantName,'sale_enabled'=>'1','purchase_enabled'=>'1','sale_price'=>'13.00','currency_code'=>$currency,'category_id'=>$category,'product_type'=>'goods','invoice_policy'=>'ordered']);variantCheck($r[0]===303&&str_contains($r[2],'variantes.php?parent_id='.$parent),'Editar variante no vuelve al listado correspondiente.');
    echo "RUN cross-company parent protection and read-only permissions\n";
    $otherCompany=createBusinessCompany($raw,['name'=>'Empresa variantes '.$prefix],$actor);$other=$connect([$otherCompany],$otherCompany);
    echo "RUN foreign company created\n";
    $foreignParent=saveInventoryProduct($other,array_replace($rootData,['category_id'=>null,'name'=>'Principal ajeno '.$prefix]),null);
    $selected=$connect([1,$otherCompany],1);variantReject(fn()=>inventoryCreateVariant($selected,['parent_product_id'=>$foreignParent,'name'=>'Cruce']));
    echo "RUN foreign parent rejected\n";
    $raw->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Lector variantes '.$prefix,'Solo consultar']);$group=(int)$raw->lastInsertId();
    $raw->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'inventario.products.read','all')")->execute([$group]);
    $readerLogin='variants.reader.'.bin2hex(random_bytes(4));$raw->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Lector de variantes',$readerLogin,password_hash($password,PASSWORD_DEFAULT)]);$reader=(int)$raw->lastInsertId();
    echo "RUN reader created\n";
    $raw->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$reader,$group]);$raw->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$reader]);
    $limited=tempnam(sys_get_temp_dir(),'nexo-variants-reader-');$cookies[]=$limited;$r=authTestRequest($s,'/login.php',$limited);$r=authTestRequest($s,'/login.php',$limited,['csrf'=>authTestToken($r[1]),'login'=>$readerLogin,'password'=>$password]);variantCheck($r[0]===303,'No inicia sesión el lector.');
    $r=authTestRequest($s,'/modules/inventario/variantes.php',$limited);variantCheck($r[0]===200&&!str_contains($r[1],'href="variantes.php?new=1'),'Se ofrece crear variantes al lector.');
    variantCheck(authTestRequest($s,'/modules/inventario/variantes.php?new=1',$limited)[0]===403,'El lector accedió al formulario de creación.');
    $raw->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'inventario.products.create','all')")->execute([$group]);
    $creatorCookie=tempnam(sys_get_temp_dir(),'nexo-variants-creator-');$cookies[]=$creatorCookie;
    $r=authTestRequest($s,'/login.php',$creatorCookie);$r=authTestRequest($s,'/login.php',$creatorCookie,['csrf'=>authTestToken($r[1]),'login'=>$readerLogin,'password'=>$password]);variantCheck($r[0]===303,'No inicia sesión el creador limitado.');
    $r=authTestRequest($s,'/modules/inventario/variantes.php?new=1&parent_id='.$parent,$creatorCookie);variantCheck($r[0]===200&&!str_contains($r[1],'name="cost_price"'),'La ficha expone costos a un usuario sin permiso.');
    $restrictedPost=array_replace($rootData,['csrf'=>authTestToken($r[1]),'action'=>'save_product','parent_product_id'=>$parent,'name'=>'Variante limitada '.$prefix]);
    unset($restrictedPost['cost_price'],$restrictedPost['commission_type'],$restrictedPost['commission_value']);
    $r=authTestRequest($s,'/modules/inventario/variantes.php?new=1',$creatorCookie,$restrictedPost);variantCheck($r[0]===303,'No crea una variante sin acceso a costos o comisión.');
    $q=$db->prepare('SELECT cost_price,commission_type,commission_value FROM inventory_products WHERE name=? AND parent_product_id=?');$q->execute(['Variante limitada '.$prefix,$parent]);$restricted=$q->fetch();
    variantCheck($restricted['cost_price']==='2.00'&&$restricted['commission_type']==='fixed'&&$restricted['commission_value']==='1.0000','No se conservaron los datos protegidos del principal.');
    if(isset($argv[1])){
        $fixtures=sys_get_temp_dir().'/nexo-variant-fixtures-'.bin2hex(random_bytes(5));mkdir($fixtures,0700);
        $css=preg_replace('/^@import[^\r\n]+[\r\n]*/m','',file_get_contents(dirname(__DIR__).'/css/app.css')).file_get_contents(dirname(__DIR__).'/css/modules.css');
        $js=file_get_contents(dirname(__DIR__).'/js/app.js')."\n".file_get_contents(dirname(__DIR__).'/js/modules.js')."\n".file_get_contents(dirname(__DIR__).'/js/navigation.js')."\n".file_get_contents(dirname(__DIR__).'/js/product-commissions.js');
        foreach(['list'=>'/modules/inventario/variantes.php?parent_id='.$parent,'new'=>'/modules/inventario/variantes.php?new=1&parent_id='.$parent,'parent'=>'/modules/inventario/index.php?id='.$parent,'variant'=>'/modules/inventario/index.php?id='.$lemon] as $name=>$route){
            $r=authTestRequest($s,$route,$cookie);variantCheck($r[0]===200,'No renderiza '.$name);
            $html=preg_replace('~<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>|<link\b[^>]*rel="stylesheet"[^>]*>~i','',$r[1]);$html=str_replace('</head>','<style>'.$css.'</style></head>',$html);$html=str_replace('</body>','<script>'.$js.'</script></body>',$html);file_put_contents($fixtures.'/'.$name.'.html',$html);
        }
        $process=proc_open(['node',__DIR__.'/product-variants-ui.js',$fixtures,$argv[1]],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);variantCheck(proc_close($process)===0,'Falló la revisión visual de variantes.');
    }
    echo "PASS variants: migration, names, defaults, duplicate/hierarchy guards, independent stock, sale/invoice/payment, HTTP, companies and permissions.\n";
}finally{
    foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);
    if($fixtures){foreach(glob($fixtures.'/*.html') as $file)unlink($file);rmdir($fixtures);}stopAuthTestServer($s);
}

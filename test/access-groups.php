<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/access-schema.php';
require_once dirname(__DIR__).'/php/access-repository.php';

function accessCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function accessReject(callable $action): void {
    try{$action();}catch(InvalidArgumentException|PDOException){return;}
    throw new RuntimeException('Se aceptó una definición de acceso inválida.');
}
function accessInput(array $permissions,string $name): array {
    $scopes=[];$catalog=accessPermissionCatalog();
    foreach($permissions as $code=>$scope)$scopes[$catalog[$code]['resource']]=$scope;
    return ['name'=>$name,'description'=>'Prueba de permisos','active'=>'1','permissions'=>array_keys($permissions),'scopes'=>$scopes];
}
function accessRequest(string $path,string $cookie,?array $post=null,string $method='GET'): array {
    $curl=curl_init('http://localhost/project/modules/ajustes/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    elseif($method!=='GET')curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $html=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$location=curl_getinfo($curl,CURLINFO_REDIRECT_URL);curl_close($curl);
    accessCheck(is_string($html)&&!preg_match('/Fatal error|Warning:|Notice:/',$html),'Error en '.$path);
    return [$status,$html,$location];
}

$db=createDatabaseConnection();$testDatabase='nexo_access_test_'.bin2hex(random_bytes(8));
accessCheck((bool)preg_match('/^nexo_access_test_[a-f0-9]{16}$/D',$testDatabase),'Nombre de prueba no válido.');
$db->exec('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4');
try {
    $db->exec('USE `'.$testDatabase.'`');installAccessGroups($db);installAccessGroups($db);
    accessCheck((int)$db->query('SELECT COUNT(*) FROM access_groups')->fetchColumn()===6,'No se instalan seis grupos iniciales sin duplicarlos.');
    accessCheck((int)$db->query('SELECT COUNT(*) FROM access_permissions')->fetchColumn()===count(accessPermissionCatalog()),'El catálogo no coincide con los permisos instalados.');
    foreach(accessInitialGroups() as $key=>$initial)validateAccessGroup(accessInput($initial['permissions'],$initial['name']));
    $ids=$db->query('SELECT system_key,id FROM access_groups')->fetchAll(PDO::FETCH_KEY_PAIR);
    $sales=resolveAccessGroupPermissions($db,[$ids['sales']]);
    accessCheck(accessPermissionAllows($sales,'ventas.orders.confirm',21,21)&&!accessPermissionAllows($sales,'ventas.orders.confirm',22,21),'Ventas propias no respeta el vendedor asignado.');
    accessCheck(!accessPermissionAllows($sales,'ventas.orders.read',null,21)&&!accessPermissionAllows($sales,'ventas.orders.read',21,null),'Registros sin vendedor o usuario relacionado reciben acceso propio.');
    accessCheck(!accessPermissionAllows($sales,'ventas.commissions.pay',21,21)&&!accessPermissionAllows($sales,'inventario.deliveries.validate',21,21),'Vender concede pagar o validar inventario.');
    accessCheck(!accessPermissionAllows($sales,'contabilidad.expense.create')&&!accessPermissionAllows($sales,'unknown.permission'),'Se concede un permiso no autorizado.');
    accessCheck(!resolveAccessGroupPermissions($db,[])&&!accessPermissionAllows([],'ventas.orders.read',21,21),'Sin grupos se concede acceso.');
    $reader=saveAccessGroup($db,accessInput(['ventas.orders.read'=>'all'],'Lectura de todas'),null,null);
    $combined=resolveAccessGroupPermissions($db,[$ids['sales'],$reader]);
    accessCheck(accessPermissionScope($combined,'ventas.orders.read')==='all'&&accessPermissionScope($combined,'ventas.orders.edit')==='own','Consultar todo amplía también la edición de otros vendedores.');
    accessCheck(accessPermissionAllows($combined,'ventas.orders.read',22,21)&&!accessPermissionAllows($combined,'ventas.orders.edit',22,21),'La combinación mezcla alcances de acciones distintas.');
    $readerRecord=loadAccessGroup($db,$reader);$readerInput=accessInput($readerRecord['permissions'],'Lectura de todas');$readerInput['active']='0';
    saveAccessGroup($db,$readerInput,$reader,1);
    accessCheck(accessPermissionScope(resolveAccessGroupPermissions($db,[$ids['sales'],$reader]),'ventas.orders.read')==='own','Un grupo archivado sigue concediendo acceso.');
    $wrongRead=['ventas.orders.read'=>'own','ventas.orders.edit'=>'all'];
    accessCheck(accessPermissionScope($wrongRead,'ventas.orders.edit')==='own','Editar permite superar el alcance de consulta.');
    $input=accessInput(['ventas.orders.read'=>'own','ventas.orders.edit'=>'own'],'Grupo propio');$key=bin2hex(random_bytes(32));
    $id=saveAccessGroup($db,$input,null,null,$key);
    accessCheck(saveAccessGroup($db,$input,null,null,$key)===$id,'Reenviar creación duplica grupos.');
    $snapshot=loadAccessGroup($db,$id);
    foreach([
        ['name'=>''],['name'=>[]],['description'=>[]],['active'=>[]],['permissions'=>['unknown.code']],
        ['permissions'=>['ventas.orders.read','ventas.orders.read']],['permissions'=>['ventas.orders.edit']],
        ['permissions'=>'ventas.orders.read'],['scopes'=>[]],['scopes'=>['ventas.orders'=>'invalid']],
        ['scopes'=>['inventario.products'=>'own']],['scopes'=>['nonexistent'=>'all']],
    ] as $invalid)accessReject(fn()=>saveAccessGroup($db,array_replace($input,$invalid),$id,1));
    accessReject(fn()=>saveAccessGroup($db,$input,$id,0));accessReject(fn()=>saveAccessGroup($db,$input,$id,99));
    accessReject(fn()=>saveAccessGroup($db,$input,null,null));
    accessCheck(loadAccessGroup($db,$id)===$snapshot,'Una validación alteró el grupo o sus permisos.');
    $input['description']='Nueva descripción';saveAccessGroup($db,$input,$id,1);
    accessReject(fn()=>saveAccessGroup($db,array_replace($input,['permissions'=>[]]),$id,1));
    accessCheck(count(loadAccessGroup($db,$id)['permissions'])===2,'Un conflicto borró permisos.');
    $admin=loadAccessGroup($db,(int)$ids['administrator']);$adminInput=accessInput($admin['permissions'],$admin['name']);
    accessReject(fn()=>saveAccessGroup($db,array_replace($adminInput,['active'=>'0']),(int)$admin['id'],1));
    accessReject(fn()=>saveAccessGroup($db,array_replace($adminInput,['permissions'=>[]]),(int)$admin['id'],1));
    accessCheck(loadAccessGroup($db,(int)$admin['id'])===$admin,'Se perdió la administración de grupos.');
    $salesGroup=loadAccessGroup($db,(int)$ids['sales']);
    $renamed=accessInput($salesGroup['permissions'],'Ventas modificadas');$renamed['active']='0';unset($renamed['permissions'][array_search('ventas.orders.confirm',$renamed['permissions'],true)]);$renamed['permissions']=array_values($renamed['permissions']);
    saveAccessGroup($db,$renamed,(int)$salesGroup['id'],1);$custom=loadAccessGroup($db,(int)$salesGroup['id']);
    installAccessGroups($db);accessCheck(loadAccessGroup($db,(int)$salesGroup['id'])===$custom,'Reinstalar restaura nombre, estado o permisos personalizados.');
    accessCheck(!accessPermissionAllows(resolveAccessGroupPermissions($db,[$salesGroup['id']]),'ventas.orders.confirm',21,21),'Un grupo inactivo concede confirmación.');
} finally {$db->exec('USE information_schema');$db->exec('DROP DATABASE `'.$testDatabase.'`');}

$live=createDatabaseConnection();$prefix='Test permisos '.bin2hex(random_bytes(8));$fixtures=[];$cookie=tempnam(sys_get_temp_dir(),'access-');
try {
    [$status,$html]=accessRequest('grupos.php',$cookie);accessCheck($status===200&&str_contains($html,'<table')&&!str_contains($html,'data-access-group-form'),'Listado y formulario se muestran juntos.');
    [$status,$html]=accessRequest('grupos.php?new=1',$cookie);accessCheck($status===200&&str_contains($html,'data-access-group-form'),'No se abre el formulario de grupos.');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$csrf);preg_match('/name="creation_key" value="([a-f0-9]+)"/',$html,$key);
    accessCheck(!empty($csrf[1])&&!empty($key[1]),'El formulario no tiene protección de creación.');
    $input=accessInput(['ventas.orders.read'=>'own','ventas.orders.edit'=>'own'],$prefix.' <script>');
    $post=$input+['csrf'=>$csrf[1],'creation_key'=>$key[1],'version'=>'0','action'=>'save_group'];
    [$status]=accessRequest('grupos.php?new=1',$cookie,array_replace($post,['csrf'=>'wrong']));accessCheck($status===403,'Se acepta CSRF inválido.');
    [$status]=accessRequest('grupos.php?new=1',$cookie,array_replace($post,['creation_key'=>bin2hex(random_bytes(32))]));accessCheck($status===403,'Se acepta una creación sin clave de sesión.');
    [$status,$html,$location]=accessRequest('grupos.php?new=1',$cookie,$post);
    accessCheck($status===303&&preg_match('/id=(\d+)/',$location,$match),'No se crea el grupo: '.strip_tags($html));$id=(int)$match[1];$fixtures[]=$id;
    [$status,,$repeated]=accessRequest('grupos.php?new=1',$cookie,$post);accessCheck($status===303&&$repeated===$location,'Un reenvío HTTP duplica el grupo.');
    [$status,$html]=accessRequest('grupos.php?id='.$id,$cookie);
    accessCheck($status===200&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,$prefix.' <script>'),'El nombre del grupo no se presenta como texto seguro.');
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
    accessCheck($xpath->query('//*[@data-notebook-page]')->length===7,'No se organizan los permisos por módulo.');
    accessCheck($xpath->query('//input[@value="ventas.orders.edit" and @checked]')->length===1&&$xpath->query('//select[@name="scopes[ventas.orders]"]/option[@value="own" and @selected]')->length===1,'No se conserva la matriz de permisos propios.');
    $saved=loadAccessGroup($live,$id);
    [$status]=accessRequest('grupos.php?id='.$id,$cookie,array_replace($post,['version'=>'999']));accessCheck($status===409&&loadAccessGroup($live,$id)===$saved,'Se sobrescribe un grupo con versión antigua.');
    if(in_array('--browser-review',$argv,true)){echo 'REVIEW '.json_encode(['group'=>$id])."\n";fgets(STDIN);}
    $archive=array_replace($post,['version'=>'1','active'=>'0']);
    [$status]=accessRequest('grupos.php?id='.$id,$cookie,$archive);accessCheck($status===303&&!loadAccessGroup($live,$id)['active'],'No se archiva el grupo.');
    [$status,$html]=accessRequest('grupos.php?active=inactive&q='.urlencode($prefix),$cookie);accessCheck($status===200&&str_contains($html,$prefix),'El filtro no muestra el grupo archivado.');
    [$status,$html]=accessRequest('grupos.php?active=active&q='.urlencode($prefix),$cookie);accessCheck($status===200&&!str_contains($html,'href="grupos.php?id='.$id.'"'),'El filtro activo muestra un grupo archivado.');
    foreach(['grupos.php?id[]=1','grupos.php?id=0','grupos.php?id=999999999','grupos.php?new[]=1','grupos.php?page[]=1'] as $path){[$status]=accessRequest($path,$cookie);accessCheck($status===404,'Se acepta ruta inválida '.$path);}
    [$status]=accessRequest('grupos.php',$cookie,[]);accessCheck($status===403,'El listado acepta un POST sin CSRF.');
    [$status]=accessRequest('grupos.php',$cookie,null,'DELETE');accessCheck($status===405,'Se permite eliminar grupos por método HTTP.');
    echo "PASS: grupos, permisos por acción, alcances propios/todos, combinación, denegación, administrador, migración, CSRF, duplicados, conflictos y filtros.\n";
} finally {
    foreach($fixtures as $id) {
        $query=$live->prepare('SELECT name FROM access_groups WHERE id=?');$query->execute([$id]);$name=$query->fetchColumn();
        if(is_string($name)&&str_starts_with($name,$prefix)) {$live->prepare('DELETE FROM access_group_permissions WHERE group_id=?')->execute([$id]);$live->prepare('DELETE FROM access_groups WHERE id=? AND name=?')->execute([$id,$name]);}
    }
    unlink($cookie);
}

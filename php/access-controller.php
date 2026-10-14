<?php
declare(strict_types=1);
require_once __DIR__.'/settings-controller.php';
require_once __DIR__.'/access-repository.php';

function renderAccessGroups(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    $_SESSION['settings_csrf']??=bin2hex(random_bytes(32));
    $page=['area'=>'groups','available'=>true,'errors'=>[],'databaseStatus'=>getDatabaseStatus(),
        'csrf'=>$_SESSION['settings_csrf'],'flash'=>$_SESSION['settings_flash']??null,'view'=>'list',
        'record'=>['name'=>'','description'=>'','active'=>1,'version'=>0,'system_key'=>null,'permissions'=>[]]];
    unset($_SESSION['settings_flash']);
    try {
        $method=$_SERVER['REQUEST_METHOD'];
        if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);throw new InvalidArgumentException('El método solicitado no está disponible.');}
        $id=accessId($_GET['id']??null);
        if((isset($_GET['id'])&&!$id)||($id&&isset($_GET['new']))||
            (isset($_GET['new'])&&$_GET['new']!=='1')||(isset($_GET['page'])&&!accessId($_GET['page']))) {
            http_response_code(404);throw new InvalidArgumentException('El grupo solicitado no existe.');
        }
        $db=createDatabaseConnection();$page['view']=$id||isset($_GET['new'])?'form':'list';
        if($id) {$page['record']=loadAccessGroup($db,$id);if(!$page['record']){http_response_code(404);throw new InvalidArgumentException('El grupo solicitado no existe.');}}
        $page['scopes']=[];
        foreach($page['record']['permissions'] as $code=>$scope) {
            $definition=accessPermissionCatalog()[$code]??null;
            if($definition)$page['scopes'][$definition['resource']]=$scope;
        }
        if($page['view']==='form'&&!$id) {
            $key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));
            if($method!=='POST') {
                $_SESSION['access_creation_keys'][$key]=true;
                $_SESSION['access_creation_keys']=array_slice($_SESSION['access_creation_keys'],-50,null,true);
            }
            $page['creationKey']=$key;
        }
        if($method==='POST') {
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])) {
                http_response_code(403);$page['errors'][]='La sesión del formulario no es válida. Recarga la página.';
            } elseif($page['view']!=='form'||($_POST['action']??null)!=='save_group') {
                http_response_code(400);$page['errors'][]='La operación solicitada no es válida.';
            } elseif(!$id&&!isset($_SESSION['access_creation_keys'][$key])) {
                http_response_code(403);$page['errors'][]='La solicitud de creación no es válida. Recarga la página.';
            } else {
                try {
                    $saved=saveAccessGroup($db,$_POST,$id,accessId($_POST['version']??null),$id?null:$key);
                    $_SESSION['settings_flash']='Grupo de acceso guardado.';
                    header('Location: grupos.php?id='.$saved,true,303);return;
                } catch(InvalidArgumentException $exception) {
                    if($exception->getCode()===409)http_response_code(409);
                    $page['errors'][]=$exception->getMessage();
                    foreach(['name','description','version'] as $field)if(is_string($_POST[$field]??null))$page['record'][$field]=$_POST[$field];
                    $page['record']['active']=($_POST['active']??null)==='1'?1:0;
                    $selected=is_array($_POST['permissions']??null)?$_POST['permissions']:[];$catalog=accessPermissionCatalog();
                    $page['record']['permissions']=[];
                    foreach($selected as $code)if(is_string($code)&&isset($catalog[$code]))$page['record']['permissions'][$code]='all';
                    $page['scopes']=[];
                    foreach(is_array($_POST['scopes']??null)?$_POST['scopes']:[] as $resource=>$scope) {
                        if(isset(accessResources()[$resource])&&in_array($scope,['own','all'],true))$page['scopes'][$resource]=$scope;
                    }
                }
            }
        }
        if($page['view']==='list') {
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,120):'';
            $page['filter']=is_string($_GET['active']??null)&&in_array($_GET['active'],['all','active','inactive'],true)?$_GET['active']:'all';
            $where=' WHERE (g.name LIKE ? OR g.description LIKE ?)';$params=array_fill(0,2,'%'.$page['search'].'%');
            if($page['filter']!=='all'){$where.=' AND g.active=?';$params[]=$page['filter']==='active'?1:0;}
            $query=$db->prepare('SELECT COUNT(*) FROM access_groups g'.$where);$query->execute($params);$page['total']=(int)$query->fetchColumn();
            $page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(accessId($_GET['page']??null)??1,$page['pages']);
            $query=$db->prepare('SELECT g.*,(SELECT COUNT(*) FROM access_group_permissions p WHERE p.group_id=g.id) AS permission_count
                FROM access_groups g'.$where.' ORDER BY g.name,g.id LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));
            $query->execute($params);$page['rows']=$query->fetchAll();
        }
    } catch(InvalidArgumentException $exception) {$page['available']=false;$page['errors'][]=$exception->getMessage();}
    catch(Throwable $exception) {
        error_log('Grupos de acceso: '.$exception->getMessage());http_response_code(503);$page['available']=false;
        $page['errors'][]='No se pudieron cargar los grupos de acceso. Verifica la instalación de Ajustes.';
    }
    renderModulePage('ajustes',false,$page);
}

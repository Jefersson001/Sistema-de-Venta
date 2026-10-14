<?php
declare(strict_types=1);
require_once __DIR__.'/settings-controller.php';
require_once __DIR__.'/auth.php';

function renderAuthUsers(): void
{
    $actor=authRequireStageAdministrator();$_SESSION['settings_csrf']??=bin2hex(random_bytes(32));
    $page=['area'=>'users','available'=>true,'errors'=>[],'databaseStatus'=>getDatabaseStatus(),'csrf'=>$_SESSION['settings_csrf'],
        'flash'=>$_SESSION['settings_flash']??null,'view'=>'list','record'=>['name'=>'','login'=>'','email'=>'','contact_id'=>null,
        'active'=>1,'version'=>0,'must_change_password'=>1,'last_login_at'=>null,'group_ids'=>[]]];unset($_SESSION['settings_flash']);
    try {
        $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);throw new InvalidArgumentException('El método solicitado no está disponible.');}
        $id=accessId($_GET['id']??null);
        if((isset($_GET['id'])&&!$id)||($id&&isset($_GET['new']))||(isset($_GET['new'])&&$_GET['new']!=='1')||(isset($_GET['page'])&&!accessId($_GET['page']))) {
            http_response_code(404);throw new InvalidArgumentException('El usuario solicitado no existe.');
        }
        $db=createDatabaseConnection();$page['view']=$id||isset($_GET['new'])?'form':'list';
        if($id){$page['record']=loadAuthUser($db,$id);if(!$page['record']){http_response_code(404);throw new InvalidArgumentException('El usuario solicitado no existe.');}}
        if($page['view']==='form'&&!$id) {
            $key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:bin2hex(random_bytes(32));
            if($method!=='POST'){$_SESSION['user_creation_keys'][$key]=true;$_SESSION['user_creation_keys']=array_slice($_SESSION['user_creation_keys'],-50,null,true);}
            $page['creationKey']=$key;
        }
        if($method==='POST') {
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='La sesión del formulario no es válida. Recarga la página.';}
            elseif($page['view']!=='form') {http_response_code(400);$page['errors'][]='La operación no es válida.';}
            else {
                try {
                    $action=$_POST['action']??null;
                    if($action==='save_user') {
                        if(!$id&&!isset($_SESSION['user_creation_keys'][$key]))throw new InvalidArgumentException('La solicitud de creación no es válida.',403);
                        $saved=saveAuthUser($db,$_POST,$id,accessId($_POST['version']??null),(int)$actor['id'],$id?null:$key);
                        $_SESSION['settings_flash']='Usuario guardado.';
                    } elseif($action==='reset_password'&&$id) {
                        resetAuthUserPassword($db,$id,accessId($_POST['version']??null)??0,(int)$actor['id'],$_POST['password']??null,$_POST['password_confirmation']??null);
                        $saved=$id;$_SESSION['settings_flash']='Contraseña temporal restablecida. El usuario deberá cambiarla al entrar.';
                    } else throw new InvalidArgumentException('La operación no es válida.',400);
                    if($saved===(int)$actor['id']&&(int)loadAuthUser($db,$saved)['auth_version']!==(int)$actor['auth_version']) {
                        authForgetSession();header('Location: '.authBasePath().'/login.php',true,303);return;
                    }
                    header('Location: usuarios.php?id='.$saved,true,303);return;
                }catch(InvalidArgumentException $exception) {
                    if(in_array($exception->getCode(),[400,403,409],true))http_response_code($exception->getCode());$page['errors'][]=$exception->getMessage();
                    if(($_POST['action']??null)==='save_user') {
                        foreach(['name','login','email'] as $field)if(is_string($_POST[$field]??null))$page['record'][$field]=$_POST[$field];
                        $page['record']['active']=($_POST['active']??null)==='1'?1:0;
                        $page['record']['contact_id']=accessId($_POST['contact_id']??null);
                        $page['record']['group_ids']=array_values(array_filter(array_map('accessId',is_array($_POST['groups']??null)?$_POST['groups']:[])));
                    }
                    $page['reopenPassword']=($_POST['action']??null)==='reset_password';
                }
            }
        }
        if($page['view']==='form') {
            $page['groups']=$db->query('SELECT id,name,description,active FROM access_groups ORDER BY name,id')->fetchAll();
            $query=$db->prepare('SELECT c.id,c.name,c.active,t.name AS type_name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.active=1 OR c.id=? ORDER BY c.name,c.id');
            $query->execute([$page['record']['contact_id']]);$page['contacts']=$query->fetchAll();
        } else {
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,120):'';
            $page['filter']=is_string($_GET['active']??null)&&in_array($_GET['active'],['all','active','inactive'],true)?$_GET['active']:'all';
            $where=' WHERE (u.name LIKE ? OR u.login LIKE ? OR u.email LIKE ?)';$params=array_fill(0,3,'%'.$page['search'].'%');
            if($page['filter']!=='all'){$where.=' AND u.active=?';$params[]=$page['filter']==='active'?1:0;}
            $query=$db->prepare('SELECT COUNT(*) FROM auth_users u'.$where);$query->execute($params);$page['total']=(int)$query->fetchColumn();
            $page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(accessId($_GET['page']??null)??1,$page['pages']);
            $query=$db->prepare("SELECT u.id,u.name,u.login,u.email,u.active,u.must_change_password,
                (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') FROM auth_user_groups ug JOIN access_groups g ON g.id=ug.group_id WHERE ug.user_id=u.id) AS group_names
                FROM auth_users u".$where.' ORDER BY u.name,u.id LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));
            $query->execute($params);$page['rows']=$query->fetchAll();
        }
    }catch(InvalidArgumentException $exception){$page['available']=false;$page['errors'][]=$exception->getMessage();}
    catch(Throwable $exception){error_log('Usuarios: '.$exception->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudieron cargar los usuarios. Verifica la instalación de Ajustes.';}
    renderModulePage('ajustes',false,$page);
}

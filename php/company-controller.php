<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/company-repository.php';
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/settings-controller.php';

function renderCompanies(): void
{
    $GLOBALS['permission_runtime']=true;$user=authRequireLogin();$GLOBALS['permission_user']=$user;
    permissionRequire('ajustes.company');
    $db=createDatabaseConnection(true);$db->exec('SET @nexo_actor_id='.(int)$user['id']);
    $id=accessId($_GET['id']??null);$new=isset($_GET['new']);
    if(isset($_GET['id'])&&!$id)authStop(404,'La empresa no está disponible.');
    $page=['area'=>'company','available'=>true,'errors'=>[],'databaseStatus'=>getDatabaseStatus(),
        'csrf'=>$_SESSION['auth_csrf'],'view'=>$new||$id?'form':'list','record'=>[],
        'rows'=>companyAvailable($db,$user),'administrator'=>(bool)$user['administrator'],
        'flash'=>$_SESSION['settings_flash']??null,'impact'=>[],'companyUsers'=>[],'userRows'=>[],'userContacts'=>[],'contacts'=>[]];
    unset($_SESSION['settings_flash']);
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){header('Allow: GET, POST');authStop(405,'Método no admitido.');}
    if($new&&!$user['administrator'])authStop(403,'Solo un Administrador puede crear empresas.');
    if($id&&!in_array($id,array_map('intval',array_column($page['rows'],'id')),true))authStop(404,'La empresa no está disponible.');
    try {
        $page['record']=$id?loadBusinessCompany($db,$id):array_fill_keys(['name','legal_name','tax_id','address','phone','email','website'],'');
        if($_SERVER['REQUEST_METHOD']==='POST') {
            if(!authCheckCsrf($_POST['csrf']??null))authStop(403,'La sesión del formulario no es válida.');
            if(!hash_equals(companyScopeToken(),is_string($_POST['company_scope']??null)?$_POST['company_scope']:(string)($_SERVER['HTTP_X_NEXO_COMPANY_SCOPE']??'')))authStop(409,'La selección de empresas cambió. Recarga antes de guardar.');
            $action=$_POST['action']??'';$version=accessId($_POST['version']??null)??0;
            if($action==='save_company') {
                permissionRequire('ajustes.company','edit');
                if($new){$id=createBusinessCompany($db,$_POST,(int)$user['id']);}
                elseif($id)saveBusinessCompany($db,$_POST,$version,companyLogoUpload($_FILES),($_POST['remove_logo']??null)==='1',$id);
                else throw new InvalidArgumentException('Selecciona una empresa.');
                $_SESSION['settings_flash']='Empresa guardada.';
            } elseif($action==='save_company_users'&&$id) {
                if(!is_array($_POST['users']??[]))throw new InvalidArgumentException('Selección de usuarios inválida.');
                if(!is_array($_POST['company_contacts']??[]))throw new InvalidArgumentException('Contactos de usuario inválidos.');
                saveCompanyUsers($db,$id,$_POST['users']??[],$version,(int)$user['id'],$_POST['company_contacts']??[]);$_SESSION['settings_flash']='Accesos de empresa guardados.';
            } elseif($action==='delete_company'&&$id) {
                deleteBusinessCompany($db,$id,$version,is_string($_POST['confirmation']??null)?$_POST['confirmation']:'',is_string($_POST['impact_hash']??null)?$_POST['impact_hash']:'',(int)$user['id']);
                $_SESSION['settings_flash']='Empresa y sus registros eliminados.';
                header('Location: empresa.php',true,303);return;
            } else throw new InvalidArgumentException('Acción no válida.');
            header('Location: empresa.php?id='.$id,true,303);return;
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,409],true)?$e->getCode():200);$page['errors'][]=$e->getMessage();if(($_POST['action']??'')==='save_company')$page['record']=array_filter($_POST,'is_string')+$page['record'];}
    catch(Throwable $e){error_log('Empresas: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo completar la operación de empresa. No se confirmaron cambios.';}
    if($id&&$user['administrator']) {
        $page['impact']=companyImpact($db,$id);
        $q=$db->prepare('SELECT user_id FROM company_user_access WHERE company_id=?');$q->execute([$id]);$page['companyUsers']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $q=$db->prepare('SELECT user_id,contact_id FROM company_user_access WHERE company_id=?');$q->execute([$id]);$page['userContacts']=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.company_id=? AND t.system_key='salesperson' ORDER BY c.name,c.id");$q->execute([$id]);$page['contacts']=$q->fetchAll();
        $page['userRows']=$db->query('SELECT id,name,login,active FROM auth_users ORDER BY name,id')->fetchAll();
    }
    renderModulePage('ajustes',false,$page);
}

function switchCompanies(): void
{
    $user=authRequireLogin();
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');authStop(405,'Usa el selector de empresas.');}
    if(!authCheckCsrf($_POST['csrf']??null))authStop(403,'La sesión del formulario no es válida.');
    $selection=$_POST['companies']??null;$active=$_POST['primary_company']??$_POST['active_company']??null;
    if(isset($_POST['primary_company'])&&is_array($selection))$selection[]=$active;
    if(!isset($_POST['primary_company'])&&is_array($selection)&&$selection&&($requestedActive=accessId($active))&&!in_array($requestedActive,array_map('intval',$selection),true)) {
        $allowed=array_map('intval',array_column($GLOBALS['company_context']['companies'],'id'));
        if(in_array($requestedActive,$allowed,true))$active=reset($selection);
    }
    try {companySelect(createDatabaseConnection(true),$user,$selection,$active);}
    catch(InvalidArgumentException $e){authStop($e->getCode()===403?403:422,$e->getMessage());}
    // Returning to a fresh list avoids carrying an ID from the previous company.
    $next=authSafeNext($_POST['next']??null);$path=parse_url($next,PHP_URL_PATH);
    if(str_contains($path??'','/modules/')){
        $filename=basename($path);
        if(!in_array($filename,['index.php','configuracion.php','comisiones.php','pagos-comisiones.php','informes.php','ingresos-egresos.php','pagos.php','tesoreria.php'],true))$next=authBasePath().'/index.php';
        else {parse_str(parse_url($next,PHP_URL_QUERY)??'',$query);$query=array_intersect_key($query,array_flip(['type','kind','view','section']));$next=$path.($query?'?'.http_build_query($query):'');}
    }
    header('Location: '.$next,true,303);
}

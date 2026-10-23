<?php
declare(strict_types=1);
require_once __DIR__.'/transfer-service.php';
require_once __DIR__.'/database-status.php';

function renderTransfer(): void
{
    $GLOBALS['permission_runtime']=true;$user=authRequireLogin();$GLOBALS['permission_user']=$user;
    $key=$_GET['model']??'';$action=$_GET['action']??'export';
    if(!is_string($key)||!isset(transferModels()[$key])||!is_string($action)||!in_array($action,['template','export','import'],true))authStop(404,'El modelo o la operación no existe.');
    $model=transferModels()[$key];
    $source=authSafeNext($_GET['source']??null);$sourceModel=transferRouteModel($source);
    if(isset($_GET['source'])&&(!$sourceModel||$sourceModel['key']!==$key))authStop(400,'La vista de origen no corresponde al modelo.');
    if($sourceModel&&$sourceModel['key']===$key)$model=$sourceModel;
    if(!permissionHas($model['resource']))authStop(403,'No tienes acceso a este modelo.');
    parse_str(parse_url($source,PHP_URL_QUERY)??'',$filters);$errors=[];$preview=null;$success=null;
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){header('Allow: GET, POST');authStop(405,'Método no admitido.');}
    if($_SERVER['REQUEST_METHOD']==='POST'&&(!authCheckCsrf($_POST['csrf']??null)||!is_string($_POST['company_scope']??null)||!hash_equals(companyScopeToken(),$_POST['company_scope'])))authStop(409,'La sesión o la selección de empresas cambió. Vuelve a abrir la importación.');
    try {
        if($action!=='export'&&!transferCanImport($model))throw new InvalidArgumentException('Este modelo es de consulta o tu usuario no tiene permiso para importar. Sus datos se gestionan desde las operaciones de origen.',403);
        $context=$GLOBALS['company_context'];
        if($action!=='export')$GLOBALS['company_context']['read_selected']=[companyActiveId()];
        $db=$action==='import'?transferConnection($model):createDatabaseConnection();$fields=transferFields($db,$model);
        if($action==='template') {
            $columns=array_keys(array_filter($fields,static fn($f)=>$f['write']));
            $columns=array_merge(isset($fields['id'])?['id']:[],isset($fields['version'])?['version']:[],$columns);
            transferDownload($model,$fields,$columns,[],'plantilla');
        }
        if($action==='export'&&isset($_GET['download'])) {
            $columns=$_GET['fields']??[];if(!is_array($columns)||!array_is_list($columns))throw new InvalidArgumentException('Selecciona campos válidos.');
            transferDownload($model,$fields,$columns,transferExportRows($db,$model,$filters,$columns),'exportacion');
        }
        $_SESSION['transfer_pending']??=[];
        foreach($_SESSION['transfer_pending'] as $token=>$item)if(($item['expires']??0)<time())unset($_SESSION['transfer_pending'][$token]);
        if($action==='import'&&$_SERVER['REQUEST_METHOD']==='POST') {
            $phase=$_POST['phase']??'';
            if($phase==='preview') {
                $upload=$_FILES['csv']??null;if(!is_array($upload)||($upload['error']??null)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name']))throw new InvalidArgumentException('Selecciona un archivo CSV válido de hasta 4 MB.');
                $rows=transferParseCsv($upload['tmp_name'],$fields);$groups=transferGroupRows($model,$rows);$token=bin2hex(random_bytes(32));
                $result=transferApply($db,$model,$groups,$fields,$token,true);
                $ignored=[];foreach(array_keys($rows[0]['values']) as $column)if(!$fields[$column]['write']&&!in_array($column,['id','version'],true))$ignored[]=$fields[$column]['label'];
                $preview=['key'=>$key,'scope'=>companyScopeToken(),'actor'=>(int)$user['id'],'groups'=>$groups,'rows'=>$result['rows'],'snapshots'=>$result['snapshots'],'ignored'=>$ignored,
                    'fields_signature'=>hash('sha256',serialize($fields)),'expires'=>time()+900,'token'=>$token];
                $_SESSION['transfer_pending'][$token]=$preview;
                while(count($_SESSION['transfer_pending'])>3)array_shift($_SESSION['transfer_pending']);
            } elseif($phase==='confirm') {
                $token=is_string($_POST['token']??null)?$_POST['token']:'';$pending=$_SESSION['transfer_pending'][$token]??null;
                if(!$pending||$pending['key']!==$key||$pending['actor']!==(int)$user['id']||!hash_equals($pending['scope'],companyScopeToken())||!hash_equals($pending['fields_signature'],hash('sha256',serialize($fields))))throw new InvalidArgumentException('La vista previa venció o cambió. Vuelve a validar el archivo.',409);
                // Consume before committing so a repost cannot repeat this batch.
                unset($_SESSION['transfer_pending'][$token]);
                $result=transferApply($db,$model,$pending['groups'],$fields,$token,false,$pending['snapshots']);$success=count($result['rows']);
            } else throw new InvalidArgumentException('La operación de importación no es válida.');
        }
        $GLOBALS['company_context']=$context;
    }catch(Throwable $e) {
        if(isset($context))$GLOBALS['company_context']=$context;
        if($e instanceof InvalidArgumentException){if(in_array($e->getCode(),[403,409],true))http_response_code($e->getCode());$errors[]=$e->getMessage();}
        else {error_log('Importar/exportar: '.$e->getMessage());http_response_code(503);$errors[]='No se pudo completar la operación. No se guardó la importación.';}
    }
    $fields??=[];$databaseStatus=getDatabaseStatus();$homeHref=authBasePath().'/index.php';$isHomePage=false;
    require dirname(__DIR__).'/html/transfer/page.php';
}

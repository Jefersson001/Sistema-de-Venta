<?php
declare(strict_types=1);
require_once __DIR__.'/company-catalog.php';

function companyAvailable(PDO $db,array $user): array
{
    if($user['administrator'])return $db->query('SELECT id,name,version FROM business_company ORDER BY name,id')->fetchAll();
    $q=$db->prepare('SELECT c.id,c.name,c.version FROM business_company c JOIN company_user_access a ON a.company_id=c.id WHERE a.user_id=? ORDER BY c.name,c.id');
    $q->execute([$user['id']]);return $q->fetchAll();
}

function companyScopeToken(): string
{
    $context=$GLOBALS['company_context']??null;
    return $context?hash_hmac('sha256',$context['active'].':'.implode(',',$context['selected']),$_SESSION['auth_csrf']):'';
}

function companyInitialize(array $user,bool $json=false): array
{
    if(isset($GLOBALS['company_context']))return $user;
    $db=createDatabaseConnection(true);
    $q=$db->query('SELECT version FROM company_schema_state WHERE id=1');
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Ejecuta php/install-multi-company.php.');
    $companies=companyAvailable($db,$user);$allowed=array_map('intval',array_column($companies,'id'));
    if(!$allowed)authStop(403,'Tu usuario no tiene empresas autorizadas. Solicita acceso a un administrador.',$json);
    $selected=array_values(array_intersect($allowed,array_map('intval',$_SESSION['company_selected']??[])));
    $active=(int)($_SESSION['company_active']??$allowed[0]);
    if(!in_array($active,$allowed,true))$active=$allowed[0];
    if(!$selected)$selected=[$active];
    if(!in_array($active,$selected,true))$selected[]=$active;
    sort($selected,SORT_NUMERIC);
    $_SESSION['company_selected']=$selected;$_SESSION['company_active']=$active;
    $tables=$db->query('SELECT table_name FROM company_schema_tables ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
    $path=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');$readSelected=$selected;
    if(($_SERVER['REQUEST_METHOD']==='POST'&&str_starts_with($path,authBasePath().'/modules/'))||isset($_GET['new'])
        ||str_ends_with($path,'/producto.php')||($_GET['section']??null)==='currency_settings')$readSelected=[$active];
    elseif(($recordId=accessId($_GET['id']??null))&&($recordTable=companyRouteTable($path,$_GET))) {
        $q=$db->prepare('SELECT company_id FROM `'.$recordTable.'` WHERE id=?');$q->execute([$recordId]);$owner=(int)$q->fetchColumn();
        if(in_array($owner,$selected,true))$readSelected=[$owner];
    }
    $GLOBALS['company_context']=['active'=>$active,'selected'=>$selected,'read_selected'=>$readSelected,'actor'=>(int)$user['id'],'companies'=>$companies,'tables'=>$tables];
    $q=$db->prepare('SELECT a.company_id,a.contact_id FROM company_user_access a WHERE a.user_id=? AND a.contact_id IS NOT NULL');$q->execute([$user['id']]);
    $contacts=[];foreach($q->fetchAll() as $assignment)if(in_array((int)$assignment['company_id'],$selected,true))$contacts[(int)$assignment['company_id']]=(int)$assignment['contact_id'];
    // A salesperson contact belongs to exactly one company. Never apply its
    // "own records" identity to another company's records.
    if($user['contact_id']) {
        $q=$db->prepare('SELECT company_id FROM contacts WHERE id=?');$q->execute([$user['contact_id']]);
        $contactCompany=(int)$q->fetchColumn();
        if(in_array($contactCompany,$selected,true)&&!isset($contacts[$contactCompany]))$contacts[$contactCompany]=(int)$user['contact_id'];
    }
    $user['company_contacts']=$contacts;$user['contact_id']=$contacts[$active]??null;
    $GLOBALS['auth_request_user']=$user;
    $token=companyScopeToken();header('X-Nexo-Company-Scope: '.$token);
    if(isset($_SERVER['HTTP_X_NEXO_COMPANY_SCOPE'])&&!hash_equals($token,(string)$_SERVER['HTTP_X_NEXO_COMPANY_SCOPE']))
        authStop(409,'La selección de empresas cambió. Recarga esta página.',$json);
    if($_SERVER['REQUEST_METHOD']==='POST'&&str_starts_with($path,authBasePath().'/modules/')
        &&!hash_equals($token,is_string($_POST['company_scope']??null)?$_POST['company_scope']:(string)($_SERVER['HTTP_X_NEXO_COMPANY_SCOPE']??'')))
        authStop(409,'La selección de empresas cambió o el formulario está desactualizado. Recarga antes de guardar.',$json);
    return $user;
}

function companyActiveId(): int { return (int)($GLOBALS['company_context']['active']??1); }

/** Change the whole context atomically; accepting only already-authorized IDs. */
function companySelect(PDO $db,array $user,mixed $selection,mixed $active): void
{
    if(!is_array($selection)||!$selection||count($selection)>100)throw new InvalidArgumentException('Selecciona al menos una empresa.');
    $ids=[];
    foreach($selection as $id) {
        $value=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($value===false)throw new InvalidArgumentException('Selección de empresas inválida.');$ids[]=$value;
    }
    $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
    $allowed=array_map('intval',array_column(companyAvailable($db,$user),'id'));
    $primary=filter_var($active,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(array_diff($ids,$allowed)||!in_array($primary,$ids,true))throw new InvalidArgumentException('La empresa activa debe estar seleccionada y autorizada.',403);
    $_SESSION['company_active']=$primary;$_SESSION['company_selected']=$ids;
}

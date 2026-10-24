<?php
declare(strict_types=1);
require_once __DIR__.'/transfer-service.php';

function recordActionPermission(array $m,string $action): string
{
    return str_ends_with($m['resource'],'.configuration')?'configure':($action==='delete'?'delete':($m['save']==='invoices'?'duplicate':'create'));
}

function recordActionSupported(array $m,string $action): bool
{
    if($m['save']==='readonly'||!empty($m['singleton'])||!empty($m['create_only']))return false;
    if(in_array($m['key'],['companies','users','currency_settings'],true))return false;
    if($action==='duplicate')return !in_array($m['key'],['groups','currencies','types','document_types'],true);
    return true;
}

function recordActionRow(PDO $db,array $m,int $id,bool $lock=false): array
{
    [$where,$args]=transferDomain($db,$m,[],true);$where[]='r.id=?';$args[]=$id;
    $q=$db->prepare('SELECT r.* FROM '.$m['table'].' r WHERE '.implode(' AND ',$where).($lock?' FOR UPDATE':''));$q->execute($args);
    $row=$q->fetch();if(!$row)throw new InvalidArgumentException('El registro ya no existe o no tienes acceso.',404);
    return $row;
}

function recordActionSnapshot(PDO $db,array $m,array $row): string
{
    $lines=[];
    if(isset($m['line_table'])){$q=$db->prepare('SELECT * FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=? ORDER BY id');$q->execute([$row['id']]);$lines=$q->fetchAll();}
    return hash('sha256',json_encode([$row,$lines],JSON_THROW_ON_ERROR));
}

/** Foreign keys remain the final authority, including references added by future modules. */
function recordCheckDelete(PDO $db,array $m,array $row): void
{
    if(isset($row['status'])&&$row['status']!=='draft')throw new InvalidArgumentException('Solo se eliminan borradores. Este documento debe cancelarse o revertirse desde su ficha.');
    if(!empty($row['system_key'])||!empty($row['is_system']))throw new InvalidArgumentException('Este registro forma parte de la configuración del sistema y no se puede eliminar.');
    if($m['key']==='currencies'){
        $q=$db->prepare('SELECT COUNT(*) FROM accounting_currency_settings WHERE primary_code=? OR alternate_code=?');$q->execute([$row['code'],$row['code']]);
        if($q->fetchColumn())throw new InvalidArgumentException('No se elimina una moneda principal o alternativa. Cambia primero la configuración.');
        foreach(['sales_quotations','purchase_orders','accounting_invoices','accounting_payments','accounting_payment_accounts','accounting_treasury_documents'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE currency_code=?');$q->execute([$row['code']]);if($q->fetchColumn())throw new InvalidArgumentException('La moneda tiene documentos o cuentas relacionados y debe conservarse.');}
    }
    if($m['table']==='accounting_invoices'&&($row['payment_state']??'not_paid')!=='not_paid')throw new InvalidArgumentException('No se elimina una factura con pagos o reversiones.');
}

function recordDeleteRow(PDO $db,array $m,array $row): void
{
    recordCheckDelete($db,$m,$row);
    if($m['key']==='groups')$db->prepare('DELETE FROM access_group_permissions WHERE group_id=?')->execute([$row['id']]);
    if(isset($m['line_table'])){
        $db->prepare('DELETE FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=?')->execute([$row['id']]);
    }
    $db->prepare('DELETE FROM '.$m['table'].' WHERE id=?')->execute([$row['id']]);
}

function recordDuplicateRow(PDO $db,array $m,array $row,string $key): int
{
    $input=array_intersect_key($row,array_flip($m['write']));
    $input=array_map(static fn($value)=>$value===null?'':(string)$value,$input);
    foreach($m['defaults'] as $field=>$value)$input[$field]??=$value;
    if(isset($m['line_table'])){
        $q=$db->prepare('SELECT * FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=? ORDER BY '.($m['save']==='products'?'id':'position,id'));
        $q->execute([$row['id']]);$input['lines']=array_map(static fn($line)=>array_intersect_key($line,array_flip($m['line_write'])),$q->fetchAll());
    }
    if(isset($input['name']))$input['name']=mb_substr($input['name'],0,str_starts_with($m['key'],'restaurant_')?35:100).' (copia '.substr($key,0,6).')';
    if($m['key']==='restaurant_tables')$input['active']='0';
    if($m['key']==='countries')$input['code']='';
    foreach(['order_date','scheduled_date','payment_date','movement_date'] as $field)if(array_key_exists($field,$input))$input[$field]=commerceToday();
    if($m['save']==='operations')foreach($input['lines'] as &$line)$line['quantity']='0.000';unset($line);
    if($m['save']==='contacts'){
        // A copy must not retain another person's login or external stock location.
        $input['partner_location_id']='';$input['tax_id']='';
        if(permissionScope($m['resource'],'create')==='own'&&empty($input['salesperson_id']))$input['salesperson_id']=permissionContact();
    }
    if($m['save']==='invoices')return recordDuplicateInvoice($db,$row);
    return transferSave($db,$m,$input,null,$key);
}

function recordDuplicateInvoice(PDO $db,array $row): int
{
    if($row['document_type']!=='invoice')throw new InvalidArgumentException('Las notas de crédito se generan desde la factura original para comprobar el saldo disponible.');
    // Standalone draft: never invoice the same source order or stock movement twice.
    $fields=['invoice_type','contact_id','invoice_address','payment_term_id','salesperson_id','base_amount','tax_amount','total_amount','currency_code','currency_primary_code','currency_alternate_code'];
    $data=array_intersect_key($row,array_flip($fields));$data['name']='COPY-'.bin2hex(random_bytes(10));$data['invoice_date']=commerceToday();
    $data['due_date']=commerceDueDate($db,$row['payment_term_id']?(int)$row['payment_term_id']:null,$data['invoice_date']);
    $data+=invoiceExchangeSnapshot($db,$row,$data['invoice_date']);
    $id=transferWriteMaster($db,'accounting_invoices',$data,null);
    $db->prepare('UPDATE accounting_invoices SET name=? WHERE id=?')->execute([($row['invoice_type']==='customer'?'FC':'FP').str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);
    $q=$db->prepare('SELECT * FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY position,id');$q->execute([$row['id']]);
    foreach($q->fetchAll() as $line){
        foreach(['id','company_id','created_by','created_at','updated_by','updated_at','sale_line_id','purchase_line_id','original_line_id'] as $field)unset($line[$field]);
        $line['invoice_id']=$id;transferWriteMaster($db,'accounting_invoice_lines',$line,null);
    }
    return $id;
}

function recordApplyAction(PDO $db,array $m,string $action,array $snapshots,string $key): array
{
    if(!recordActionSupported($m,$action)||!in_array($action,['delete','duplicate'],true))throw new InvalidArgumentException('Esta acción no está disponible para el modelo.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$key)||count($snapshots)<1||count($snapshots)>100||($action==='duplicate'&&count($snapshots)!==1))throw new InvalidArgumentException('Selecciona entre 1 y 100 registros válidos.');
    if(!permissionHas($m['resource'],recordActionPermission($m,$action)))throw new InvalidArgumentException('Tu grupo no permite esta acción.',403);
    if($m['admin']&&!permissionUser()['administrator'])throw new InvalidArgumentException('Esta acción requiere un administrador.',403);
    if($action==='duplicate'&&$m['save']==='operations'&&permissionScope($m['resource'],'create')==='own')throw new InvalidArgumentException('Para entregas propias, genera el documento desde su orden de venta.');
    if($action==='duplicate'&&$m['save']==='invoices'&&!permissionHas($m['resource'],'edit'))throw new InvalidArgumentException('Se necesita permiso para editar facturas.',403);
    if(!method_exists($db,'atomicTransfer'))throw new LogicException('Se requiere la conexión transaccional de la aplicación.');
    $hash=hash('sha256',json_encode([$m['key'],$action,$snapshots],JSON_THROW_ON_ERROR));$actor=(int)permissionUser()['id'];
    return $db->atomicTransfer(function()use($db,$m,$action,$snapshots,$key,$hash,$actor){
        $q=$db->prepare('SELECT * FROM business_record_actions WHERE request_key=? FOR UPDATE');$q->execute([$key]);$previous=$q->fetch();
        if($previous){if(!hash_equals($previous['payload_hash'],$hash)||(int)$previous['created_by']!==$actor)throw new InvalidArgumentException('La solicitud corresponde a otra acción.',409);return json_decode($previous['result_json'],true,512,JSON_THROW_ON_ERROR);}
        $result=[];$details=[];ksort($snapshots,SORT_NUMERIC);
        foreach($snapshots as $id=>$snapshot){
            $id=accessId($id);if(!$id||!permissionRecord($m['resource'],$id,recordActionPermission($m,$action)))throw new InvalidArgumentException('El registro no está autorizado.',403);
            $row=recordActionRow($db,$m,$id,true);
            if(isset($row['company_id'])&&(int)$row['company_id']!==companyActiveId())throw new InvalidArgumentException('Activa la empresa del registro antes de modificarlo.',409);
            if(!is_string($snapshot)||!hash_equals(recordActionSnapshot($db,$m,$row),$snapshot))throw new InvalidArgumentException('Un registro cambió después de abrir la confirmación. Revisa la selección nuevamente.',409);
            $details[]=['id'=>$id,'name'=>$row['name']??$row['code']??(string)$id,'snapshot'=>$snapshot];
            if($action==='delete'){recordDeleteRow($db,$m,$row);$result[]=$id;}
            else $result[]=recordDuplicateRow($db,$m,$row,$key);
        }
        $db->prepare('INSERT INTO business_record_actions(model_key,action_key,request_key,payload_hash,result_json,details) VALUES(?,?,?,?,?,?)')->execute([$m['key'],$action,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR),json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

function recordFormUrl(string $source,?int $id): string
{
    parse_str(parse_url($source,PHP_URL_QUERY)??'',$q);unset($q['id'],$q['new'],$q['page']);if($id)$q['id']=$id;
    return parse_url($source,PHP_URL_PATH).($q?'?'.http_build_query($q):'');
}

function recordActionSource(mixed $source): string
{
    if(!is_string($source)||strlen($source)>4000||preg_match('/[\x00-\x1f\\\\]/',rawurldecode($source)))return '';
    $path=parse_url($source,PHP_URL_PATH);
    if(!is_string($path)||!preg_match('~^'.preg_quote(authBasePath(),'~').'/modules/[a-z-]+/[a-z-]+\.php$~D',$path)||parse_url($source,PHP_URL_HOST)!==null||parse_url($source,PHP_URL_SCHEME)!==null)return '';
    return $source;
}

function renderRecordActions(): void
{
    $GLOBALS['permission_runtime']=true;$GLOBALS['permission_user']=authRequireLogin();
    $source=recordActionSource($_GET['source']??null);$m=$source?transferRouteModel($source):null;$action=$_GET['action']??'';
    if(!$m||!is_string($action)||!in_array($action,['delete','duplicate','print'],true)||!permissionHas($m['resource']))authStop(404,'La acción o la vista no existe.');
    parse_str(parse_url($source,PHP_URL_QUERY)??'',$filters);
    $db=createDatabaseConnection();$errors=[];$rows=[];$success=null;$result=[];
    if($action==='print'){
        require_once __DIR__.'/document-print.php';renderBusinessDocument($db,$m,accessId($filters['id']??null),$source);return;
    }
    if(!recordActionSupported($m,$action))authStop(400,'Este registro se conserva por seguridad. Usa Archivar, Cancelar o el procedimiento específico de su módulo.');
    if(!permissionHas($m['resource'],recordActionPermission($m,$action)))authStop(403,'Tu grupo no permite esta acción.');
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){header('Allow: GET, POST');authStop(405,'Método no admitido.');}
    $_SESSION['record_actions']??=[];
    foreach($_SESSION['record_actions'] as $token=>$entry)if($entry['expires']<time())unset($_SESSION['record_actions'][$token]);
    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!authCheckCsrf($_POST['csrf']??null)||!is_string($_POST['company_scope']??null)||!hash_equals(companyScopeToken(),$_POST['company_scope']))throw new InvalidArgumentException('Cambió la sesión o la selección de empresas. Abre nuevamente la acción.',409);
            $key=is_string($_POST['request_key']??null)?$_POST['request_key']:'';$pending=$_SESSION['record_actions'][$key]??null;
            if(!$pending||$pending['source']!==$source||$pending['action']!==$action||!hash_equals($pending['scope'],companyScopeToken()))throw new InvalidArgumentException('La confirmación venció. Abre nuevamente la acción.',409);
            $result=recordApplyAction($db,$m,$action,$pending['snapshots'],$key);$success=true;
        }else{
            $ids=isset($filters['id'])?[$filters['id']]:($_GET['ids']??[]);
            if(!is_array($ids)||count($ids)<1||count($ids)>100)throw new InvalidArgumentException('Selecciona entre 1 y 100 registros en el listado.');
            $snapshots=[];
            foreach($ids as $id){$id=accessId($id);if(!$id)throw new InvalidArgumentException('La selección no es válida.');if(isset($snapshots[$id]))continue;$row=recordActionRow($db,$m,$id);if($action==='delete')recordCheckDelete($db,$m,$row);$rows[]=$row;$snapshots[$id]=recordActionSnapshot($db,$m,$row);}
            $key=bin2hex(random_bytes(32));$_SESSION['record_actions'][$key]=['source'=>$source,'action'=>$action,'scope'=>companyScopeToken(),'snapshots'=>$snapshots,'expires'=>time()+1800];
            while(count($_SESSION['record_actions'])>20)array_shift($_SESSION['record_actions']);
        }
    }catch(Throwable $e){
        if($e instanceof InvalidArgumentException){http_response_code(in_array($e->getCode(),[403,404,409],true)?$e->getCode():422);$errors[]=$e->getMessage();}
        elseif($e instanceof PDOException&&in_array((int)($e->errorInfo[1]??0),[1451,1452],true)){http_response_code(409);$errors[]='Hay registros relacionados que deben conservarse. No se eliminó ningún registro de la selección. Usa Archivar o Cancelar desde su ficha.';}
        else{error_log('Acciones de registros: '.$e->getMessage());http_response_code(503);$errors[]='No se pudo completar la acción. No se guardaron cambios parciales.';}
    }
    require dirname(__DIR__).'/html/record-actions.php';
}

<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/transfer-models.php';
require_once __DIR__.'/transfer-fields.php';
require_once __DIR__.'/transfer-adapters.php';
require_once __DIR__.'/transfer-transactions.php';
require_once __DIR__.'/cash-flow-repository.php';

function transferCanImport(array $m): bool
{
    if($m['save']==='readonly'||!permissionHas($m['resource'])||($m['admin']&&!permissionUser()['administrator']))return false;
    if(str_ends_with($m['resource'],'.configuration'))return permissionHas($m['resource'],'configure');
    if($m['save']==='commission_payments')return permissionHas('ventas.commissions','pay')&&permissionHas('contabilidad.expense','create')&&permissionHas('contabilidad.expense','confirm');
    if($m['save']==='invoices')return permissionHas($m['resource'],'edit')||permissionHas($m['type']==='customer'?'ventas.orders':'compras.orders','create_invoice');
    return permissionHas($m['resource'],'create')||permissionHas($m['resource'],'edit');
}

function transferConnection(array $m): PDO
{
    if($m['save']!=='companies')return createDatabaseConnection();
    if(!permissionUser()['administrator'])throw new InvalidArgumentException('Solo un Administrador puede importar empresas.',403);
    $c=require __DIR__.'/config/database.php';
    $db=new TransferAdminConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset='.$c['charset'],$c['username'],$c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='-04:00', @nexo_actor_id=".(int)permissionUser()['id'].', @nexo_company_id='.companyActiveId());return $db;
}

function transferDomain(PDO $db,array $m,array $filters=[],bool $editing=false): array
{
    $where=[];$args=[];$schema=transferSchema($db,$m['table']);
    foreach($m['domain'] as $key=>$value){$where[]='r.'.companyQuoteIdentifier($key).'=?';$args[]=$value;}
    if($m['key']==='companies'){$ids=array_map('intval',array_column($GLOBALS['company_context']['companies'],'id'));$where[]='r.id IN('.implode(',',$ids).')';}
    if(in_array($m['key'],['sold','purchased'],true)) {
        $sale=$m['key']==='sold';$table=$sale?'sales_quotations':'purchase_orders';$fk=$sale?'quotation_id':'order_id';
        $where[]='EXISTS(SELECT 1 FROM '.$table.' o WHERE o.id=r.'.$fk.' AND o.status=?)';$args[]=$sale?'sale':'purchase';
        $where[]='EXISTS(SELECT 1 FROM '.$table.' o WHERE o.id=r.'.$fk.' AND '.permissionWhere($m['resource'],'o').')';
    } elseif($m['key']==='treasury')$where[]=permissionTreasuryWhere('r');
    elseif(!str_ends_with($m['resource'],'.configuration'))$where[]=permissionWhere($m['resource'],'r');
    if($editing)return [$where,$args];
    if(in_array($m['key'],['products','variants'],true)&&!isset($filters['id'])){
        $where[]='r.parent_product_id IS '.($m['key']==='variants'?'NOT NULL':'NULL');
        if($m['key']==='variants'&&accessId($filters['parent_id']??null)){$where[]='r.parent_product_id=?';$args[]=accessId($filters['parent_id']);}
    }
    if(isset($filters['id'])){if(!accessId($filters['id'])||!isset($schema['id']))throw new InvalidArgumentException('La ficha solicitada no existe.');$where[]='r.id=?';$args[]=accessId($filters['id']);}
    if($m['key']==='contacts'&&!isset($filters['id'])) {
        $where[]='r.active=?';$args[]=($filters['status']??'')==='archived'?0:1;
        if(accessId($filters['type']??null)){$where[]='r.type_id=?';$args[]=accessId($filters['type']);}
    }
    if(isset($filters['active'])&&isset($schema['active'])&&in_array($filters['active'],['active','inactive'],true)){$where[]='r.active=?';$args[]=$filters['active']==='active'?1:0;}
    if(isset($filters['status'])&&isset($schema['status'])&&!in_array($filters['status'],['all','','active','archived'],true)) {
        if($filters['status']==='quotations')$where[]="r.status IN('draft','sent')";
        elseif($m['table']==='accounting_invoices'&&$filters['status']==='sent')$where[]="r.status='posted' AND r.sent_at IS NOT NULL";
        elseif($m['table']==='accounting_invoices'&&in_array($filters['status'],['not_paid','in_payment','partial','paid','reversed'],true)){$where[]="r.status='posted' AND r.payment_state=?";$args[]=$filters['status'];}
        else {$where[]='r.status=?';$args[]=$filters['status'];}
    }
    foreach(['product_id','location_id','account_id','contact_id','category_id','sale_id','purchase_id'] as $field)if(isset($schema[$field])&&accessId($filters[$field]??null)){$where[]='r.'.$field.'=?';$args[]=accessId($filters[$field]);}
    if($m['table']==='accounting_invoices'&&(($filters['unpaid']??'')==='1'||($filters['overdue']??'')==='1'))$where[]="r.status='posted' AND r.document_type='invoice' AND r.payment_state IN('not_paid','in_payment','partial')";
    if($m['table']==='accounting_invoices'&&($filters['overdue']??'')==='1'){$where[]='r.due_date<?';$args[]=commerceToday();}
    if(in_array($m['save'],['sale_orders','purchase_orders'],true)&&($filters['pending']??'')==='1'){require_once __DIR__.'/dashboard-repository.php';$where[]=dashboardPendingOrderCondition($m['kind'],'r');}
    if(isset($filters['q'])&&is_string($filters['q'])&&trim($filters['q'])!=='') {
        $search=[];$term='%'.mb_substr(trim($filters['q']),0,160).'%';
        foreach(array_unique(array_merge($m['search'],array_intersect(['tax_id','email','reference','code','symbol'],array_keys($schema)))) as $field)if(isset($schema[$field])){$search[]='r.'.$field.' LIKE ?';$args[]=$term;}
        foreach(['customer_id'=>'contacts','supplier_id'=>'contacts','contact_id'=>'contacts','product_id'=>'inventory_products','location_id'=>'inventory_locations','account_id'=>'accounting_payment_accounts'] as $field=>$table)if(isset($schema[$field])){$search[]='EXISTS(SELECT 1 FROM '.$table.' related WHERE related.id=r.'.$field.' AND related.name LIKE ?)';$args[]=$term;}
        if($search)$where[]='('.implode(' OR ',$search).')';
    }
    if($m['key']==='cash_flow') {
        $range=cashFlowRange($filters);$where[]='r.movement_date BETWEEN ? AND ?';array_push($args,$range['date_from'],$range['date_to']);
    }
    if($m['key']==='commission_payments'&&!isset($filters['id'])) {
        $range=cashFlowRange($filters);$where[]='EXISTS(SELECT 1 FROM accounting_treasury_documents d WHERE d.id=r.treasury_document_id AND d.movement_date BETWEEN ? AND ?)';array_push($args,$range['date_from'],$range['date_to']);
        if(accessId($filters['seller_id']??null)){$where[]='EXISTS(SELECT 1 FROM accounting_invoices i WHERE i.id=r.invoice_id AND i.salesperson_id=?)';$args[]=accessId($filters['seller_id']);}
    }
    return [$where,$args];
}

function transferFind(PDO $db,array $m,int $id): ?array
{
    [$where,$args]=transferDomain($db,$m,[],true);$where[]='r.id=?';$args[]=$id;
    $q=$db->prepare('SELECT r.* FROM '.$m['table'].' r WHERE '.implode(' AND ',$where).' FOR UPDATE');$q->execute($args);return $q->fetch()?:null;
}

function transferSnapshot(PDO $db,array $m,array $record): string
{
    $snapshot=[$record];
    if(isset($m['line_table'])){$q=$db->prepare('SELECT * FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=? ORDER BY id');$q->execute([$record['id']]);$snapshot[]=$q->fetchAll();}
    if($m['save']==='users'){$q=$db->prepare('SELECT * FROM company_user_access WHERE user_id=? ORDER BY company_id');$q->execute([$record['id']]);$snapshot[]=$q->fetchAll();$snapshot[]=loadAuthUser($db,(int)$record['id'])['group_ids'];}
    if($m['save']==='groups')$snapshot[]=loadAccessGroup($db,(int)$record['id'])['permissions'];
    return hash('sha256',serialize($snapshot));
}

function transferParseCsv(string $filename,array $fields): array
{
    if(!is_file($filename)||filesize($filename)>4*1024*1024)throw new InvalidArgumentException('El archivo debe ser CSV UTF-8 de hasta 4 MB.');
    $data=file_get_contents($filename);if($data===false||!mb_check_encoding($data,'UTF-8')||str_contains($data,"\0"))throw new InvalidArgumentException('Guarda el archivo como CSV UTF-8.');
    $data=preg_replace('/^\xEF\xBB\xBF/','',$data);$stream=fopen('php://temp','w+');fwrite($stream,$data);rewind($stream);
    try {
        $first=fgets($stream);rewind($stream);$separator=count(str_getcsv($first?:'', ';','"',''))>count(str_getcsv($first?:'',',','"',''))?';':',';
        $header=fgetcsv($stream,0,$separator,'"','');if(!$header||count($header)>250)throw new InvalidArgumentException('El CSV no tiene encabezados válidos.');
        $labels=[];foreach($fields as $name=>$field)$labels[mb_strtolower($field['label'])]=$name;
        $columns=[];foreach($header as $label){$name=trim($label??'');$name=isset($fields[$name])?$name:($labels[mb_strtolower($name)]??null);if(!$name||in_array($name,$columns,true))throw new InvalidArgumentException('Encabezado desconocido o repetido: '.mb_substr((string)$label,0,100).'. Descarga la plantilla del modelo.');$columns[]=$name;}
        if(!array_filter($columns,static fn($name)=>$fields[$name]['write']??false))throw new InvalidArgumentException('El archivo no contiene campos importables.');
        $rows=[];$line=1;
        while(($values=fgetcsv($stream,0,$separator,'"',''))!==false){$line++;if($values===[null]||!array_filter($values,static fn($v)=>$v!==null&&$v!==''))continue;
            if(count($values)!==count($columns))throw new InvalidArgumentException('Fila '.$line.': el número de columnas no coincide con el encabezado.');
            if(count($rows)>=2000)throw new InvalidArgumentException('Cada carga admite hasta 2000 filas. Divide el archivo.');
            $rows[]=['row'=>$line,'values'=>array_combine($columns,array_map(static fn($v)=>(string)($v??''),$values))];
        }
        if(!$rows)throw new InvalidArgumentException('El archivo no contiene registros.');return $rows;
    }finally{fclose($stream);}
}

function transferGroupRows(array $m,array $rows): array
{
    $groups=[];
    foreach($rows as $row) {
        $v=$row['values'];$hasLines=false;foreach(array_keys($v) as $field)if(str_starts_with($field,'line.'))$hasLines=true;
        $key=isset($v['id'])&&$v['id']!==''?'id:'.$v['id']:(!empty($m['line_write'])&&($v['document_key']??'')!==''?'key:'.$v['document_key']:'row:'.$row['row']);
        $head=[];$line=[];foreach($v as $field=>$value){if(str_starts_with($field,'line.'))$line[substr($field,5)]=$value;else $head[$field]=$value;}
        if(!isset($groups[$key]))$groups[$key]=['row'=>$row['row'],'values'=>$head,'lines'=>[],'has_lines'=>$hasLines];
        elseif(empty($m['line_write']))throw new InvalidArgumentException('Fila '.$row['row'].': un registro aparece repetido.');
        elseif($groups[$key]['values']!==$head)throw new InvalidArgumentException('Fila '.$row['row'].': las cabeceras de un mismo documento deben coincidir.');
        if($hasLines&&array_filter($line,static fn($v)=>$v!==''))$groups[$key]['lines'][]=$line;
    }
    return array_values($groups);
}

function transferExportRows(PDO $db,array $m,array $filters,array $columns=[]): array
{
    if(!permissionHas($m['resource']))throw new InvalidArgumentException('No tienes permiso para exportar este modelo.',403);
    if(($m['reader']??'')==='outstanding') {
        require_once __DIR__.'/outstanding-repository.php';$rows=[];$filter=outstandingFilters($filters);
        foreach(['customer','supplier'] as $type){$resource=permissionInvoiceResource($type);if(permissionHas($resource))array_push($rows,...outstandingInvoiceRows($db,$type,permissionWhere($resource,'i'),$filter));}
    } elseif(($m['reader']??'')==='commissions') {
        $range=isset($filters['id'])?null:cashFlowRange($filters);$rows=loadSalesCommissions($db,$range,accessId($filters['seller_id']??null),accessId($filters['id']??null));
    } else {
        [$where,$args]=transferDomain($db,$m,$filters);$schema=transferSchema($db,$m['table']);
        if($m['key']==='movements'&&in_array($filters['view']??'',['incoming','outgoing'],true)) {
            $incoming=$filters['view']==='incoming';
            $where[]="EXISTS(SELECT 1 FROM inventory_operations o WHERE o.id=r.operation_id AND o.status='done' AND ".($incoming?"((o.operation_type='receipt' AND o.return_of_id IS NULL) OR (o.operation_type='delivery' AND o.return_of_id IS NOT NULL))":"((o.operation_type='delivery' AND o.return_of_id IS NULL) OR (o.operation_type='receipt' AND o.return_of_id IS NOT NULL))").')';
            $where[]='r.created_at >= DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 YEAR)';
        }
        $q=$db->prepare('SELECT r.* FROM '.$m['table'].' r'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.(isset($schema['id'])?'r.id':'r.product_id,r.location_id').' LIMIT 20001');$q->execute($args);$rows=$q->fetchAll();
    }
    if(count($rows)>20000)throw new InvalidArgumentException('La exportación supera 20000 registros. Reduce los filtros.');
    $names=[];foreach($GLOBALS['company_context']['companies'] as $c)$names[(int)$c['id']]=$c['name']?:'Empresa '.$c['id'];
    $output=[];
    foreach($rows as $row) {
        $row['company_name']=$names[(int)($row['company_id']??0)]??'';
        if($m['key']==='stock'){$q=$db->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_reservations WHERE product_id=? AND location_id=?');$q->execute([$row['product_id'],$row['location_id']]);$row['reserved']=(string)$q->fetchColumn();$row['available']=bcsub($row['quantity'],$row['reserved'],2);}
        if($m['save']==='invoices')$row['source_order_id']=$row[$m['type']==='customer'?'sale_id':'purchase_id'];
        if($m['save']==='users'){$row['groups']=implode('|',loadAuthUser($db,(int)$row['id'])['group_ids']);$q=$db->prepare('SELECT company_id FROM company_user_access WHERE user_id=? ORDER BY company_id');$q->execute([$row['id']]);$row['company_ids']=implode('|',$q->fetchAll(PDO::FETCH_COLUMN));}
        if($m['save']==='groups'){$permissions=loadAccessGroup($db,(int)$row['id'])['permissions'];$row['permissions']=implode('|',array_keys($permissions));$scopes=[];foreach($permissions as $code=>$scope)$scopes[substr($code,0,strrpos($code,'.'))]=$scope;$row['scopes']=json_encode($scopes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
        $lines=[[]];if(isset($m['line_table'])&&array_filter($columns,static fn($name)=>str_starts_with($name,'line.'))){$lineSchema=transferSchema($db,$m['line_table']);$q=$db->prepare('SELECT * FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=? ORDER BY '.(isset($lineSchema['position'])?'position,id':'id'));$q->execute([$row['id']]);$lines=$q->fetchAll()?:[[]];}
        foreach($lines as $line){$out=$row;$out['document_key']='documento-'.($row['id']??'');foreach($line as $name=>$value)$out['line.'.$name]=$value;$output[]=$out;}
        if(count($output)>100000)throw new InvalidArgumentException('La exportación supera 100000 líneas. Reduce los filtros.');
    }
    return $output;
}

function transferCsvCell(mixed $value,array $field): string
{
    $value=$value===null?'':(is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):(string)$value);
    if($field['type']==='decimal'&&$value!=='')return decimalRound($value);
    if(!in_array($field['type'],['int','decimal','boolean'],true)&&preg_match('/^[\s]*[=+@\-\t\r]/u',$value))return "'".$value;
    return $value;
}

function transferDownload(array $m,array $fields,array $columns,array $rows,string $suffix): never
{
    if(!$columns||count($columns)>200)throw new InvalidArgumentException('Selecciona al menos un campo.');
    foreach($columns as $column)if(!is_string($column)||!isset($fields[$column])||($suffix==='plantilla'?!($fields[$column]['write']||in_array($column,['id','version'],true)):!$fields[$column]['export']))throw new InvalidArgumentException('Un campo seleccionado no está autorizado.');
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$m['key'].'-'.$suffix.'.csv"');header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$columns,';','"','');
    foreach($rows as $row){$values=[];foreach($columns as $name)$values[]=transferCsvCell($row[$name]??'',$fields[$name]);fputcsv($out,$values,';','"','');}fclose($out);exit;
}

function transferApply(PDO $db,array $m,array $groups,array $fields,string $token,bool $preview,array $expected=[]): array
{
    if(!transferCanImport($m))throw new InvalidArgumentException('Tu usuario no puede importar este modelo.',403);
    return $db->atomicTransfer(function()use($db,$m,$groups,$fields,$token,$preview,$expected){
        $results=[];$snapshots=[];$seen=[];
        foreach($groups as $i=>$group)try {
            $raw=$group['values'];$id=($raw['id']??'')!==''?accessId($raw['id']):null;
            if(($raw['id']??'')!==''&&!$id)throw new InvalidArgumentException('El ID del registro no es válido.');
            if(!empty($m['singleton']))$id=1;
            if($id&&isset($seen[$id]))throw new InvalidArgumentException('Un registro aparece repetido en el archivo.');if($id)$seen[$id]=true;
            $old=$id?transferFind($db,$m,$id):null;if($id&&!$old)throw new InvalidArgumentException('El registro no existe en la empresa principal o no tienes acceso.');
            if($old&&isset($old['company_id'])&&(int)$old['company_id']!==companyActiveId())throw new InvalidArgumentException('El registro no pertenece a la empresa principal.');
            if($old){$snapshots[$id]=transferSnapshot($db,$m,$old);if(!$preview&&(!isset($expected[$id])||!hash_equals($expected[$id],$snapshots[$id])))throw new InvalidArgumentException('El registro cambió después de la vista previa; vuelve a validar.',409);
                if(($raw['version']??'')!==''&&isset($old['version'])&&(string)$old['version']!==$raw['version'])throw new InvalidArgumentException('La versión del archivo está desactualizada.',409);}
            $action=$id?'edit':'create';$resource=$m['resource'];
            if(str_ends_with($resource,'.configuration'))$action='configure';
            elseif($m['save']==='companies')$action='edit';
            elseif($m['save']==='commission_payments'){$action='pay';$resource='ventas.commissions';}
            if(!($m['save']==='invoices'&&!$id)&&(!permissionHas($resource,$action)||($id&&!permissionRecord($resource,$id,$action))))throw new InvalidArgumentException('No tienes permiso para '.($id?'modificar':'crear').' este registro.',403);
            $input=[];foreach($old??[] as $name=>$value)if(isset($fields[$name])&&$fields[$name]['write'])$input[$name]=$value===null?'':(string)$value;
            foreach($m['defaults'] as $name=>$value)if(!isset($input[$name]))$input[$name]=$value;
            if(!$id&&isset($fields['currency_code'])&&$fields['currency_code']['write']&&!isset($input['currency_code']))$input['currency_code']=accountingCurrencies($db)['primary_code'];
            foreach($fields as $name=>$field)if($field['write']&&!str_starts_with($name,'line.')&&!isset($input[$name])&&$field['default']!==null)$input[$name]=(string)$field['default'];
            foreach($raw as $name=>$value)if(!in_array($name,['id','version','document_key'],true)&&!str_starts_with($name,'line.')&&($fields[$name]['write']??false)) {
                if(!$id&&$value===''&&(isset($input[$name])||$fields[$name]['default']!==null))$value=(string)($input[$name]??$fields[$name]['default']);
                $input[$name]=transferNormalize($db,$name,$value,$fields[$name]);
            }
            if(!$id)foreach($fields as $name=>$field){
                if($m['save']==='payments'&&$name==='amount'&&($input['received_amount']??'')!=='')continue;
                if($field['write']&&$field['required']&&!str_starts_with($name,'line.')&&($input[$name]??'')==='')throw new InvalidArgumentException('Falta el campo obligatorio: '.$field['label'].'.');
            }
            if($group['has_lines']&&!empty($m['line_write'])){$input['lines']=[];foreach($group['lines'] as $line){$values=[];foreach($line as $name=>$value)if($fields['line.'.$name]['write']??false)$values[$name]=transferNormalize($db,'line.'.$name,$value,$fields['line.'.$name]);$input['lines'][]=$values;}}
            if($m['save']==='users'&&isset($input['groups']))$input['groups']=transferList($input['groups']);
            if($m['save']==='payments'&&array_key_exists('amount',$raw)&&!array_key_exists('received_amount',$raw))unset($input['received_amount']);
            if($m['save']==='groups'&&isset($input['permissions']))$input['permissions']=transferList($input['permissions']);
            if(isset($input['active'])&&$old&&isset($old['active'])&&(int)$input['active']!==(int)$old['active']&&!str_ends_with($resource,'.configuration')&&!permissionHas($resource,'archive'))throw new InvalidArgumentException('No tienes permiso para archivar o reactivar registros.',403);
            $post=$_POST;try {$_POST=$input;permissionGuardInput($db,$resource,$m['save']==='commission_payments'?accessId($input['invoice_id']??null):$id,$action,false);$input=$_POST;}finally{$_POST=$post;}
            $saved=transferSave($db,$m,$input,$old,hash('sha256',$token.':'.$i));
            $details=[];if(isset($input['lines']))$details[]=count($input['lines']).' líneas de detalle';
            if($m['save']==='commission_payments')$details[]=($input['amount']??'').' · Pago de comisión confirmado';
            else {
                $q=$db->prepare('SELECT * FROM '.$m['table'].' WHERE id=?');$q->execute([$saved]);$fresh=$q->fetch();
                if(isset($fresh['total_amount'])||isset($fresh['amount']))$details[]='Importe: '.($fresh['total_amount']??$fresh['amount']).' '.($fresh['currency_code']??'');
                if(isset($fresh['status']))$details[]=['draft'=>'Borrador','pending'=>'En proceso','done'=>'Confirmado','settled'=>'Pagado'][$fresh['status']]??$fresh['status'];
            }
            $results[]=['row'=>$group['row'],'operation'=>$id?'Actualizar':'Crear','name'=>$input['name']??$input['login']??$raw['document_key']??('#'.($i+1)),'id'=>$saved,'details'=>implode(' · ',$details)];
        }catch(Throwable $e){
            if($e instanceof PDOException){$code=$e->errorInfo[1]??0;$message=$code===1062?'Hay un valor duplicado.':($code===1452?'Una referencia no existe o pertenece a otra empresa.':($code===1644?($e->errorInfo[2]??'Relación no válida.'):'No se pudo guardar el registro; revisa sus campos y relaciones.'));}
            else $message=$e->getMessage();
            throw new InvalidArgumentException('Fila '.$group['row'].': '.$message,in_array((int)$e->getCode(),[403,409],true)?(int)$e->getCode():0,$e);
        }
        return ['rows'=>$results,'snapshots'=>$snapshots];
    },$preview);
}

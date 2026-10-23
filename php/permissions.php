<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';

// Solo las entradas HTTP activan el contexto. Los instaladores y pruebas de repositorios son CLI.
function permissionRuntime(): bool { return !empty($GLOBALS['permission_runtime']); }
function permissionUser(): array { return $GLOBALS['permission_user'] ?? authRequireLogin(); }
function permissionMap(): array
{
    if(!permissionRuntime())return array_fill_keys(array_keys(accessPermissionCatalog()),'all');
    if(!isset($GLOBALS['permission_map'])){$map=resolveAccessGroupPermissions(createDatabaseConnection(),permissionUser()['group_ids']);ksort($map,SORT_STRING);$GLOBALS['permission_map']=$map;}
    return $GLOBALS['permission_map'];
}
function permissionScope(string $resource,string $action='read'): ?string { return accessPermissionScope(permissionMap(),$resource.'.'.$action); }
function permissionHas(string $resource,string $action='read'): bool { return permissionScope($resource,$action)!==null; }
function permissionContact(): int { return permissionRuntime()?(int)(permissionUser()['contact_id']??0):0; }
function permissionContacts(): array
{
    $user=permissionUser();return array_values(array_unique(array_map('intval',$user['company_contacts']??array_filter([$user['contact_id']??0]))));
}
function permissionWhere(string $resource,string $alias,string $action='read'): string
{
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new LogicException('Alias de permisos inválido.');
    $scope=permissionScope($resource,$action);if($scope==='all')return '1=1';
    $contact=permissionContact();if($scope!=='own'||!permissionContacts())return '1=0';
    return permissionAssignedWhere($resource,$alias,$contact);
}
function permissionAssignedWhere(string $resource,string $alias,int $contact): string
{
    $ids=permissionRuntime()?permissionContacts():array_filter([$contact]);
    if(!$ids)return '1=0';$list=implode(',',$ids);
    return match($resource) {
        'contactos.contacts'=>"($alias.id IN ($list) OR $alias.salesperson_id IN ($list))",
        'ventas.orders','contabilidad.customer_invoices'=>"$alias.salesperson_id IN ($list)",
        'inventario.deliveries'=>"EXISTS(SELECT 1 FROM sales_quotations permission_sale WHERE permission_sale.id=$alias.quotation_id AND permission_sale.salesperson_id IN ($list))",
        'ventas.commissions'=>"$alias.salesperson_id IN ($list)",
        'ventas.commission_payments'=>"EXISTS(SELECT 1 FROM accounting_invoices permission_invoice WHERE permission_invoice.id=$alias.invoice_id AND permission_invoice.salesperson_id IN ($list))",
        default=>'1=0',
    };
}
function permissionDashboardWhere(string $section,string $resource,string $alias): string
{
    if(!permissionHas($section)||!permissionHas($resource))return '1=0';
    $source=permissionWhere($resource,$alias);$dashboard=permissionScope($section)==='own'?permissionAssignedWhere($resource,$alias,permissionContact()):'1=1';
    if($source==='1=0'||$dashboard==='1=0')return '1=0';return '('.$source.') AND ('.$dashboard.')';
}
function permissionRecord(string $resource,int $id,string $action='read'): bool
{
    if(permissionScope($resource,$action)==='all')return true;
    $table=match($resource){'contactos.contacts'=>'contacts','ventas.orders'=>'sales_quotations',
        'inventario.deliveries'=>'inventory_operations','contabilidad.customer_invoices','ventas.commissions'=>'accounting_invoices',
        'ventas.commission_payments'=>'sales_commission_payments',default=>null};
    if(!$table)return false;
    $db=createDatabaseConnection();$q=$db->prepare('SELECT 1 FROM '.$table.' r WHERE r.id=? AND '.permissionWhere($resource,'r',$action));$q->execute([$id]);return (bool)$q->fetchColumn();
}
function permissionRequire(string $resource,string $action='read',?int $id=null,bool $json=false): void
{
    if(!permissionRuntime())return;
    if(!permissionHas($resource,$action)||($id!==null&&!permissionRecord($resource,$id,$action))) {
        authStop(403,'Tu grupo no permite consultar este registro o realizar esta acción.',$json,authBasePath().'/index.php');
    }
}
function permissionInvoiceResource(string $type): string { return 'contabilidad.'.($type==='supplier'?'supplier':'customer').'_invoices'; }
function permissionPaymentResource(string $type): string { return 'contabilidad.'.($type==='supplier'?'supplier':'customer').'_payments'; }
function permissionOperationResource(string $type): string { return 'inventario.'.match($type){'receipt'=>'receipts','internal'=>'internal',default=>'deliveries'}; }
function permissionTreasuryResource(int $id): ?string
{
    static $cache=[];if(array_key_exists($id,$cache))return $cache[$id];$original=$id;$db=createDatabaseConnection();
    for($depth=0;$depth<16;$depth++){
        $q=$db->prepare('SELECT kind,reversal_of_id,payment_id FROM accounting_treasury_documents WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)break;
        if(in_array($row['kind'],['income','expense','transfer','opening'],true))return $cache[$original]='contabilidad.'.$row['kind'];
        if($row['kind']==='payment_reversal'){$q=$db->prepare('SELECT partner_type FROM accounting_payments WHERE id=?');$q->execute([$row['payment_id']]);if($type=$q->fetchColumn())return $cache[$original]=permissionPaymentResource($type);break;}
        $id=(int)$row['reversal_of_id'];if(!$id)break;
    }
    return $cache[$original]=null;
}
function permissionTreasuryWhere(string $alias='d'): string
{
    $kinds=array_filter(['income','expense','transfer','opening'],static fn($kind)=>permissionHas('contabilidad.'.$kind));
    $list=$kinds?implode(',',array_map(static fn($kind)=>"'".$kind."'",$kinds)):"''";
    $payments=array_filter(['customer','supplier'],static fn($type)=>permissionHas(permissionPaymentResource($type)));
    $types=$payments?implode(',',array_map(static fn($type)=>"'".$type."'",$payments)):"''";
    return "($alias.kind IN ($list) OR ($alias.kind='reversal' AND EXISTS(SELECT 1 FROM accounting_treasury_documents permission_original WHERE permission_original.id=$alias.reversal_of_id AND permission_original.kind IN ($list))) OR ($alias.kind='payment_reversal' AND EXISTS(SELECT 1 FROM accounting_payments permission_payment WHERE permission_payment.id=$alias.payment_id AND permission_payment.partner_type IN ($types))))";
}
function permissionRoute(string $path,array $query): ?string
{
    $route=substr($path,strlen(authBasePath().'/modules/'));$parts=explode('/',$route);if(count($parts)!==2)return null;
    [$module,$file]=$parts;
    if($module==='contabilidad'&&$file==='tesoreria.php'&&($id=accessId($query['id']??null))) {
        if($resource=permissionTreasuryResource($id))return $resource;
    }
    if($file==='configuracion.php')return $module==='dashboard'?null:$module.'.configuration';
    return match($module.'/'.$file){
        'contactos/index.php'=>'contactos.contacts',
        'ventas/index.php','ventas/producto.php'=>'ventas.orders',
        'ventas/comisiones.php'=>'ventas.commissions','ventas/pagos-comisiones.php'=>'ventas.commission_payments',
        'compras/index.php','compras/orden.php'=>'compras.orders',
        'inventario/index.php'=>'inventario.products',
        'inventario/operaciones.php'=>permissionOperationResource(is_string($query['type']??null)?$query['type']:'delivery'),
        'inventario/informes.php'=>match($query['view']??'movements'){'stock'=>'inventario.stock','sold'=>'ventas.orders','purchased'=>'compras.orders',default=>'inventario.movements'},
        'contabilidad/index.php'=>permissionInvoiceResource(is_string($query['type']??null)?$query['type']:'customer'),
        'contabilidad/pagos.php'=>permissionPaymentResource(is_string($query['type']??null)?$query['type']:'customer'),
        'contabilidad/tesoreria.php'=>'contabilidad.'.(in_array($query['kind']??null,['income','expense','transfer','opening'],true)?$query['kind']:'income'),
        'contabilidad/ingresos-egresos.php'=>'contabilidad.cash_flow',
        'ajustes/index.php'=>'ajustes.general','ajustes/empresa.php','ajustes/logo.php'=>'ajustes.company',
        'ajustes/grupos.php'=>'ajustes.groups','ajustes/usuarios.php'=>'ajustes.users',
        'dashboard/ingresos-egresos.php'=>'dashboard.cash_flow',default=>null,
    };
}
function permissionAction(string $resource,mixed $action,?int $id): ?string
{
    if(!is_string($action))return null;
    if(str_ends_with($resource,'.configuration'))return 'configure';
    if(str_starts_with($action,'save_'))return $resource==='ajustes.company'?'edit':($id?'edit':'create');
    return match($action){'toggle_active'=>'archive','mark_sent','mark_invoice_sent'=>'send',
        'confirm_sale','confirm_purchase','confirm_invoice','confirm_operation','post_payment','confirm_treasury'=>'confirm',
        'cancel_quotation','cancel_purchase','cancel_invoice','cancel_operation','cancel_payment','cancel_treasury'=>'cancel',
        'create_delivery'=>'create_delivery','create_receipt'=>'create_receipt','create_invoice'=>'create_invoice',
        'check_ready'=>'reserve','validate_operation'=>'validate','create_return'=>'return',
        'reverse_invoice','create_credit'=>'credit','register_payment'=>'register_payment','settle_payment'=>'settle_payment',
        'apply_existing_payment','apply_payment'=>'allocate','unapply_payment'=>'unallocate','pay_commission'=>'pay',
        'reverse_payment','reverse_treasury'=>'reverse','reset_password'=>'password',default=>null};
}
function permissionModuleUrl(string $module): ?string
{
    $routes=match($module){
        'dashboard'=>['index.php'], 'contactos'=>['index.php','configuracion.php'],
        'ventas'=>['index.php','comisiones.php','pagos-comisiones.php','configuracion.php'],
        'compras'=>['index.php','configuracion.php'],
        'inventario'=>['index.php','operaciones.php?type=delivery','operaciones.php?type=receipt','operaciones.php?type=internal','informes.php?view=stock','informes.php?view=movements','configuracion.php'],
        'contabilidad'=>['index.php?type=customer','index.php?type=supplier','pagos.php?type=customer','pagos.php?type=supplier','tesoreria.php?kind=income','tesoreria.php?kind=expense','tesoreria.php?kind=transfer','tesoreria.php?kind=opening','ingresos-egresos.php','configuracion.php'],
        'ajustes'=>['index.php','empresa.php','grupos.php','usuarios.php'],default=>[]};
    foreach($routes as $route){$url=authBasePath().'/modules/'.$module.'/'.$route;if(permissionUrlAllowed($url))return $url;}
    return null;
}
function permissionRedirectDocument(string $destination,string $fallback,string $flashKey): never
{
    if(permissionUrlAllowed($destination)){header('Location: '.$destination,true,303);exit;}
    $_SESSION[$flashKey]='Documento generado correctamente. Tu grupo no tiene acceso a su ficha.';
    header('Location: '.$fallback,true,303);exit;
}
function permissionUrlAllowed(string $url): bool
{
    if(!permissionRuntime())return true;
    $path=parse_url($url,PHP_URL_PATH);if(!is_string($path)||!str_starts_with($path,authBasePath().'/modules/'))return true;
    parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    if($path===authBasePath().'/modules/dashboard/index.php'||$path===authBasePath().'/modules/dashboard/resumen.php') {
        foreach(['business','inventory','tasks','cash_flow','commissions'] as $section)if(permissionHas('dashboard.'.$section))return true;return false;
    }
    $resource=permissionRoute($path,$query);if(!$resource)return false;
    if(!permissionHas($resource))return false;
    $id=accessId($query['id']??null);
    if($path===authBasePath().'/modules/ventas/producto.php')$id=null;
    if($path===authBasePath().'/modules/inventario/informes.php')$id=null;
    if($id&&!permissionRecord($resource,$id))return false;
    if($resource==='inventario.deliveries'&&isset($query['new'])&&permissionScope($resource,'create')==='own')return false;
    if($resource==='ventas.commission_payments'&&isset($query['new'])&&permissionScope('ventas.commissions','pay')==='own'&&isset($query['seller_id'])&&accessId($query['seller_id'])!==permissionContact())return false;
    if($resource==='ajustes.company'&&isset($query['new']))return (bool)permissionUser()['administrator'];
    if(isset($query['new']))return permissionHas($resource,$resource==='ventas.commission_payments'?'read':(str_ends_with($resource,'.configuration')?'configure':'create'))
        &&($resource!=='ventas.commission_payments'||(permissionHas('ventas.commissions','pay')&&permissionHas('contabilidad.expense','create')&&permissionHas('contabilidad.expense','confirm')));
    return true;
}
function permissionFilterContacts(array $records): array
{
    if(!permissionRuntime())return $records;
    $scope=permissionScope('contactos.contacts');if($scope==='all')return $records;$ids=permissionContacts();
    return array_values(array_filter($records,static fn($r)=>$scope==='own'&&(in_array((int)$r['id'],$ids,true)||in_array((int)($r['salesperson_id']??0),$ids,true))));
}
function permissionGuard(bool $json=false): void
{
    $GLOBALS['permission_runtime']=true;$GLOBALS['permission_user']=authRequireLogin($json);
    $path=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');$resource=permissionRoute($path,$_GET);
    if(in_array($path,[authBasePath().'/modules/dashboard/index.php',authBasePath().'/modules/dashboard/resumen.php'],true)) {
        if(!permissionUrlAllowed($path))authStop(403,'No tienes permiso para consultar el Dashboard.',$json);return;
    }
    $db=createDatabaseConnection();$id=accessId($_GET['id']??null);
    if($path===authBasePath().'/modules/contabilidad/tesoreria.php') {
        if(!$id&&(!isset($_GET['kind'])||in_array($_GET['kind'],['reversal','payment_reversal'],true))&&!isset($_GET['new'])&&$_SERVER['REQUEST_METHOD']==='GET') {
            foreach(['income','expense','transfer','opening'] as $kind)if(permissionHas('contabilidad.'.$kind)){$resource='contabilidad.'.$kind;break;}
            if(!permissionHas($resource))foreach(['customer','supplier'] as $type)if(permissionHas(permissionPaymentResource($type))){$resource=permissionPaymentResource($type);break;}
        }
    }
    if(!$resource)authStop(403,'Esta sección no está autorizada.',$json);
    $GLOBALS['permission_resource']=$resource;
    if(str_ends_with($path,'/index.php')&&!$id&&!isset($_GET['new'])&&!isset($_GET['type'])&&$_SERVER['REQUEST_METHOD']==='GET'&&!permissionHas($resource)) {
        $module=basename(dirname($path));$url=permissionModuleUrl($module);
        if($url&&$url!==$path){header('Location: '.$url,true,303);exit;}
    }
    $recordId=str_ends_with($path,'/producto.php')||str_ends_with($path,'/informes.php')?null:$id;
    permissionRequire($resource,'read',$recordId,$json);
    if($resource==='dashboard.cash_flow')permissionRequire('contabilidad.cash_flow','read',null,$json);
    if(str_ends_with($path,'/producto.php')){permissionRequire('inventario.products','read',null,$json);return;}
    if(in_array($path,[authBasePath().'/modules/ajustes/index.php',authBasePath().'/modules/ajustes/logo.php',authBasePath().'/modules/inventario/informes.php',authBasePath().'/modules/contabilidad/ingresos-egresos.php',authBasePath().'/modules/dashboard/ingresos-egresos.php'],true))return;
    if($resource==='ajustes.company'&&isset($_GET['new']))authStop(404,'La página solicitada no existe.',$json);
    if($resource==='inventario.deliveries'&&isset($_GET['new'])&&permissionScope($resource,'create')==='own')authStop(403,'Genera la entrega desde una venta asignada a tu vendedor.',$json);
    if(in_array($resource,['ventas.commissions','ventas.commission_payments'],true)&&permissionScope($resource)==='own'&&isset($_GET['seller_id'])&&$_GET['seller_id']!==''&&!in_array(accessId($_GET['seller_id']),permissionContacts(),true))authStop(403,'Solo puedes consultar las comisiones de tus vendedores autorizados.',$json);
    if(isset($_GET['new']))permissionRequire($resource,$resource==='ventas.commission_payments'?'read':(str_ends_with($resource,'.configuration')?'configure':'create'),null,$json);
    if($resource==='ventas.commission_payments'&&isset($_GET['new']))permissionRequire('ventas.commissions','pay',accessId($_GET['invoice_id']??null),$json);
    if($resource==='ventas.commission_payments'&&isset($_GET['new'])&&permissionScope('ventas.commissions','pay')==='own'&&isset($_GET['seller_id'])&&accessId($_GET['seller_id'])!==permissionContact())authStop(403,'Solo puedes pagar comisiones de tu vendedor.',$json);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $companyTable=companyResourceTable($resource);
        if(!$companyTable&&str_ends_with($resource,'.configuration')) {
            require_once __DIR__.'/company-view.php';$companyTable=companyViewTable($_SERVER['REQUEST_URI']??$path);
        }
        if($recordId&&$companyTable) {
            $ownership=$db->prepare('SELECT company_id FROM '.$companyTable.' WHERE id=?');$ownership->execute([$recordId]);$owner=$ownership->fetchColumn();
            if($owner&&(int)$owner!==companyActiveId())authStop(409,'Activa la empresa de este registro antes de modificarlo.',$json);
        }
        if(!authCheckCsrf($_POST['csrf']??null))authStop(403,'La sesión del formulario no es válida. Recarga la página.',$json);
        $target=$resource==='ventas.commission_payments'?'ventas.commissions':$resource;
        $targetId=$resource==='ventas.commission_payments'?accessId($_GET['invoice_id']??null):$recordId;
        $action=permissionAction($target,$_POST['action']??null,$targetId);
        if(($_POST['action']??null)==='apply_existing_payment')$action='register_payment';
        if(str_contains($target,'_invoices')&&($_POST['action']??null)==='cancel_payment')$action='unallocate';
        if(!$action)authStop(400,'La acción solicitada no es válida.',$json);
        permissionRequire($target,$action,$targetId,$json);
        permissionGuardInput($db,$target,$targetId,$action,$json);
    }
}
function permissionGuardInput(PDO $db,string $resource,?int $id,string $action,bool $json): void
{
    if(in_array($resource,['ventas.orders','contactos.contacts'],true)&&in_array($action,['create','edit'],true)&&permissionScope($resource,$action)==='own') {
        $seller=permissionContact();if(!$seller)authStop(403,'Relaciona tu usuario con su contacto vendedor antes de crear registros propios.',$json);
        if(isset($_POST['salesperson_id'])&&$_POST['salesperson_id']!==''&&accessId($_POST['salesperson_id'])!==$seller)authStop(403,'Solo puedes asignar tu propio vendedor.',$json);
        $_POST['salesperson_id']=(string)$seller;
        if($resource==='contactos.contacts'&&$id!==$seller){$client=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();if(accessId($_POST['type_id']??null)!==$client)authStop(403,'Con alcance Propios puedes crear o editar clientes asignados a tu vendedor.',$json);}
    }
    if($resource==='inventario.deliveries'&&permissionScope($resource,$action)==='own'&&in_array($action,['create','edit'],true)) {
        // Las entregas propias se generan desde la venta y conservan ese origen.
        if(!$id)authStop(403,'Genera la entrega desde una venta asignada a tu vendedor.',$json);
    }
    foreach(['customer_id','supplier_id','contact_id'] as $field)if($resource!=='ajustes.users'&&($related=accessId($_POST[$field]??null))&&in_array($action,['create','edit'],true))permissionRequire('contactos.contacts','read',$related,$json);
    if($resource==='ajustes.users'&&in_array($action,['create','edit'],true)) {
        $old=$id?loadAuthUser($db,$id):null;$groups=$_POST['groups']??[];$previous=$old['group_ids']??[];
        if(!permissionHas($resource,'assign_groups')&&!isset($_POST['groups'])){$groups=$previous;$_POST['groups']=$previous;}
        if($old&&!permissionHas($resource,'archive')&&!isset($_POST['active']))$_POST['active']=(string)$old['active'];
        if($old&&!permissionHas('contactos.contacts')&&!isset($_POST['contact_id']))$_POST['contact_id']=$old['contact_id'];
        if(($related=accessId($_POST['contact_id']??null))&&$related!==(isset($old['contact_id'])?(int)$old['contact_id']:null))permissionRequire('contactos.contacts','read',$related,$json);
        if(!is_array($groups))$groups=[];$groups=array_map('intval',$groups);sort($groups);sort($previous);
        if($groups!==$previous)permissionRequire($resource,'assign_groups',$id,$json);
        if($old&&(int)$old['active']!==(int)($_POST['active']??0))permissionRequire($resource,'archive',$id,$json);
    }
    if($resource==='ajustes.groups'&&$id&&$action==='edit') {$old=loadAccessGroup($db,$id);if($old&&!permissionHas($resource,'archive')&&!isset($_POST['active']))$_POST['active']=(string)$old['active'];if($old&&(int)$old['active']!==(int)($_POST['active']??0))permissionRequire($resource,'archive',$id,$json);}
    if(str_contains($resource,'_invoices')&&$id&&$action==='confirm'&&!permissionRecord($resource,$id,'edit')) {
        $q=$db->prepare('SELECT invoice_date,due_date FROM accounting_invoices WHERE id=?');$q->execute([$id]);$old=$q->fetch();
        foreach(['invoice_date','due_date'] as $field)if($old&&isset($_POST[$field])&&$_POST[$field]!==($old[$field]??''))permissionRequire($resource,'edit',$id,$json);
        if(is_array($_POST['credit_quantities']??null)){$q=$db->prepare('SELECT id,quantity FROM accounting_invoice_lines WHERE invoice_id=?');$q->execute([$id]);$lines=$q->fetchAll(PDO::FETCH_KEY_PAIR);foreach($_POST['credit_quantities'] as $line=>$quantity)if(isset($lines[$line])&&is_string($quantity)&&is_numeric($quantity)&&bccomp($quantity,$lines[$line],3)!==0)permissionRequire($resource,'edit',$id,$json);}
    }
    if($resource==='inventario.products'&&in_array($action,['create','edit'],true)) {
        $q=$db->prepare('SELECT cost_price,commission_type,commission_value FROM inventory_products WHERE id=?');$q->execute([$id]);$old=$q->fetch()?:['cost_price'=>'0.00','commission_type'=>'none','commission_value'=>'0.0000'];
        if(!permissionHas($resource,'view_cost')){if(isset($_POST['cost_price'])&&is_string($_POST['cost_price'])&&is_numeric($_POST['cost_price'])&&bccomp($_POST['cost_price'],$old['cost_price'],2)!==0)authStop(403,'No puedes modificar el costo del producto.',$json);$_POST['cost_price']=$old['cost_price'];unset($_POST['supplier_prices']);}
        if(!permissionHas($resource,'set_commission')){if((isset($_POST['commission_type'])&&$_POST['commission_type']!==$old['commission_type'])||(isset($_POST['commission_value'])&&is_string($_POST['commission_value'])&&is_numeric($_POST['commission_value'])&&bccomp($_POST['commission_value'],$old['commission_value'],4)!==0))authStop(403,'No puedes modificar la configuración de comisión.',$json);$_POST['commission_type']=$old['commission_type'];$_POST['commission_value']=$old['commission_value'];}
    }
    if(($invoice=accessId($_POST['invoice_id']??null))&&in_array($action,['allocate','unallocate'],true)) {
        $q=$db->prepare('SELECT invoice_type FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);if($type=$q->fetchColumn())permissionRequire(permissionInvoiceResource($type),'read',$invoice,$json);
    }
    if(str_contains($resource,'_invoices')&&($_POST['action']??null)==='apply_existing_payment'&&($payment=accessId($_POST['existing_payment_id']??null))) {
        $q=$db->prepare('SELECT partner_type FROM accounting_payments WHERE id=?');$q->execute([$payment]);if($type=$q->fetchColumn())permissionRequire(permissionPaymentResource($type),'allocate',$payment,$json);
    }
    if(($allocation=accessId($_POST['payment_id']??null))&&str_contains($resource,'_invoices')&&in_array($action,['settle_payment','unallocate'],true)) {
        $q=$db->prepare('SELECT p.id,p.partner_type FROM accounting_invoice_payments l JOIN accounting_payments p ON p.id=l.payment_id WHERE l.id=? AND l.invoice_id=?');$q->execute([$allocation,$id]);
        if($payment=$q->fetch())permissionRequire(permissionPaymentResource($payment['partner_type']),$action,(int)$payment['id'],$json);
    }
    if($action==='pay'){permissionRequire('ventas.commissions','pay',$id,$json);permissionRequire('contabilidad.expense','create',null,$json);permissionRequire('contabilidad.expense','confirm',null,$json);}
    if($resource==='contabilidad.expense'&&$action==='reverse'&&$id){$q=$db->prepare('SELECT invoice_id FROM sales_commission_payments WHERE treasury_document_id=?');$q->execute([$id]);if($invoice=$q->fetchColumn())permissionRequire('ventas.commissions','reverse',(int)$invoice,$json);}
    if(str_starts_with($resource,'contabilidad.')&&in_array($action,['create','edit'],true)&&isset($_POST['kind'])&&!in_array($resource,[permissionInvoiceResource('customer'),permissionInvoiceResource('supplier'),permissionPaymentResource('customer'),permissionPaymentResource('supplier')],true)) {
        if('contabilidad.'.($_POST['kind']??'')!==$resource)permissionRequire('contabilidad.'.(is_string($_POST['kind'])?$_POST['kind']:''),$action,$id,$json);
    }
}

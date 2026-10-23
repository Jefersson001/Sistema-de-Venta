<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';

function transferFieldLabel(string $name): string
{
    $labels=['id'=>'ID del registro','version'=>'Versión','company_id'=>'Empresa (ID)','created_by'=>'Creado por (ID)','created_at'=>'Fecha de creación','updated_by'=>'Modificado por (ID)','updated_at'=>'Fecha de modificación',
        'name'=>'Nombre / referencia','description'=>'Descripción','code'=>'Código','entity_kind'=>'Persona o empresa','type_id'=>'Tipo de contacto (ID)','tax_id'=>'Documento / impuesto (ID en líneas)','document_type_id'=>'Tipo de documento (ID)',
        'email'=>'Correo','phone'=>'Teléfono','mobile'=>'Móvil','website'=>'Sitio web','address'=>'Dirección','postal_code'=>'Código postal','country_id'=>'País (ID)','state_id'=>'Estado (ID)','city_id'=>'Ciudad (ID)','notes'=>'Notas','active'=>'Activo',
        'salesperson_id'=>'Vendedor (ID)','warehouse_id'=>'Almacén (ID)','location_id'=>'Ubicación (ID)','partner_location_id'=>'Ubicación externa (ID)','parent_id'=>'Registro superior (ID)',
        'sale_enabled'=>'Disponible para venta','purchase_enabled'=>'Disponible para compra','sale_price'=>'Precio de venta','cost_price'=>'Costo','currency_code'=>'Moneda','category_id'=>'Categoría (ID)','purchase_tax_id'=>'Impuesto de compra (ID)','sale_tax_id'=>'Impuesto de venta (ID)',
        'product_type'=>'Tipo de producto','invoice_policy'=>'Política de facturación','commission_type'=>'Tipo de comisión','commission_value'=>'Valor de comisión','location_type'=>'Tipo de ubicación',
        'customer_id'=>'Cliente (ID)','supplier_id'=>'Proveedor (ID)','contact_id'=>'Contacto (ID)','order_date'=>'Fecha de orden','order_type'=>'Tipo de orden','payment_term_id'=>'Plazo de pago (ID)',
        'invoice_address'=>'Dirección de factura','delivery_address'=>'Dirección de entrega','destination_location_id'=>'Ubicación de destino (ID)','exchange_rate'=>'Tasa de cambio','rate'=>'Tasa / porcentaje','days'=>'Días',
        'amount'=>'Importe','payment_date'=>'Fecha de pago','reference'=>'Referencia','account_id'=>'Cuenta (ID)','method_id'=>'Medio de pago (ID)','concept_id'=>'Concepto (ID)','direction'=>'Dirección del pago',
        'scheduled_date'=>'Fecha programada','source_warehouse_id'=>'Almacén de origen (ID)','source_location_id'=>'Ubicación de origen (ID)','destination_warehouse_id'=>'Almacén de destino (ID)',
        'destination_account_id'=>'Cuenta de destino (ID)','destination_currency_code'=>'Moneda de destino','destination_amount'=>'Importe de destino','movement_date'=>'Fecha del movimiento',
        'account_type'=>'Tipo de cuenta','symbol'=>'Símbolo','unit_label'=>'Nombre de unidad','subunit_label'=>'Nombre de subunidad','symbol_position'=>'Posición del símbolo','primary_code'=>'Moneda principal','alternate_code'=>'Moneda alterna',
        'legal_name'=>'Razón social','login'=>'Usuario de acceso','invoice_id'=>'Factura (ID)','invoice_date'=>'Fecha de factura','due_date'=>'Fecha de vencimiento',
        'product_id'=>'Producto (ID)','quantity'=>'Cantidad','unit_price'=>'Precio unitario','demand'=>'Cantidad prevista','document_key'=>'Clave del documento (agrupar líneas)',
        'status'=>'Estado','base_amount'=>'Base imponible','tax_amount'=>'Impuestos','total_amount'=>'Total','company_name'=>'Empresa',
        'price'=>'Precio de proveedor','rate_date'=>'Fecha de la tasa','base_currency_id'=>'Moneda base (ID)',
        'allow_change'=>'Permitir vuelto','received_amount'=>'Efectivo / importe recibido','change_amount'=>'Vuelto entregado','change_enabled'=>'Vuelto habilitado en este pago','flow_amount'=>'Importe neto para ingresos y egresos','cash_role'=>'Tipo de movimiento de efectivo'];
    return $labels[$name]??ucfirst(str_replace('_',' ',$name));
}

function transferSchema(PDO $db,string $table): array
{
    static $cache=null;$cache??=new WeakMap();$tables=$cache[$db]??[];if(isset($tables[$table]))return $tables[$table];
    companyQuoteIdentifier($table);$fields=[];
    foreach($db->query('SHOW COLUMNS FROM '.$table)->fetchAll() as $column) {
        $type=$column['Type'];$enum=[];if(str_starts_with($type,'enum(')){preg_match_all("/'([^']*)'/",$type,$matches);$enum=$matches[1];}
        $fields[$column['Field']]=['label'=>transferFieldLabel($column['Field']),'sql_type'=>$type,'enum'=>$enum,'nullable'=>$column['Null']==='YES','default'=>$column['Default'],
            'required'=>$column['Null']==='NO'&&$column['Default']===null&&!str_contains($column['Extra'],'auto_increment')&&!str_contains($column['Extra'],'GENERATED'),
            'type'=>str_contains($type,'int')?(str_starts_with($type,'tinyint(1)')?'boolean':'int'):(preg_match('/decimal|float|double/',$type)?'decimal':(str_starts_with($type,'date')?'date':'text'))];
    }
    $q=$db->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');$q->execute([$table]);
    foreach($q->fetchAll() as $fk)$fields[$fk['COLUMN_NAME']]['relation']=[$fk['REFERENCED_TABLE_NAME'],$fk['REFERENCED_COLUMN_NAME']];
    $tables[$table]=$fields;$cache[$db]=$tables;return $fields;
}

function transferFields(PDO $db,array $model): array
{
    $schema=transferSchema($db,$model['table']);$fields=[];
    $private=array_merge(['password_hash','auth_version','creation_key','logo_data','logo_mime','parent_scope','warehouse_scope'],$model['private']??[]);
    foreach($schema as $name=>$field)if(!in_array($name,$private,true)&&!str_contains($field['sql_type'],'blob'))$fields[$name]=$field+['write'=>in_array($name,$model['write'],true),'export'=>true];
    foreach($model['synthetic']??[] as $name=>$field)$fields[$name]=array_replace(['label'=>transferFieldLabel($name),'type'=>'text','required'=>false,'default'=>null,'write'=>true,'export'=>false],$field);
    if(!empty($model['line_write']))$fields['document_key']=['label'=>transferFieldLabel('document_key'),'type'=>'text','required'=>false,'write'=>true,'export'=>true,'default'=>null];
    if(isset($model['line_table']))foreach(transferSchema($db,$model['line_table']) as $name=>$field) {
        if(in_array($name,['id',$model['line_fk'],'company_id','created_by','updated_by','created_at','updated_at','position','original_line_id'],true)||str_contains($name,'scope'))continue;
        $fields['line.'.$name]=array_replace($field,['label'=>'Línea: '.$field['label'],'write'=>in_array($name,$model['line_write']??[],true),'export'=>true]);
    }
    if(isset($schema['company_id']))$fields['company_name']=['label'=>'Empresa','type'=>'text','required'=>false,'write'=>false,'export'=>true,'default'=>null];
    if(($model['reader']??'')==='commissions')foreach(['commission_generated'=>'Comisión generada','commission_earned'=>'Disponible por cobros','commission_paid'=>'Comisión pagada','commission_pending'=>'Pendiente de pago','commission_overpaid'=>'Ajuste por recuperar','sales_amount'=>'Monto vendido sin impuestos'] as $name=>$label)
        $fields[$name]=['label'=>$label,'type'=>'decimal','required'=>false,'write'=>false,'export'=>true,'default'=>null];
    if($model['key']==='stock')foreach(['reserved'=>'Reservado','available'=>'Disponible'] as $name=>$label)$fields[$name]=['label'=>$label,'type'=>'decimal','required'=>false,'write'=>false,'export'=>true,'default'=>null];
    foreach($fields as $name=>&$field) {
        $field['label']=$model['labels'][$name]??$field['label'];
        if(in_array($name,['invoice_address','delivery_address','notes','reference'],true))$field['required']=false;
        if($model['save']==='treasury'&&$name==='concept_id')$field['required']=in_array($model['key'],['income','expense'],true);
        if($model['save']==='treasury'&&$name==='method_id')$field['required']=$model['key']!=='opening';
        if($name==='tax_id'&&in_array($model['key'],['contacts','companies'],true))$field['label']='Documento / RIF';
        if($model['key']==='users'&&in_array($name,['groups','company_ids'],true))$field['export']=true;
        if($model['key']==='groups'&&in_array($name,['permissions','scopes'],true))$field['export']=true;
        if($model['key']==='products'&&str_starts_with($name,'line.')&&!permissionHas('inventario.products','view_cost')){unset($fields[$name]);continue;}
        if(($name==='cost_price'||$name==='line.unit_cost')&&!permissionHas('inventario.products','view_cost'))unset($fields[$name]);
        elseif(in_array($name,['commission_type','commission_value'],true)&&!permissionHas('inventario.products','set_commission'))$field['write']=false;
    }unset($field);
    return $fields;
}

function transferNormalize(PDO $db,string $name,string $value,array $field): mixed
{
    if($field['type']==='password')return $value;
    $value=trim($value);
    if($value==='')return $field['type']==='boolean'?'0':'';
    if(!empty($field['relation'])) {
        [$table,$column]=$field['relation'];
        if(preg_match('/^[1-9][0-9]*$/D',$value))return $value;
        if($column!=='id')return $value;
        $schema=transferSchema($db,$table);$choices=[];
        foreach(['name','code','system_key','login'] as $key)if(isset($schema[$key]))$choices[]=$key.'=?';
        if(!$choices)throw new InvalidArgumentException($field['label'].': utiliza un ID.');
        $q=$db->prepare('SELECT id FROM '.companyQuoteIdentifier($table).' WHERE '.implode(' OR ',$choices).' LIMIT 2');$q->execute(array_fill(0,count($choices),$value));$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)throw new InvalidArgumentException($field['label'].': no existe una coincidencia única; utiliza el ID.');return (string)$ids[0];
    }
    if($field['type']==='boolean') {
        $v=mb_strtolower($value);if(in_array($v,['1','sí','si','true'],true))return '1';if(in_array($v,['0','no','false'],true))return '0';
        throw new InvalidArgumentException($field['label'].': usa 1 o 0.');
    }
    if($field['type']==='int'&&!preg_match('/^[0-9]+$/D',$value))throw new InvalidArgumentException($field['label'].': utiliza un entero no negativo.');
    if($field['type']==='decimal'){$value=str_replace(',','.',$value);if(!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$value))throw new InvalidArgumentException($field['label'].': importe inválido.');}
    if($field['type']==='date'){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException($field['label'].': usa AAAA-MM-DD.');}
    if(!empty($field['enum'])&&!in_array($value,$field['enum'],true))throw new InvalidArgumentException($field['label'].': valores permitidos: '.implode(', ',$field['enum']).'.');
    if(preg_match('/^(?:var)?char\(([0-9]+)\)/',$field['sql_type']??'',$m)&&mb_strlen($value,'UTF-8')>(int)$m[1])throw new InvalidArgumentException($field['label'].': supera '.$m[1].' caracteres.');
    return $value;
}

<?php
declare(strict_types=1);
require_once __DIR__.'/company-catalog.php';

/** One declaration per model, never per tree/form. Save adapters own business rules. */
function transferModels(): array
{
    $models=[];
    $add=static function(string $key,string $table,string $label,string $resource,string $save,string $fields,array $extra=[])use(&$models): void {
        $models[$key]=array_replace(['key'=>$key,'table'=>$table,'label'=>$label,'resource'=>$resource,'save'=>$save,
            'write'=>array_values(array_filter(explode(',',$fields))),'domain'=>[],'defaults'=>[],'search'=>['name'],'admin'=>false],$extra);
    };
    $add('contacts','contacts','Contactos','contactos.contacts','contacts','name,entity_kind,type_id,tax_id,document_type_id,email,phone,mobile,website,address,postal_code,country_id,state_id,city_id,notes,active,salesperson_id,warehouse_id,location_id,partner_location_id',['defaults'=>['entity_kind'=>'person','active'=>'1']]);
    $add('products','inventory_products','Productos','inventario.products','products','name,parent_product_id,sale_enabled,purchase_enabled,sale_price,cost_price,currency_code,category_id,purchase_tax_id,sale_tax_id,product_type,invoice_policy,commission_type,commission_value',['defaults'=>['sale_enabled'=>'1','purchase_enabled'=>'1','product_type'=>'goods','invoice_policy'=>'delivered','commission_type'=>'none','commission_value'=>'0','cost_price'=>'0'],'line_table'=>'product_supplier_prices','line_fk'=>'product_id','line_write'=>['supplier_id','price']]);
    foreach(['categories'=>['inventory_categories','Categorías','name,parent_id,restaurant_enabled'],'warehouses'=>['inventory_warehouses','Almacenes','name'],'locations'=>['inventory_locations','Ubicaciones','name,warehouse_id,parent_id,location_type']] as $key=>[$table,$label,$fields])$add($key,$table,$label,'inventario.configuration',$key,$fields);
    foreach(['countries'=>['contact_countries','Países','name,code'],'states'=>['contact_states','Estados','name,country_id'],'cities'=>['contact_cities','Ciudades','name,state_id'],'types'=>['contact_types','Tipos de contacto','name'],'document_types'=>['contact_document_types','Tipos de documento','name,code']] as $key=>[$table,$label,$fields])$add($key,$table,$label,'contactos.configuration','contact_catalog',$fields,['section'=>$key]);
    foreach(['sale','purchase'] as $kind) {
        $sale=$kind==='sale';$add($kind.'_taxes','business_taxes',$sale?'Impuestos de ventas':'Impuestos de compras',($sale?'ventas':'compras').'.configuration','taxes','name,rate',['domain'=>['module_key'=>$sale?'ventas':'compras']]);
        $add($kind.'_orders',$sale?'sales_quotations':'purchase_orders',$sale?'Ventas y cotizaciones':'Compras',($sale?'ventas':'compras').'.orders',$kind.'_orders',($sale?'customer_id,salesperson_id,delivery_address,warehouse_id,location_id':'supplier_id,destination_location_id').',order_date,order_type,payment_term_id,invoice_address,currency_code,exchange_rate',[
            'defaults'=>['order_type'=>$sale?'delivery':'receipt'],'line_table'=>$sale?'sales_quotation_lines':'purchase_order_lines','line_fk'=>$sale?'quotation_id':'order_id',
            'line_write'=>['product_id','quantity','unit_price','tax_id'],'kind'=>$kind]);
    }
    foreach(['customer','supplier'] as $type) {
        $add($type.'_invoices','accounting_invoices',$type==='customer'?'Facturas de clientes':'Facturas de proveedores','contabilidad.'.$type.'_invoices','invoices','source_order_id,invoice_date,due_date',[
            'domain'=>['invoice_type'=>$type],'type'=>$type,'line_table'=>'accounting_invoice_lines','line_fk'=>'invoice_id','line_write'=>[],
            'synthetic'=>['source_order_id'=>['label'=>'Orden de origen (ID)','required'=>true,'type'=>'int']]]);
        $add($type.'_payments','accounting_payments',$type==='customer'?'Cobros de clientes':'Pagos a proveedores','contabilidad.'.$type.'_payments','payments','contact_id,amount,received_amount,change_amount,currency_code,exchange_rate,payment_date,reference,account_id,method_id,concept_id,direction',[
            'domain'=>['partner_type'=>$type],'defaults'=>['partner_type'=>$type,'direction'=>$type==='customer'?'inbound':'outbound'],'type'=>$type]);
    }
    foreach(['delivery','receipt','internal'] as $type)$add($type.'_operations','inventory_operations',['delivery'=>'Entregas','receipt'=>'Recepciones','internal'=>'Traslados'][$type],'inventario.'.['delivery'=>'deliveries','receipt'=>'receipts','internal'=>'internal'][$type],'operations','scheduled_date,contact_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,notes',[
        'domain'=>['operation_type'=>$type],'type'=>$type,'line_table'=>'inventory_operation_lines','line_fk'=>'operation_id','line_write'=>['product_id','demand','quantity']]);
    foreach(['income','expense','transfer','opening'] as $kind)$add($kind,'accounting_treasury_documents',['income'=>'Otros ingresos','expense'=>'Otros egresos','transfer'=>'Transferencias','opening'=>'Saldos iniciales'][$kind],'contabilidad.'.$kind,'treasury','account_id,destination_account_id,currency_code,destination_currency_code,amount,destination_amount,exchange_rate,movement_date,concept_id,contact_id,method_id,reference',[
        'domain'=>['kind'=>$kind],'defaults'=>['kind'=>$kind]]);
    $add('treasury','accounting_treasury_documents','Movimientos de tesorería','contabilidad.income','readonly','');
    $add('payment_terms','accounting_payment_terms','Plazos de pago','contabilidad.configuration','payment_terms','name,days');
    $add('accounts','accounting_payment_accounts','Cajas y bancos','contabilidad.configuration','accounts','name,account_type,currency_code',['defaults'=>['currency_code'=>'']]);
    $add('methods','accounting_payment_methods','Medios de pago','contabilidad.configuration','methods','name,allow_change');
    $add('concepts','accounting_cash_concepts','Conceptos de tesorería','contabilidad.configuration','concepts','name,direction,active',['defaults'=>['active'=>'1','direction'=>'both']]);
    $add('currencies','accounting_currencies','Monedas','contabilidad.configuration','currencies','code,name,symbol,unit_label,subunit_label,symbol_position,active',['defaults'=>['symbol_position'=>'before','active'=>'1'],'line_table'=>'accounting_currency_rates','line_fk'=>'currency_id','line_write'=>['base_currency_id','rate_date','rate']]);
    $add('currency_settings','accounting_currency_settings','Configuración de monedas','contabilidad.configuration','currency_settings','primary_code,alternate_code',['singleton'=>true,'search'=>[]]);
    $add('companies','business_company','Empresas','ajustes.company','companies','name,legal_name,tax_id,address,phone,email,website',['admin'=>true]);
    $add('users','auth_users','Usuarios','ajustes.users','users','name,login,email,contact_id,active,password,groups,company_ids',['admin'=>true,'defaults'=>['active'=>'1'],'search'=>['name','login','email'],
        'synthetic'=>['password'=>['label'=>'Contraseña temporal (solo creación)','type'=>'password'],'groups'=>['label'=>'Grupos (IDs separados por |)','type'=>'list'],'company_ids'=>['label'=>'Empresas (IDs separados por |)','type'=>'list']]]);
    $add('groups','access_groups','Grupos de acceso','ajustes.groups','groups','name,description,active,permissions,scopes',['admin'=>true,'defaults'=>['active'=>'1'],
        'synthetic'=>['permissions'=>['label'=>'Permisos (códigos separados por |)','type'=>'list'],'scopes'=>['label'=>'Alcances por recurso (JSON)','type'=>'json']]]);
    $add('commissions','accounting_invoices','Comisiones','ventas.commissions','readonly','',['reader'=>'commissions','domain'=>['invoice_type'=>'customer']]);
    $add('commission_payments','sales_commission_payments','Pagos de comisiones','ventas.commission_payments','commission_payments','invoice_id,amount,payment_date,account_id,method_id,currency_code,exchange_rate,reference',[
        'create_only'=>true,'synthetic'=>array_fill_keys(['payment_date','account_id','method_id','currency_code','exchange_rate','reference'],['type'=>'text'])]);
    foreach(['stock'=>['inventory_stock','Existencias','inventario.stock'],'movements'=>['inventory_stock_moves','Movimientos de existencias','inventario.movements'],'sold'=>['sales_quotation_lines','Productos vendidos','ventas.orders'],'purchased'=>['purchase_order_lines','Productos comprados','compras.orders']] as $key=>[$table,$label,$resource])$add($key,$table,$label,$resource,'readonly','',['search'=>[]]);
    $add('cash_flow','accounting_treasury_entries','Ingresos y egresos','contabilidad.cash_flow','readonly','',['search'=>[]]);
    $balanceFields=[];foreach(['contact_name'=>'Cliente / proveedor','paid'=>'Pagado confirmado','credited'=>'Notas de crédito','pending'=>'Pagos en proceso','balance'=>'Saldo pendiente','overdue'=>'Vencida'] as $name=>$label)$balanceFields[$name]=['label'=>$label,'type'=>$name==='contact_name'?'text':($name==='overdue'?'boolean':'decimal'),'write'=>false,'export'=>true];
    $add('outstanding','accounting_invoices','Cuentas por cobrar y pagar','contabilidad.customer_invoices','readonly','',['reader'=>'outstanding','synthetic'=>$balanceFields]);
    // New modules register their model here through the same contract.
    foreach(array_replace(require __DIR__.'/config/transfer-models.php',$GLOBALS['transfer_extra_models']??[]) as $key=>$model)$models[$key]=array_replace(['key'=>$key,'domain'=>[],'defaults'=>[],'search'=>['name'],'admin'=>false],$model);
    return $models;
}

function transferRouteModel(string $url): ?array
{
    $path=parse_url($url,PHP_URL_PATH)??'';parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);
    $route=implode('/',array_slice(explode('/',$path),-2));$key=null;
    // Form redirects often retain only the ID. Resolve the actual record subtype.
    $subtypes=['contabilidad/index.php'=>['accounting_invoices','invoice_type','type'],
        'contabilidad/pagos.php'=>['accounting_payments','partner_type','type'],
        'contabilidad/tesoreria.php'=>['accounting_treasury_documents','kind','kind'],
        'inventario/operaciones.php'=>['inventory_operations','operation_type','type']];
    if(isset($subtypes[$route])&&ctype_digit((string)($q['id']??''))&&(int)$q['id']>0) {
        [$table,$column,$param]=$subtypes[$route];$db=createDatabaseConnection();
        $query=$db->prepare('SELECT '.$column.' FROM '.$table.' WHERE id=?');$query->execute([(int)$q['id']]);
        if($value=$query->fetchColumn())$q[$param]=$value;
    }
    if(basename($path)==='configuracion.php') {
        $section=$q['section']??null;if(!is_string($section))return null;
        $key=match(dirname($route)){'contactos'=>in_array($section,['countries','states','cities','types','document_types'],true)?$section:null,
            'inventario'=>in_array($section,['categories','warehouses','locations'],true)?$section:null,
            'ventas','compras'=>$section==='taxes'?(dirname($route)==='ventas'?'sale_taxes':'purchase_taxes'):null,
            'contabilidad'=>in_array($section,['payment_terms','accounts','methods','concepts','currencies','currency_settings'],true)?$section:null,default=>null};
    } else $key=match($route){
        'contactos/index.php'=>'contacts','inventario/index.php'=>'products','ventas/index.php'=>'sale_orders','compras/index.php','compras/orden.php'=>'purchase_orders',
        'contabilidad/index.php'=>($q['type']??'customer')==='supplier'?'supplier_invoices':'customer_invoices',
        'contabilidad/pagos.php'=>($q['type']??'customer')==='supplier'?'supplier_payments':'customer_payments',
        'contabilidad/tesoreria.php'=>in_array($q['kind']??'',['income','expense','transfer','opening'],true)?$q['kind']:'treasury',
        'inventario/operaciones.php'=>in_array($q['type']??'delivery',['delivery','receipt','internal'],true)?($q['type']??'delivery').'_operations':null,
        'ajustes/usuarios.php'=>'users','ajustes/grupos.php'=>'groups','ajustes/empresa.php'=>'companies','ventas/comisiones.php'=>'commissions','ventas/pagos-comisiones.php'=>'commission_payments',
        'inventario/informes.php'=>match($q['view']??'movements'){'stock'=>'stock','sold'=>'sold','purchased'=>'purchased',default=>'movements'},
        'contabilidad/ingresos-egresos.php'=>'cash_flow',default=>null,
    };
    $models=transferModels();
    if($route==='contabilidad/cuentas-pendientes.php'){$model=$models['outstanding'];$model['resource']=permissionHas('contabilidad.customer_invoices')?'contabilidad.customer_invoices':'contabilidad.supplier_invoices';return $model;}
    if($key==='treasury') {
        $model=$models[$key];
        foreach(['income','expense','transfer','opening','customer_payments','supplier_payments'] as $resource)if(permissionHas('contabilidad.'.$resource)){$model['resource']='contabilidad.'.$resource;break;}
        return $model;
    }
    if($key===null)foreach($models as $candidate)if(in_array($route,$candidate['routes']??[],true))return $candidate;
    return $models[$key??'']??null;
}

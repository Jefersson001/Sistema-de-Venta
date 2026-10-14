<?php
declare(strict_types=1);

function accessModules(): array
{
    return ['dashboard'=>'Dashboard','contactos'=>'Contactos','ventas'=>'Ventas','compras'=>'Compras',
        'inventario'=>'Inventario','contabilidad'=>'Contabilidad','ajustes'=>'Ajustes'];
}

function accessActions(): array
{
    return ['read'=>'Consultar','create'=>'Crear','edit'=>'Editar','archive'=>'Archivar',
        'confirm'=>'Confirmar','send'=>'Marcar enviada','cancel'=>'Cancelar',
        'create_delivery'=>'Generar entrega','create_receipt'=>'Generar recepción','create_invoice'=>'Generar factura',
        'reserve'=>'Comprobar disponibilidad','validate'=>'Validar movimiento','return'=>'Crear devolución',
        'credit'=>'Crear nota de crédito','register_payment'=>'Registrar pago','settle_payment'=>'Confirmar pago',
        'allocate'=>'Aplicar a factura','unallocate'=>'Retirar aplicación','pay'=>'Pagar comisión','reverse'=>'Revertir',
        'configure'=>'Configurar','view_cost'=>'Consultar costos','set_commission'=>'Configurar comisión',
        'password'=>'Restablecer contraseña','assign_groups'=>'Asignar grupos'];
}

function accessResources(): array
{
    $specs = [
        ['dashboard','business','Resumen del negocio','read',true],
        ['dashboard','inventory','Alertas de inventario','read',false],
        ['dashboard','tasks','Tareas pendientes','read',true],
        ['dashboard','cash_flow','Ingresos y egresos','read',false],
        ['dashboard','commissions','Comisiones por vendedor','read',true],
        ['contactos','contacts','Contactos','read,create,edit,archive',true],
        ['contactos','configuration','Localización, tipos de contacto y documento','read,configure',false],
        ['ventas','orders','Cotizaciones y órdenes de venta','read,create,edit,send,confirm,cancel,create_delivery,create_invoice',true],
        ['ventas','commissions','Comisiones','read,pay,reverse',true],
        ['ventas','commission_payments','Pagos de comisiones','read',true],
        ['ventas','configuration','Impuestos de venta','read,configure',false],
        ['compras','orders','Solicitudes y órdenes de compra','read,create,edit,send,confirm,cancel,create_receipt,create_invoice',false],
        ['compras','configuration','Impuestos de compra','read,configure',false],
        ['inventario','products','Productos','read,create,edit,view_cost,set_commission',false],
        ['inventario','deliveries','Entregas','read,create,edit,confirm,reserve,validate,cancel,return,create_invoice',true],
        ['inventario','receipts','Recepciones','read,create,edit,confirm,reserve,validate,cancel,return,create_invoice',false],
        ['inventario','internal','Movimientos internos','read,create,edit,confirm,reserve,validate,cancel',false],
        ['inventario','stock','Existencias por ubicación','read',false],
        ['inventario','movements','Historial de movimientos','read',false],
        ['inventario','configuration','Categorías, almacenes y ubicaciones','read,configure',false],
        ['contabilidad','customer_invoices','Facturas y notas de crédito de clientes','read,edit,confirm,send,cancel,credit,register_payment,settle_payment,unallocate',true],
        ['contabilidad','supplier_invoices','Facturas y notas de crédito de proveedores','read,edit,confirm,send,cancel,credit,register_payment,settle_payment,unallocate',false],
        ['contabilidad','customer_payments','Pagos de clientes','read,create,edit,confirm,settle_payment,cancel,allocate,unallocate,reverse',false],
        ['contabilidad','supplier_payments','Pagos de proveedores','read,create,edit,confirm,settle_payment,cancel,allocate,unallocate,reverse',false],
        ['contabilidad','income','Otros ingresos','read,create,edit,confirm,cancel,reverse',false],
        ['contabilidad','expense','Otros egresos','read,create,edit,confirm,cancel,reverse',false],
        ['contabilidad','transfer','Transferencias internas','read,create,edit,confirm,cancel,reverse',false],
        ['contabilidad','opening','Saldos iniciales','read,create,edit,confirm,cancel,reverse',false],
        ['contabilidad','cash_flow','Saldos e historial de cajas y bancos','read',false],
        ['contabilidad','configuration','Monedas, cuentas, medios, conceptos y términos de pago','read,configure',false],
        ['ajustes','general','Ajustes generales','read',false],
        ['ajustes','company','Empresa','read,edit',false],
        ['ajustes','groups','Grupos de acceso','read,create,edit,archive',false],
        ['ajustes','users','Usuarios','read,create,edit,archive,password,assign_groups',false],
    ];
    $result = [];
    foreach ($specs as [$module,$key,$label,$actions,$own]) {
        $resource = $module.'.'.$key;
        $result[$resource] = ['module'=>$module,'label'=>$label,'actions'=>explode(',',$actions),'allows_own'=>$own];
    }
    return $result;
}

function accessPermissionCatalog(): array
{
    $catalog = [];
    foreach (accessResources() as $resource=>$definition) {
        foreach ($definition['actions'] as $action) $catalog[$resource.'.'.$action] = [
            'resource'=>$resource,'module'=>$definition['module'],'action'=>$action,'allows_own'=>$definition['allows_own'],
            'label'=>$definition['label'].' · '.accessActions()[$action],
        ];
    }
    return $catalog;
}

function accessAdministratorMinimum(): array
{
    return array_keys(array_filter(accessPermissionCatalog(),static fn($permission)=>$permission['module']==='ajustes'));
}

function accessInitialGroups(): array
{
    $specs = [
        'administrator'=>['Administrador','Administración del sistema y permisos completos iniciales.',[]],
        'sales'=>['Ventas','Ventas asignadas, clientes relacionados y consulta de sus comisiones.',[
            'dashboard.business'=>['own','read'],'dashboard.tasks'=>['own','read'],'dashboard.commissions'=>['own','read'],
            'contactos.contacts'=>['own','read,create,edit'], 'inventario.products'=>['all','read'],
            'inventario.configuration'=>['all','read'], 'ventas.configuration'=>['all','read'],
            'ventas.orders'=>['own','read,create,edit,send,confirm,cancel,create_delivery,create_invoice'],
            'ventas.commissions'=>['own','read'],'ventas.commission_payments'=>['own','read'],
            'inventario.deliveries'=>['own','read'],'contabilidad.customer_invoices'=>['own','read'],
        ]],
        'purchases'=>['Compras','Compras y proveedores; generación de recepciones y facturas de proveedor.',[
            'dashboard.business'=>['all','read'],'dashboard.tasks'=>['all','read'],
            'contactos.contacts'=>['all','read,create,edit'], 'inventario.products'=>['all','read,view_cost'],
            'inventario.configuration'=>['all','read'],'compras.configuration'=>['all','read'],
            'compras.orders'=>['all','read,create,edit,send,confirm,cancel,create_receipt,create_invoice'],
            'inventario.receipts'=>['all','read'],'contabilidad.supplier_invoices'=>['all','read'],
        ]],
        'inventory'=>['Inventario','Productos, existencias, operaciones y configuración de almacenes.',[
            'dashboard.inventory'=>['all','read'],'dashboard.tasks'=>['all','read'],
            'contactos.contacts'=>['all','read'], 'inventario.products'=>['all','read,create,edit'],
            'inventario.deliveries'=>['all','read,create,edit,confirm,reserve,validate,cancel,return'],
            'inventario.receipts'=>['all','read,create,edit,confirm,reserve,validate,cancel,return'],
            'inventario.internal'=>['all','*'],'inventario.stock'=>['all','read'],
            'inventario.movements'=>['all','read'],'inventario.configuration'=>['all','read,configure'],
        ]],
        'accounting'=>['Contabilidad','Facturas, cobros, pagos, Tesorería y pagos de comisiones.',[
            'dashboard.business'=>['all','read'],'dashboard.tasks'=>['all','read'],'dashboard.cash_flow'=>['all','read'],
            'dashboard.commissions'=>['all','read'],'contactos.contacts'=>['all','read'],
            'inventario.products'=>['all','read,view_cost'],'ventas.orders'=>['all','read'],
            'compras.orders'=>['all','read'],'ventas.commissions'=>['all','*'],'ventas.commission_payments'=>['all','read'],
            'contabilidad.customer_invoices'=>['all','*'],'contabilidad.supplier_invoices'=>['all','*'],
            'contabilidad.customer_payments'=>['all','*'],'contabilidad.supplier_payments'=>['all','*'],
            'contabilidad.income'=>['all','*'],'contabilidad.expense'=>['all','*'],'contabilidad.transfer'=>['all','*'],
            'contabilidad.opening'=>['all','*'],'contabilidad.cash_flow'=>['all','read'],'contabilidad.configuration'=>['all','*'],
        ]],
        'readonly'=>['Consulta','Consulta operativa de productos, contactos y órdenes, sin permisos financieros iniciales.',[
            'dashboard.business'=>['all','read'],'dashboard.inventory'=>['all','read'],'dashboard.tasks'=>['all','read'],
            'contactos.contacts'=>['all','read'],'ventas.orders'=>['all','read'],'compras.orders'=>['all','read'],
            'inventario.products'=>['all','read'],'inventario.deliveries'=>['all','read'],'inventario.receipts'=>['all','read'],
            'inventario.internal'=>['all','read'],'inventario.stock'=>['all','read'],'inventario.movements'=>['all','read'],
        ]],
    ];
    $resources=accessResources(); $result=[];
    foreach ($specs as $key=>[$name,$description,$rows]) {
        $permissions=[];
        if ($key==='administrator') $permissions=array_fill_keys(array_keys(accessPermissionCatalog()),'all');
        else foreach ($rows as $resource=>[$scope,$actions]) {
            foreach ($actions==='*'?$resources[$resource]['actions']:explode(',',$actions) as $action) $permissions[$resource.'.'.$action]=$scope;
        }
        $result[$key]=['name'=>$name,'description'=>$description,'permissions'=>$permissions];
    }
    return $result;
}

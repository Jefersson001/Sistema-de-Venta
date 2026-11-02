<?php
declare(strict_types=1);
require_once __DIR__.'/permissions.php';

/** Searchable destinations are actual list/configuration pages, never actions. */
function navigationSearchCatalog(): array
{
    $entries=[];
    $add=static function(string $module,array $path,string $route,string $keywords='')use(&$entries):void{
        $entries[]=['module'=>$module,'segments'=>$path,'route'=>$route,'keywords'=>$keywords];
    };
    $add('dashboard',['Resumen general'],'index.php','panel indicadores negocio');
    $add('contactos',['Contactos'],'index.php','clientes proveedores vendedores personas empresas');
    $add('contactos',['Contactos archivados'],'index.php?status=archived','inactivos');
    foreach(['countries'=>'Países','states'=>'Estados','municipalities'=>'Municipios','cities'=>'Ciudades','types'=>'Tipos de contacto','document_types'=>'Tipos de documento'] as $section=>$label)$add('contactos',['Configuración',$label],'configuracion.php?section='.$section);
    $add('inventario',['Productos'],'index.php','artículos mercancía servicios catálogo');
    $add('inventario',['Productos','Variantes del producto'],'variantes.php','colores tallas');
    $add('inventario',['Productos','Lotes y números de serie'],'lotes.php','series seriales vencimientos caducidad trazabilidad');
    foreach(['delivery'=>'Entregas','receipt'=>'Recepciones','internal'=>'Movimientos internos'] as $type=>$label)$add('inventario',['Operaciones',$label],'operaciones.php?type='.$type);
    foreach(['movements'=>'Historial de movimientos','stock'=>'Existencias por ubicación'] as $view=>$label)$add('inventario',['Informes',$label],'informes.php?view='.$view,'stock almacén');
    foreach(['categories'=>'Categorías','warehouses'=>'Almacenes','locations'=>'Ubicaciones'] as $section=>$label)$add('inventario',['Configuración',$label],'configuracion.php?section='.$section);
    $add('compras',['Órdenes de compra'],'index.php','solicitudes cotizaciones proveedores');
    $add('compras',['Configuración','Impuestos de compra'],'configuracion.php?section=taxes');
    $add('ventas',['Órdenes de venta'],'index.php','cotizaciones pedidos clientes');
    $add('ventas',['Comisiones','Comisiones'],'comisiones.php','vendedores');
    $add('ventas',['Comisiones','Pagos de comisiones'],'pagos-comisiones.php','pagar vendedores');
    $add('ventas',['Configuración','Impuestos de venta'],'configuracion.php?section=taxes');
    foreach(['customer'=>'Clientes','supplier'=>'Proveedores'] as $type=>$label){
        $add('contabilidad',[$label,'Facturas'],'index.php?type='.$type,'notas crédito');
        $add('contabilidad',[$label,'Pagos'],'pagos.php?type='.$type,$type==='customer'?'cobros cobrar':'pagar egresos');
    }
    $add('contabilidad',['Informes','Ingresos y egresos'],'ingresos-egresos.php','saldos cajas bancos movimientos');
    $add('contabilidad',['Informes','Cuentas por cobrar y por pagar'],'cuentas-pendientes.php','pendientes vencidos facturas');
    foreach(['income'=>'Otros ingresos','expense'=>'Otros egresos','transfer'=>'Transferencias internas','opening'=>'Saldos iniciales'] as $kind=>$label)$add('contabilidad',['Tesorería',$label],'tesoreria.php?kind='.$kind);
    foreach(['currencies'=>'Monedas','currency_settings'=>'Moneda principal y alterna','accounts'=>'Cajas y bancos','methods'=>'Medios de pago','concepts'=>'Conceptos de ingresos y egresos','payment_terms'=>'Términos de pago'] as $section=>$label){
        $keywords=match($section){'currencies','currency_settings'=>'tasa tasas cambio BCV dólar bolívar divisas','methods'=>'métodos pagos','payment_terms'=>'plazos pagos',default=>''};
        $add('contabilidad',['Configuración',$label],'configuracion.php?section='.$section,$keywords);
    }
    $add('restaurante',['Mapa de mesas'],'index.php','salón atención');
    $add('restaurante',['Cuentas'],'cuentas.php','pedidos consumo comensales');
    $add('restaurante',['Configuración','Áreas'],'configuracion.php?section=restaurant_areas');
    $add('restaurante',['Configuración','Mesas'],'configuracion.php?section=restaurant_tables');
    foreach(['index.php'=>'Ajustes generales','empresa.php'=>'Empresa','usuarios.php'=>'Usuarios','grupos.php'=>'Grupos de acceso'] as $route=>$label)$add('ajustes',[$label],$route,$route==='grupos.php'?'roles permisos':'');
    return $entries;
}

function navigationSearchEntries(): array
{
    $modules=accessModules();$result=[];
    foreach(navigationSearchCatalog() as $entry){
        $url=authBasePath().'/modules/'.$entry['module'].'/'.$entry['route'];
        if(!permissionUrlAllowed($url))continue;
        $result[]=['title'=>$entry['segments'][count($entry['segments'])-1],
            'path'=>implode(' / ',[$modules[$entry['module']],...$entry['segments']]),
            'module'=>$entry['module'],'url'=>$url,'keywords'=>$entry['keywords']];
    }
    return $result;
}

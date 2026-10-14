<?php
declare(strict_types=1);

function businessSettingsSections(): array
{
    return [
        'general'=>['name'=>'General','description'=>'Identidad y monedas del negocio.','links'=>[
            ['Empresa','Nombre, documento, dirección y logo del negocio.','empresa.php'],
            ['Monedas del negocio','Moneda principal, alterna y su tasa vigente.','../contabilidad/configuracion.php?section=currency_settings'],
            ['Catálogo de monedas','Nombres, símbolos y tasas por fecha.','../contabilidad/configuracion.php?section=currencies'],
        ]],
        'contactos'=>['name'=>'Contactos','description'=>'Catálogos utilizados por clientes, proveedores y vendedores.','links'=>[
            ['Localización','Países, estados y ciudades.','../contactos/configuracion.php'],
            ['Tipos de contacto','Clasificación de los contactos del negocio.','../contactos/configuracion.php?section=types'],
            ['Tipos de documento','Letras y nombres de los documentos.','../contactos/configuracion.php?section=document_types'],
        ]],
        'ventas'=>['name'=>'Ventas','description'=>'Configuración del proceso de venta y sus comisiones.','links'=>[
            ['Impuestos de venta','Impuestos utilizados en productos y ventas.','../ventas/configuracion.php?section=taxes'],
            ['Productos y comisiones','Configura la comisión fija o porcentual en la pestaña Ventas de cada producto.','../inventario/index.php'],
            ['Comisiones','Consulta los cobros que habilitan la comisión y los pagos al vendedor.','../ventas/comisiones.php'],
        ]],
        'compras'=>['name'=>'Compras','description'=>'Configuración para comprar y recibir productos.','links'=>[
            ['Impuestos de compra','Impuestos utilizados en productos y compras.','../compras/configuracion.php?section=taxes'],
            ['Productos y proveedores','Precios de proveedor en la pestaña Compras de cada producto.','../inventario/index.php'],
            ['Términos de pago','Condiciones y plazos de pago de las órdenes.','../contabilidad/configuracion.php?section=payment_terms'],
        ]],
        'inventario'=>['name'=>'Inventario','description'=>'Organización de productos y lugares de almacenamiento.','links'=>[
            ['Categorías de producto','Clasificación de los productos.','../inventario/configuracion.php?section=categories'],
            ['Almacenes','Almacenes del negocio.','../inventario/configuracion.php?section=warehouses'],
            ['Ubicaciones','Ubicaciones internas y externas de los movimientos.','../inventario/configuracion.php?section=locations'],
        ]],
        'Contabilidad'=>['name'=>'Contabilidad','description'=>'Configuración de facturas, cobros y movimientos de dinero.','links'=>[
            ['Cajas y bancos','Cuentas de ingreso y egreso del negocio.','../contabilidad/configuracion.php?section=accounts'],
            ['Medios de pago','Formas utilizadas para cobrar y pagar.','../contabilidad/configuracion.php?section=methods'],
            ['Conceptos de ingresos y egresos','Clasificación de los movimientos de dinero.','../contabilidad/configuracion.php?section=concepts'],
            ['Términos de pago','Plazos de vencimiento de las facturas.','../contabilidad/configuracion.php?section=payment_terms'],
        ]],
    ];
}

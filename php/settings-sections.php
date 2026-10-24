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
        'accesos'=>['name'=>'Usuarios y accesos','description'=>'Configura quién puede entrar, qué acciones puede realizar y en qué empresas.','links'=>[
            ['Grupos y acceso a módulos','Permisos de todos los módulos, incluidos Restaurante y las variantes de Inventario.','grupos.php'],
            ['Usuarios','Asigna grupos, empresas autorizadas y seguridad de cada cuenta.','usuarios.php'],
            ['Empresas y autorizaciones','Datos de la empresa y usuarios que pueden trabajar en ella.','empresa.php'],
        ]],
        'dashboard'=>['name'=>'Dashboard','description'=>'Visibilidad de los indicadores y acceso a los datos que los alimentan.','links'=>[
            ['Permisos de indicadores','En Grupos de acceso, la pestaña Dashboard habilita resúmenes y alertas según los permisos del usuario.','grupos.php'],
            ['Consultar Dashboard','Comprueba los indicadores disponibles para tu usuario.','../dashboard/index.php'],
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
            ['Productos y variantes','Ficha completa del producto principal y sus variantes con existencias independientes.','../inventario/index.php'],
            ['Variantes del producto','Crear, consultar y editar variantes relacionadas con su producto principal.','../inventario/variantes.php'],
            ['Categorías de producto','Clasificación de los productos.','../inventario/configuracion.php?section=categories'],
            ['Almacenes','Almacenes del negocio.','../inventario/configuracion.php?section=warehouses'],
            ['Ubicaciones','Ubicaciones internas y externas de los movimientos.','../inventario/configuracion.php?section=locations'],
        ]],
        'restaurante'=>['name'=>'Restaurante','description'=>'Mesas, carta, facturación y opciones de cobro del restaurante.','links'=>[
            ['Áreas y mesas','Organiza los salones, las mesas y su ubicación en el mapa.','../restaurante/configuracion.php'],
            ['Categorías de la carta','Marca Es para restaurante en las categorías principales; sus subcategorías se incluyen automáticamente.','../inventario/configuracion.php?section=categories'],
            ['Productos y variantes de la carta','Precios, impuestos y variantes que se utilizan en los pedidos.','../inventario/index.php'],
            ['Cajas y bancos','Cuentas donde se registran los cobros de las mesas.','../contabilidad/configuracion.php?section=accounts'],
            ['Medios de pago y vuelto','Habilita Permitir vuelto en los medios de efectivo y utiliza una cuenta de tipo Caja.','../contabilidad/configuracion.php?section=methods'],
            ['Monedas y tasas','Monedas y tasas utilizadas al facturar y cobrar.','../contabilidad/configuracion.php?section=currencies'],
            ['Permisos de Restaurante','En los grupos de acceso configura Mesero, Caja o Encargado mediante sus plantillas.','grupos.php'],
        ]],
        'contabilidad'=>['name'=>'Contabilidad','description'=>'Configuración de facturas, cobros y movimientos de dinero.','links'=>[
            ['Cajas y bancos','Cuentas de ingreso y egreso del negocio.','../contabilidad/configuracion.php?section=accounts'],
            ['Medios de pago','Formas utilizadas para cobrar, pagar y permitir vuelto en efectivo.','../contabilidad/configuracion.php?section=methods'],
            ['Monedas y tasas','Catálogo de monedas y tasas por fecha.','../contabilidad/configuracion.php?section=currencies'],
            ['Conceptos de ingresos y egresos','Clasificación de los movimientos de dinero.','../contabilidad/configuracion.php?section=concepts'],
            ['Términos de pago','Plazos de vencimiento de las facturas.','../contabilidad/configuracion.php?section=payment_terms'],
        ]],
    ];
}

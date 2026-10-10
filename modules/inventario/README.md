# Inventario

## Operaciones

Ubicaciones admite los tipos Interna, Cliente y Proveedor. Solo las internas requieren almacén y forman parte de Cantidad a la mano. Se crean Clientes y Proveedores como ubicaciones externas predeterminadas. Cada cliente o proveedor puede elegir su ubicación externa en Contactos → Inventario; si no elige una, se usa la predeterminada. Las operaciones toman automáticamente esa relación y registran los dos extremos al validar. Los saldos externos sirven para trazabilidad, no representan existencias propias ni el inventario completo del proveedor. El historial muestra las ubicaciones externas y el contacto correspondiente. Los movimientos históricos anteriores conservan sus registros originales.

Informes incluye Historial de movimientos y Existencias por ubicación, con filtros por producto y ubicación, búsqueda y paginación. El historial muestra la fecha real de validación, referencia, producto, origen, destino y cantidad movida, con un enlace a la operación. Los movimientos internos aparecen una sola vez por línea. Existencias muestra balances actuales distintos de cero. Los indicadores de la ficha del producto abren directamente sus consultas filtradas: Cantidad a la mano muestra existencias por ubicación; Entrada/Salida comparten un botón que muestra ambos contadores de movimientos de los últimos 12 meses y abre el historial completo del producto; Vendido abre un análisis de órdenes confirmadas de los últimos 365 días, agrupado por mes. Vendido y Comprado ofrecen lista, tabla dinámica y gráfico, agrupación por fecha o contacto, totales y documentos de origen. Desde existencias se accede al historial. La pestaña Inventario queda vacía.

El menú Operaciones contiene Entregas, Recepciones e Interno, con listas y formularios separados, búsqueda y paginación. Las referencias se generan automáticamente como OUT/00001, IN/00002 e INT/00003. Se guardan fecha programada, contacto y notas internas.

Las entregas muestran Dirección de entrega, un selector que relaciona al cliente y muestra únicamente su nombre, y un único selector obligatorio Ubicación de origen. Las recepciones relacionan un proveedor y una Ubicación de destino. Los traslados internos tienen Ubicación de origen y Ubicación de destino. Cada selector guarda la ubicación y muestra su ruta / almacén; el servidor obtiene el almacén de esa ubicación. No hay bloques Origen/Destino ni selectores separados de almacén. Los borradores anteriores que solo tienen almacén requieren elegir una ubicación al editarlos. El servidor valida las relaciones e impide traslados al mismo origen y destino. Las ubicaciones usadas por operaciones no pueden cambiar de almacén.

Operaciones permite agregar y quitar líneas con Producto, Demanda (prevista) y Cantidad (a mover), con hasta tres decimales. También muestra existencias actuales en la ubicación de origen, o destino en recepciones. Guardar no modifica existencias. Validar mueve exclusivamente Cantidad: recepciones suman en destino, entregas restan en origen e internos trasladan entre ambas ubicaciones. La operación queda Realizado y no admite edición ni una segunda validación. No se permiten saldos negativos. Los movimientos, balances y cambio de estado se guardan en una única transacción y comprueban la versión del registro. El indicador Cantidad a la mano de la ficha del producto abre directamente su desglose por ubicación.

Las cantidades comienzan en cero y se cargan mediante recepciones. Aún no hay reservas, unidades de medida, devoluciones o generación de operaciones pendientes por diferencias entre demanda y cantidad. Desde una orden de venta confirmada, el botón Entrega genera y abre un único borrador con sus productos, demandas, cliente, fecha y origen. La entrega conserva el vínculo a la orden, muestra al cliente relacionado y permite regresar a Ventas. Vendedor relacionado aparece en Otra información, junto a Operaciones. La entrega no muestra términos de pago. La creación no modifica existencias.

Indicadores del producto: Cantidad a la mano suma los balances actuales de todas las ubicaciones; Entrada y Salida cuentan líneas de recepción y entrega realizadas de los últimos 12 meses. Excluyen borradores e internos; su detalle muestra fecha, referencia, origen, destino y cantidad real, una fila por línea. Vendido suma las cantidades de órdenes de venta confirmadas de los últimos 365 días, incluyendo ventas todavía no entregadas. Una operación manual de inventario no genera una venta ni una compra. Comprado suma unidades de órdenes de compra confirmadas en los últimos 12 meses y abre su historial de líneas confirmadas. Las tablas y la consulta están disponibles; el formulario para registrar órdenes de compra se desarrollará aparte.

Instalar con `php/install-inventory.php` y verificar con `test/inventory-operations.php` mediante el ejecutable de PHP.

La vista principal abre Productos en un listado con búsqueda y paginación. «Nuevo producto» o un registro abre un formulario separado con nombre y casillas independientes Venta y Compra. Ambas casillas comienzan desmarcadas. El formulario tiene seis pestañas, en este orden: Información general, Variantes, Ventas, Compras, Inventario y Contabilidad. Los datos se guardan en MySQL.

Información general incluye precio de venta, impuesto de venta, impuesto de compra y categoría. El precio se almacena como decimal con dos posiciones, comienza en 0.00 y acepta punto o coma decimal. Las relaciones son opcionales; cada impuesto pertenece al módulo correspondiente, Ventas o Compras, y la categoría usa el catálogo de Inventario. Los catálogos de impuestos se administran en Configuración de Compras y Ventas, con solo el nombre. Las demás pestañas siguen vacías; las existencias se consultan desde el indicador Cantidad a la mano.

Configuración → Productos → Categorías abre el listado de categorías. «Nueva categoría» o «Editar» abre un formulario independiente con nombre y categoría superior opcional. Guardar vuelve al listado. Los datos se almacenan en MySQL y el catálogo empieza vacío.

Configuración → Gestión de almacenes incluye Almacenes y Ubicaciones, cada uno con vistas de listado y formulario separadas, búsqueda y paginación. Los almacenes tienen nombre. Las ubicaciones tienen nombre, tipo y ubicación superior opcional. Las internas requieren almacén; las de Cliente y Proveedor son externas y no requieren almacén. Se impiden ciclos y duplicados dentro de la misma ubicación superior; una ubicación con sububicaciones no puede cambiar de almacén. Los almacenes comienzan vacíos; las ubicaciones incluyen Clientes y Proveedores como destinos y orígenes externos predeterminados.

Las categorías pueden organizarse en subcategorías. El sistema impide relaciones circulares y nombres duplicados dentro de la misma categoría superior. El listado admite búsqueda por nombre o ruta y paginación de 20 registros.

La migración ya se ejecutó en la base local. Para instalar esta sección en otro entorno, ejecuta desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe php/install-inventory.php
```

Para verificar creación, edición y jerarquía:

```powershell
& C:\xampp\php\php.exe test/inventory-categories.php
& C:\xampp\php\php.exe test/inventory-products.php
& C:\xampp\php\php.exe test/product-taxes.php
& C:\xampp\php\php.exe test/inventory-storage.php
```

La prueba utiliza registros identificados y los elimina al terminar. Requiere Apache y MySQL en ejecución.

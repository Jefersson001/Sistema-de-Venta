# Inventario

## Operaciones

Ubicaciones admite los tipos Interna, Cliente y Proveedor. Solo las internas requieren almacén y forman parte de Cantidad a la mano. Se crean Clientes y Proveedores como ubicaciones externas predeterminadas. Cada cliente o proveedor puede elegir su ubicación externa en Contactos → Inventario; si no elige una, se usa la predeterminada. Las operaciones toman automáticamente esa relación y registran los dos extremos al validar. Los saldos externos sirven para trazabilidad, no representan existencias propias ni el inventario completo del proveedor. El historial muestra las ubicaciones externas y el contacto correspondiente. Los movimientos históricos anteriores conservan sus registros originales.

Informes incluye Historial de movimientos y Existencias por ubicación, con filtros por producto y ubicación, búsqueda y paginación. El historial muestra la fecha real de validación, referencia, producto, origen, destino y cantidad movida, con un enlace a la operación. Los movimientos internos aparecen una sola vez por línea. Existencias muestra balances actuales distintos de cero. Los indicadores de la ficha del producto abren directamente sus consultas filtradas: Cantidad a la mano muestra existencias por ubicación; Entrada/Salida comparten un botón que muestra ambos contadores de movimientos de los últimos 12 meses y abre el historial completo del producto; Vendido abre un análisis de órdenes confirmadas de los últimos 365 días, agrupado por mes. Vendido y Comprado ofrecen lista, tabla dinámica y gráfico, agrupación por fecha o contacto, totales y documentos de origen. Desde existencias se accede al historial. La pestaña Inventario queda vacía.

El menú Operaciones contiene Entregas, Recepciones e Interno, con listas y formularios separados, búsqueda y paginación. Las referencias se generan automáticamente como OUT/00001, IN/00002 e INT/00003. Se guardan fecha programada, contacto y notas internas.

Las entregas muestran Dirección de entrega, un selector que relaciona al cliente y muestra únicamente su nombre, y un único selector obligatorio Ubicación de origen. Las recepciones relacionan un proveedor y una Ubicación de destino. Los traslados internos tienen Ubicación de origen y Ubicación de destino. Cada selector guarda la ubicación y muestra su ruta / almacén; el servidor obtiene el almacén de esa ubicación. No hay bloques Origen/Destino ni selectores separados de almacén. Los borradores anteriores que solo tienen almacén requieren elegir una ubicación al editarlos. El servidor valida las relaciones e impide traslados al mismo origen y destino. Las ubicaciones usadas por operaciones no pueden cambiar de almacén.

Operaciones tiene Borrador, En espera, Listo, Hecho y Cancelado. Confirmar pasa a En espera; Comprobar disponibilidad reserva las cantidades de una ubicación interna y pasa a Listo. Recepciones y devoluciones desde clientes no reservan stock propio en su origen externo. Guardar una operación lista libera su reserva y exige comprobar de nuevo. Cancelar libera las reservas sin mover existencias. La validación bloquea balances en orden y comprueba disponibilidad descontando reservas de otras operaciones, con lecturas actuales y transacción completa.

Cada línea relacionada conserva su línea de venta o compra. Producto y demanda se consultan sin modificar; Cantidad indica lo realmente movido y no puede superar lo pendiente. Validar un parcial crea automáticamente una nueva operación por el resto. Hecho no admite edición ni validación repetida. Las ubicaciones internas no admiten saldos negativos; los balances externos representan trazabilidad y no inventario propio.

Devolver productos desde una operación hecha crea un borrador relacionado con ubicaciones inversas y cantidades aún no devueltas. Se puede registrar una devolución parcial. Validarla actualiza existencias y cantidades netas entregadas o recibidas; la nota de crédito se registra por separado desde la factura. La entrega y la factura de cliente muestran Venta. Recepciones muestran Orden de compra.

Cantidad a la mano abre las existencias por ubicación e incluye cantidades reservadas y disponibles. La ficha del producto muestra estos dos totales debajo de sus indicadores. Su pestaña Inventario continúa vacía. Tipo de producto permite Mercancía o Servicio; los servicios no generan operaciones. Facturar antes de entregar o recibir nunca elimina el movimiento físico de una mercancía.

Indicadores del producto: Cantidad a la mano suma los balances actuales de todas las ubicaciones; Entrada y Salida cuentan líneas de recepción y entrega realizadas de los últimos 12 meses. Excluyen borradores e internos; su detalle muestra fecha, referencia, origen, destino y cantidad real, una fila por línea. Vendido suma las cantidades de órdenes de venta confirmadas de los últimos 365 días, incluyendo ventas todavía no entregadas. Una operación manual de inventario no genera una venta ni una compra. Comprado suma unidades de órdenes de compra confirmadas en los últimos 12 meses y abre su historial de líneas confirmadas. Las órdenes de compra se registran y confirman desde Compras → Órdenes → Solicitudes de cotización. El botón Recepción copia sus líneas y relaciona la recepción con la orden; la recepción muestra solo Orden de compra; desde esa orden se genera la factura de proveedor, que muestra solo Orden de recepción.

Instalar con `php/install-inventory.php` y verificar con `test/inventory-operations.php` mediante el ejecutable de PHP.

La vista principal abre Productos en un listado con búsqueda y paginación. «Nuevo producto» o un registro abre un formulario separado con nombre y casillas independientes Venta y Compra. Ambas casillas comienzan desmarcadas. El formulario tiene seis pestañas, en este orden: Información general, Variantes, Ventas, Compras, Inventario y Contabilidad. Los datos se guardan en MySQL.

Información general incluye precio de venta, impuesto de venta, impuesto de compra y categoría. El precio se almacena como decimal con dos posiciones, comienza en 0.00 y acepta punto o coma decimal. Las relaciones son opcionales; cada impuesto pertenece al módulo correspondiente, Ventas o Compras, y la categoría usa el catálogo de Inventario. Los catálogos de impuestos se administran en Configuración de Compras y Ventas, con solo el nombre. Compras permite configurar precios por proveedor; las demás pestañas pendientes siguen vacías. El costo de referencia se actualiza al recibir una compra y se puede configurar en Información general.

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

Actualizar el circuito existente con php/install-workflow.php. Instalar el circuito completo con php/install-commerce.php después de los módulos base. test/commerce-partials.php comprueba pendientes, reservas simultáneas, devoluciones y facturación parcial sin dejar sus datos de prueba.

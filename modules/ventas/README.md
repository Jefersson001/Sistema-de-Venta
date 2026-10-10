# Ventas

## Órdenes → Cotizaciones

La vista principal es el listado de cotizaciones con búsqueda, filtro de estado y paginación. Por defecto muestra todos los estados, incluidas las órdenes de venta. Cambiar el filtro de estado actualiza automáticamente la lista y mantiene la búsqueda. «Nueva cotización» o un registro abre el formulario separado. El correlativo se asigna automáticamente con el formato `S00001` y se conserva al convertir la cotización en orden de venta.

El formulario relaciona un cliente de Contactos, su dirección concatenada (calle, ciudad, estado, código postal y país), fecha de la orden, términos de pago y vendedor. Factura y entrega usan inicialmente la misma dirección porque Contactos todavía tiene una sola dirección por registro. Las direcciones guardadas se conservan al editar el mismo cliente; cambiar de cliente trae la nueva dirección. El vendedor se propone desde el cliente y puede cambiarse por otro contacto Vendedor.

Líneas de orden permite agregar y quitar productos habilitados para Venta. Elegir un producto trae su precio de venta y su impuesto de venta; cantidad, precio unitario e impuesto son editables. Los nombres de productos e impuestos y los importes se guardan con la cotización. La otra pestaña contiene las secciones Ventas y Entrega. Ubicación / almacén se trae del vendedor relacionado al cliente, muestra solo el almacén si no tiene ubicación y se guarda con la cotización. Cambiar el vendedor actualiza la relación; las órdenes confirmadas conservan el destino guardado. Esta relación no consulta existencias todavía.

Los cálculos utilizan cantidades de hasta tres decimales y precios de hasta dos, con redondeo por línea a dos decimales. BCMath y BigInt calculan bases e impuestos con el porcentaje guardado en cada línea. Los importes totales incluyen impuestos.

Los estados son Cotización, Cotización enviada, Orden de venta y Cancelado. «Marcar como enviada» cambia el estado local y no envía correos. Marcar o confirmar requiere al menos una línea guardada. Cancelar también permite cotizaciones vacías, bloquea su edición e impide confirmar después; se rechaza la cancelación si existen entregas o facturas relacionadas. Confirmar bloquea la edición y no genera movimientos de inventario, cobros ni registros contables. Los cambios de estado y guardados son transaccionales y comprueban la versión del registro para evitar sobrescribir otra edición. Los estados nuevos se instalan con `php/install-statuses.php` o con el instalador completo `php/install-commerce.php`.

Tipo de orden muestra Orden de venta y Factura. Factura permite facturar las cantidades ordenadas antes de entregar; la mercancía conserva su movimiento físico. La política del producto permite facturar cantidades ordenadas o entregadas; los servicios se facturan sin movimientos de inventario.

La entrega conserva el cliente, ubicaciones y relación directa a cada línea de venta. Producto y demanda no se cambian en una operación relacionada; se indica la cantidad real. Una entrega parcial genera automáticamente otra por el resto. La orden puede relacionar varias entregas, devoluciones, facturas y notas de crédito. Los contadores abren el documento único o su lista filtrada. Factura y entrega muestran Venta para volver a la orden.

La orden muestra cantidades ordenadas, entregadas netas de devoluciones, facturadas, pendientes y por facturar, junto con un margen estimado por línea calculado con el costo de referencia guardado al cotizar. La facturación copia solo cantidades disponibles según la política. Una factura cancelada conserva su registro y permite crear una sustituta. Crear documentos no mueve existencias.

Actualizar un circuito existente con php/install-workflow.php. Pruebas del recorrido completo y parciales: test/commerce-workflows.php y test/commerce-partials.php.

Instalación en otro entorno, después de instalar Contactos, Inventario e impuestos:

```powershell
& C:\xampp\php\php.exe php/install-sales.php
```

Pruebas del recorrido por HTTP y de las interacciones del formulario:

```powershell
& C:\xampp\php\php.exe test/sales-quotations.php
node test/sales-ui.js
```

Las pruebas crean registros identificados y los eliminan al terminar. PHP debe tener PDO MySQL y BCMath habilitados.

## Impuestos

Configuración → Impuestos permite crear y editar impuestos de venta con Nombre y Porcentaje. El listado con búsqueda y paginación y el formulario son vistas separadas. Estos registros se conservan separados de los impuestos de Compras.

El catálogo comienza vacío; el porcentaje se configura manualmente entre 0 y 100. Los impuestos de venta pueden seleccionarse en Información general de los productos de Inventario mediante su campo Impuesto de venta.

Para instalar únicamente los catálogos de impuestos en otra base:

```powershell
& C:\xampp\php\php.exe php/install-taxes.php
```

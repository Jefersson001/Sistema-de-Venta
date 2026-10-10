# Ventas

## Órdenes → Cotizaciones

La vista principal es el listado de cotizaciones con búsqueda, filtro de estado y paginación. Por defecto muestra todos los estados, incluidas las órdenes de venta. Cambiar el filtro de estado actualiza automáticamente la lista y mantiene la búsqueda. «Nueva cotización» o un registro abre el formulario separado. El correlativo se asigna automáticamente con el formato `S00001` y se conserva al convertir la cotización en orden de venta.

El formulario relaciona un cliente de Contactos, su dirección concatenada (calle, ciudad, estado, código postal y país), fecha de la orden, términos de pago y vendedor. Factura y entrega usan inicialmente la misma dirección porque Contactos todavía tiene una sola dirección por registro. Las direcciones guardadas se conservan al editar el mismo cliente; cambiar de cliente trae la nueva dirección. El vendedor se propone desde el cliente y puede cambiarse por otro contacto Vendedor.

Líneas de orden permite agregar y quitar productos habilitados para Venta. Elegir un producto trae su precio de venta y su impuesto de venta; cantidad, precio unitario e impuesto son editables. Los nombres de productos e impuestos y los importes se guardan con la cotización. La otra pestaña contiene las secciones Ventas y Entrega. Ubicación / almacén se trae del vendedor relacionado al cliente, muestra solo el almacén si no tiene ubicación y se guarda con la cotización. Cambiar el vendedor actualiza la relación; las órdenes confirmadas conservan el destino guardado. Esta relación no consulta existencias todavía.

Los cálculos utilizan cantidades de hasta tres decimales y precios de hasta dos decimales, con redondeo por línea a dos decimales. Se calculan con BCMath en PHP y BigInt en JavaScript. Como el catálogo de impuestos tiene únicamente nombre, el total es la suma de las bases y todavía no incluye impuestos; esto aparece indicado en el formulario y el listado.

Los estados son Cotización, Cotización enviada, Orden de venta y Cancelado. «Marcar como enviada» cambia el estado local y no envía correos. Marcar o confirmar requiere al menos una línea guardada. Cancelar también permite cotizaciones vacías, bloquea su edición e impide confirmar después; se rechaza la cancelación si existen entregas o facturas relacionadas. Confirmar bloquea la edición y no genera movimientos de inventario, cobros ni registros contables. Los cambios de estado y guardados son transaccionales y comprueban la versión del registro para evitar sobrescribir otra edición. Los estados nuevos se instalan con `php/install-statuses.php` o con el instalador completo `php/install-commerce.php`.

Tipo de orden permite elegir Orden de entrega (por defecto) o Factura y se conserva al confirmar. Los registros anteriores reciben Orden de entrega al instalar la migración. El botón Entrega de una orden confirmada crea un borrador en Inventario con el cliente, fecha, almacén/ubicación guardados y productos. La cantidad de cada línea de venta pasa a Demanda; la cantidad real a mover comienza en cero. La entrega muestra su documento de origen, cliente relacionado bajo la etiqueta Dirección de entrega y tipo de orden. Vendedor relacionado aparece en la pestaña Otra información; términos de pago no aparece en la entrega. Crear la entrega no modifica existencias; estas cambian al validar desde Inventario.

Solo se crea una entrega por orden. El botón pasa a Entrega · 1 y abre el registro existente; repetir el POST tampoco crea duplicados. Si la venta tiene almacén pero no ubicación, se propone la ubicación cuando es única. Si hay varias o ninguna, la entrega se crea como borrador y requiere seleccionar una ubicación antes de validar. La relación se instala desde el instalador de Ventas o Inventario una vez instalados ambos módulos.

Para el tipo Orden de entrega, validar la entrega habilita Factura en la orden de venta. El botón crea un único borrador de cliente en Contabilidad con los datos y líneas guardados de la orden. Cuando existe, Factura · 1 abre el mismo registro desde la orden de venta. La factura y la entrega muestran solo el botón Venta, que abre la orden de venta relacionada. Para el tipo Factura, la orden confirmada permite generar la factura directamente y no crea entrega. Las creaciones verifican el estado y bloquean la orden para evitar duplicados. Crear o confirmar una factura no modifica inventario. Instalación del circuito completo: `php/install-commerce.php` después de instalar todos los módulos base.

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

Configuración → Impuestos permite crear y editar impuestos de venta con un único campo visible: Nombre. El listado con búsqueda y paginación y el formulario son vistas separadas. Estos registros se conservan separados de los impuestos de Compras.

El catálogo comienza vacío y esta etapa no incluye tasas ni cálculos. Los impuestos de venta pueden seleccionarse en Información general de los productos de Inventario mediante su campo Impuesto de venta.

Para instalar únicamente los catálogos de impuestos en otra base:

```powershell
& C:\xampp\php\php.exe php/install-taxes.php
```

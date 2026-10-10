# Ventas

## Órdenes → Cotizaciones

La vista principal es el listado de cotizaciones con búsqueda, filtro de estado y paginación. Por defecto muestra todos los estados, incluidas las órdenes de venta. Cambiar el filtro de estado actualiza automáticamente la lista y mantiene la búsqueda. «Nueva cotización» o un registro abre el formulario separado. El correlativo se asigna automáticamente con el formato `S00001` y se conserva al convertir la cotización en orden de venta.

El formulario relaciona un cliente de Contactos, su dirección concatenada (calle, ciudad, estado, código postal y país), fecha de la orden, términos de pago y vendedor. Factura y entrega usan inicialmente la misma dirección porque Contactos todavía tiene una sola dirección por registro. Las direcciones guardadas se conservan al editar el mismo cliente; cambiar de cliente trae la nueva dirección. El vendedor se propone desde el cliente y puede cambiarse por otro contacto Vendedor.

Líneas de orden permite agregar y quitar productos habilitados para Venta. Elegir un producto trae su precio de venta y su impuesto de venta; cantidad, precio unitario e impuesto son editables. Los nombres de productos e impuestos y los importes se guardan con la cotización. La otra pestaña contiene las secciones Ventas y Entrega. Ubicación / almacén se trae del vendedor relacionado al cliente, muestra solo el almacén si no tiene ubicación y se guarda con la cotización. Cambiar el vendedor actualiza la relación; las órdenes confirmadas conservan el destino guardado. Esta relación no consulta existencias todavía.

Los cálculos utilizan cantidades de hasta tres decimales y precios de hasta dos decimales, con redondeo por línea a dos decimales. Se calculan con BCMath en PHP y BigInt en JavaScript. Como el catálogo de impuestos tiene únicamente nombre, el total es la suma de las bases y todavía no incluye impuestos; esto aparece indicado en el formulario y el listado.

Los estados son Cotización, Cotización enviada y Orden de venta. «Marcar como enviada» cambia el estado local y no envía correos. Marcar o confirmar requiere al menos una línea guardada. Confirmar bloquea la edición y no genera movimientos de inventario, cobros ni registros contables. Los cambios de estado y guardados son transaccionales y comprueban la versión del registro para evitar sobrescribir otra edición.

Tipo de orden permite elegir Orden de entrega (por defecto) o Factura y se conserva al confirmar. Los registros anteriores reciben Orden de entrega al instalar la migración. Las órdenes de venta muestran arriba el botón Entrega, deshabilitado hasta implementar las entregas; todavía no genera documentos ni movimientos.

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

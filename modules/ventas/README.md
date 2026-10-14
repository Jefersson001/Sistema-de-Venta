# Ventas

## Órdenes → Cotizaciones

La vista principal es el listado de cotizaciones con búsqueda, filtro de estado y paginación. Por defecto muestra todos los estados, incluidas las órdenes de venta. Cambiar el filtro de estado actualiza automáticamente la lista y mantiene la búsqueda. «Nueva cotización» o un registro abre el formulario separado. El correlativo se asigna automáticamente con el formato `S00001` y se conserva al convertir la cotización en orden de venta.

El formulario relaciona un cliente de Contactos, su dirección concatenada (calle, ciudad, estado, código postal y país), fecha de la orden, términos de pago y vendedor. Factura y entrega usan inicialmente la misma dirección porque Contactos todavía tiene una sola dirección por registro. Las direcciones guardadas se conservan al editar el mismo cliente; cambiar de cliente trae la nueva dirección. El vendedor se propone desde el cliente y puede cambiarse por otro contacto Vendedor.

Líneas de orden permite agregar y quitar productos habilitados para Venta. Elegir un producto trae su precio de venta y su impuesto de venta; cantidad, precio unitario e impuesto son editables. Los nombres de productos e impuestos y los importes se guardan con la cotización. La otra pestaña contiene las secciones Ventas y Entrega. Ubicación / almacén se trae del vendedor relacionado al cliente, muestra solo el almacén si no tiene ubicación y se guarda con la cotización. Cambiar el vendedor actualiza la relación; las órdenes confirmadas conservan el destino guardado. Esta relación no consulta existencias todavía.

Los cálculos utilizan cantidades de hasta tres decimales y precios de hasta dos, con redondeo por línea a dos decimales. BCMath y BigInt calculan bases e impuestos con el porcentaje guardado en cada línea. Los importes totales incluyen impuestos.

El resumen de la cotización muestra Base imponible, una fila por impuesto (nombre, porcentaje e importe agrupado) y Total. El desglose se calcula con los importes de cada línea, conserva los nombres y porcentajes guardados y también se presenta al abrir una venta sin JavaScript. Impuestos con distintos porcentajes se mantienen separados. Una base de 1.20 con impuesto del 10 % muestra 0.12 de impuesto y 1.32 de total.

Al seleccionar un producto, `producto.php?id=…` consulta su precio, comisión e impuesto actuales con GET y Cache-Control no-store; toma también cambios hechos en otra pestaña después de abrir la cotización. Mientras se consulta se bloquea el guardado. Un error conserva un aviso y exige volver a seleccionar el producto; una respuesta anterior no reemplaza una selección posterior. La consulta no modifica productos ni documentos. Las líneas ya guardadas conservan sus importes hasta que el usuario seleccione nuevamente su producto. Si hay comisión configurada y falta vendedor, la vista indica cómo asignarlo desde Otra información.

Los estados son Cotización, Cotización enviada, Orden de venta y Cancelado. «Marcar como enviada» cambia el estado local y no envía correos. Marcar o confirmar requiere al menos una línea guardada. Cancelar está disponible tanto en cotizaciones como en órdenes confirmadas: bloquea su edición e impide confirmar después. Cancela las entregas pendientes y libera sus reservas en la misma transacción; conserva los documentos cancelados y sus enlaces. Una factura activa exige resolverse o cancelarse primero, y una orden con movimientos hechos se conserva y se corrige mediante devoluciones. Cancelar una entrega por separado no cancela la venta; permite generar otra entrega para el pendiente. Confirmar bloquea la edición y no genera movimientos de inventario, cobros ni registros contables. Los cambios de estado y guardados son transaccionales y comprueban la versión del registro para evitar sobrescribir otra edición. Los estados nuevos se instalan con `php/install-statuses.php` o con el instalador completo `php/install-commerce.php`.

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

## Comisiones por producto

El menú Comisiones contiene Comisiones y Pagos de comisiones. El primero muestra el seguimiento por factura y vendedor; el segundo tiene su listado y ficha de pagos realizados, incluyendo los revertidos, con filtros por vendedor y fecha del pago. No crea otra caja ni otro registro de dinero: consulta las aplicaciones existentes de los egresos de Tesorería. Cada ficha enlaza comisión, factura, venta, movimiento de dinero y reversión.

Pagar comisión en cada fila del Dashboard abre Pagos de comisiones → Nuevo con vendedor, moneda y período de facturas conservados. Se elige la factura a pagar y se precarga su pendiente; el importe puede reducirse para un pago parcial. La confirmación utiliza las mismas validaciones y bloqueos del asistente existente y abre la ficha del pago registrado. Los ajustes por recuperar del vendedor, incluso en facturas fuera del período, impiden confirmar nuevos pagos. Los períodos en Comisiones y el formulario Nuevo filtran fecha de factura; el listado de pagos filtra fecha del egreso.

Disponible por cobros es la comisión habilitada por los cobros confirmados antes de descontar pagos al vendedor. Pendiente de pago es lo que queda tras descontarlos. Ajuste por recuperar es lo pagado que supera la comisión habilitada luego de devoluciones o cambios en los cobros; es un aviso de saldo por resolver y no registra automáticamente una devolución de dinero.

Ventas → Comisiones conserva listado y ficha separados. Producto → Ventas permite elegir Importe fijo por unidad o Porcentaje. El importe fijo se expresa en la moneda del producto; el porcentaje se calcula sobre su Precio de venta en tienda. Por ejemplo, precio 5.00 y comisión fija 2.00, o porcentaje 40 %, cargan un precio unitario de 7.00 al seleccionar el producto con vendedor asignado. Una venta sin vendedor carga el precio en tienda y no genera comisión. Los impuestos y el costo de compra no forman parte de este cálculo.

La comisión configurada se conserva por unidad aunque se edite manualmente el precio cobrado. Cambiar entre venta con vendedor y venta de tienda recalcula el precio automático; cambiar entre dos vendedores mantiene el precio editado. El modo Sin comisión automática conserva el cálculo manual por diferencia positiva para productos y documentos anteriores. El usuario puede volver a seleccionar el producto para traer su configuración vigente; un cambio del catálogo no modifica silenciosamente líneas ya guardadas.

Cada línea nueva guarda `commission_reference_price` y `commission_unit_amount` en la moneda de la orden. Ambos son calculados por el servidor y se conservan al editar una cotización o cambiar luego el producto. NULL en commission_unit_amount conserva la regla anterior por diferencia de precio; cero explícito conserva una comisión configurada de cero. La moneda puede cambiar mientras la orden es editable, convirtiendo precio, referencia y comisión con la tasa de la orden. Ambos importes viajan a las líneas de factura y notas de crédito. Los documentos antiguos no reciben una regla usando la configuración actual. La facturación parcial distribuye el redondeo acumulado para no duplicar céntimos entre facturas de una misma línea.

La ficha de comisión corresponde a una factura de cliente y enlaza su venta, factura y pagos de Tesorería. Muestra comisión generada, pendiente de cobro al cliente, disponible por cobros, pagado al vendedor, pendiente de pago y ajuste por recuperar. Una cotización o factura en borrador solo estima; una factura registrada habilita la comisión proporcional a sus aplicaciones de pagos confirmados, limitada al importe neto después de notas de crédito. Un anticipo sin aplicar o un pago en proceso no habilita comisiones.

Pagar comisión abre un asistente con importe en la moneda de la factura, fecha, Cuenta de egreso, Medio de pago y moneda del egreso. Se permite pagar parte del saldo y convertir entre las monedas configuradas, conservando la tasa y el importe original aplicado. El servidor valida cobros, saldo disponible de comisión y de la cuenta, fecha, moneda y método. La confirmación crea un único egreso confirmado en Tesorería, relacionado con factura y vendedor. Las claves de creación evitan duplicar reenvíos; el bloqueo de orden y factura impide que dos pagos simultáneos excedan lo disponible. El concepto configurable Pago de comisiones debe usarse a través de este asistente para mantener la aplicación.

La reversión del egreso conserva el original, registra su movimiento contrario y libera la comisión aplicada. Las devoluciones y la retirada o reversión de cobros recalculan la comisión disponible; si ya se pagó de más aparece Ajuste por recuperar y se bloquean nuevos pagos a ese vendedor hasta resolverlo. La devolución física por sí sola no cambia importes facturados: se registra su nota de crédito. El historial permite abrir los egresos y sus reversiones.

Los filtros Hoy, Últimos 7 días, Este mes y Personalizado seleccionan las facturas por su fecha. Los saldos de comisiones de esas facturas reflejan su situación actual, no un saldo histórico a la fecha final. Las monedas se muestran separadas, y el contador de ventas no duplica una orden con varias facturas.

Instalación para una base existente: `php/install-commissions.php`. El instalador `php/install-commerce.php` también lo incluye, después de instalar Tesorería. Pruebas: `test/sales-commissions.php`, `test/commission-payment-worker.php` (pagos simultáneos), `test/product-commissions-ui.js`, `test/sales-ui.js` y `test/dashboard-ui.js`. Los registros de prueba se eliminan al terminar.

# Dashboard

El inicio es el panel Mis módulos. Dashboard reúne el resumen del negocio que antes se mostraba allí: acciones rápidas, ventas y compras pendientes, cobros y pagos pendientes, vencimientos, pagos sin aplicar, alertas de inventario y tareas pendientes.

Los indicadores conservan sus consultas de datos reales en php/dashboard-repository.php y los importes separados por moneda. Los accesos rápidos y las tareas enlazan a los registros de los módulos correspondientes.

Ingresos y egresos usa el registro de movimientos confirmados por su fecha, incluidos pagos, otros ingresos/egresos y reversiones, con Hoy por defecto, Últimos 7 días (hoy y los seis días anteriores), Este mes (desde el día 1 hasta hoy) y un rango Personalizado inclusivo. Muestra ingresos, egresos y su diferencia por moneda; esta diferencia es el movimiento neto del período, no un saldo bancario ni una utilidad. Se cuentan importes completos, incluidos anticipos sin aplicar, una sola vez por pago. La dirección del pago distingue ingresos y egresos también en reembolsos. Los borradores y pagos en proceso quedan excluidos. Los pagos revertidos conservan el original y su movimiento contrario. Saldos iniciales y transferencias internas no inflan los ingresos y egresos externos.

La sección se actualiza automáticamente cada 30 segundos mientras la página está visible, sin botones Aplicar ni Actualizar. Cambiar el período o completar un rango personalizado válido también actualiza el resumen automáticamente. Las fechas aparecen solo en Personalizado; se espera 400 ms tras editar para evitar consultas por cada pulsación. No se consulta un rango incompleto o invertido ni se descartan fechas que se estén escribiendo. Utiliza la fecha y hora de America/Caracas. Los errores de consulta conservan los últimos valores y muestran el error, sin sustituirlos por ceros. Los demás indicadores se calculan al abrir el módulo. Ver historial abre Contabilidad → Informes → Ingresos y egresos con el mismo período. El endpoint de lectura modules/dashboard/ingresos-egresos.php admite GET, valida fechas y no almacena respuestas en caché.

También muestra Saldo al inicio y Saldo disponible al cierre, por moneda, y tarjetas de todas las cuentas con sus saldos e historial. La misma actualización automática refresca estas tarjetas y conserva el filtro. El saldo de cierre acumula todos los movimientos registrados hasta la fecha final; la diferencia solo describe entradas y salidas externas del período. Los saldos iniciales se configuran desde Contabilidad → Tesorería → Saldos iniciales.

Pruebas: test/treasury.php, test/cash-flow.php y test/accounting-payments.php, con pagos y contactos identificados que se eliminan al terminar.

Ruta: modules/dashboard/index.php. Dashboard muestra directamente su contenido, sin botones de Vista general ni Configuración.

Acciones rápidas, en orden: Contactos, Productos, Ventas, Facturas, Pagos, Recepciones e Interno. Contactos y Productos abren sus listados para crear registros desde Nuevo. Ventas abre el listado completo de cotizaciones y órdenes. Facturas y Pagos abren los listados de clientes en todos los estados. Recepciones e Interno abren sus respectivos listados de operaciones. No se incluye Registrar compra.

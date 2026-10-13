# Dashboard

El inicio es el panel Mis módulos. Dashboard reúne el resumen del negocio que antes se mostraba allí: acciones rápidas, ventas y compras pendientes, cobros y pagos pendientes, vencimientos, pagos sin aplicar, alertas de inventario y tareas pendientes.

Los indicadores conservan sus consultas de datos reales en php/dashboard-repository.php y los importes separados por moneda. Solo se calculan al abrir este módulo. Los accesos rápidos y las tareas enlazan a los registros de los módulos correspondientes.

Ruta: modules/dashboard/index.php. Dashboard muestra directamente su contenido, sin botones de Vista general ni Configuración.

Acciones rápidas, en orden: Contactos, Productos, Ventas, Facturas, Pagos, Recepciones e Interno. Contactos y Productos abren sus listados para crear registros desde Nuevo. Ventas abre el listado completo de cotizaciones y órdenes. Facturas y Pagos abren los listados de clientes en todos los estados. Recepciones e Interno abren sus respectivos listados de operaciones. No se incluye Registrar compra.

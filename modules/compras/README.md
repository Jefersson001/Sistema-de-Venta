# Compras

Órdenes → Solicitudes de cotización mantiene lista y formulario independientes, filtros y correlativo P00001. Tipo de orden muestra Orden de compra y Factura. La primera opción usa la política del producto; Factura permite facturar lo ordenado antes de recibir, conservando la recepción física de mercancías. Los servicios no generan movimientos. Toda compra de mercancía nueva exige ubicación interna de destino.

Cada línea conserva producto, cantidad, precio, impuesto y porcentaje, tipo de producto y política de facturación. El precio se propone desde Producto → Compras → Precios por proveedor, o desde el costo de referencia del producto. Se puede ajustar antes de confirmar. Los importes se calculan con BCMath, redondeo por línea y dos decimales.

La barra y el filtro incluyen Solicitud de cotización, Solicitud enviada, Orden de compra y Cancelado. Cancelar está disponible en solicitudes y órdenes confirmadas; cancela las recepciones pendientes y libera las reservas en una sola transacción, sin mover existencias. Los documentos cancelados y sus enlaces se conservan, pero no admiten edición, confirmación ni nuevos documentos. Las facturas activas deben resolverse o cancelarse primero; las órdenes con movimientos hechos se conservan y se corrigen mediante devoluciones. Cancelar una recepción por separado mantiene la compra confirmada y permite generar una nueva recepción del pendiente. Actualizar estados: `php/install-statuses.php` o el instalador completo.

Recepción copia líneas y demandas con una relación directa a cada línea de compra. Producto y demanda quedan protegidos en operaciones relacionadas; Cantidad es la cantidad real. Validar una recepción parcial crea automáticamente una operación pendiente por el resto. La orden muestra cantidades ordenadas, recibidas netas de devoluciones, facturadas, pendientes y por facturar. Puede tener varias recepciones y facturas; los botones muestran el contador real y abren el listado filtrado cuando hay más de una.

Facturar toma solo las cantidades disponibles según la política: recibidas o, si corresponde, ordenadas. Un borrador existente se reutiliza; las facturas canceladas liberan sus cantidades para crear un nuevo documento sin borrar el anterior. Devolver productos crea un movimiento inverso relacionado. Las notas de crédito y los reembolsos se administran desde la factura de proveedor.

La recepción y la factura de proveedor muestran Compra y abren su orden de compra de origen. La compra muestra Recepción y Factura con sus contadores. El enlace Compra funciona también en facturas sin recepción. La factura reúne sus botones relacionados y de estado, incluido Cancelar factura, en una misma barra. Crear documentos no modifica existencias; estas cambian solo al validar movimientos.

Los impuestos de Compras tienen Nombre y Porcentaje (0–100). Los términos de pago pertenecen a Contabilidad y calculan el vencimiento mediante sus días. Comprado sigue consultando cantidades de órdenes confirmadas; una recepción manual no genera una compra.

Instalación completa: php/install-commerce.php después de los módulos base. Actualización de un circuito existente: php/install-workflow.php. Pruebas: test/commerce-workflows.php y test/commerce-partials.php, con datos identificados y limpieza al terminar.

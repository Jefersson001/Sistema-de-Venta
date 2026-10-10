# Contabilidad

Clientes → Facturas y Proveedores → Facturas conservan vistas de lista y formulario independientes. Se incluyen las notas de crédito identificadas como NC00001, con enlace a su factura original. La factura de cliente muestra Venta; la factura de proveedor muestra sus recepciones o la compra si todavía no hay recepción.

Las facturas copian únicamente lo pendiente de facturar por cada línea de la orden, según la política de cantidades ordenadas o entregadas/recibidas. Productos, precios, costos de referencia y porcentajes de impuestos son snapshots: editar un catálogo después no cambia un documento ya guardado. Un borrador activo se reutiliza para evitar duplicados; después de registrarlo se puede crear otra factura por cantidades posteriores. Cancelar libera las cantidades y permite sustituir el documento conservando el anterior.

La barra única contiene Borrador, Registrado, Enviado, Sin pagar, En proceso de pago, Parcialmente pagado, Pagado, Revertido y Cancelado. Enviado es una marca local y no envía correos. Los estados de pago se calculan a partir de pagos, notas de crédito registradas y reembolsos. Se rechazan importes superiores al saldo y acciones sobre versiones anteriores.

Crear nota de crédito requiere una factura registrada y un motivo. Se propone lo que todavía no se ha revertido, conservando precios e impuestos; en borrador se ajustan las cantidades para una reversión parcial o completa. Registrarla reduce cantidades facturadas y deuda. La factura original solo muestra Revertido cuando las notas registradas cubren todo su importe. Una nota sobre deuda sin cobro se aplica a esa deuda; solo el dinero efectivamente cobrado o pagado que corresponde devolver habilita un reembolso manual. La devolución física se valida por separado desde la entrega o recepción y mantiene su relación con la orden.

Términos de pago tiene Nombre y Días hasta el vencimiento (0 indica la misma fecha). Al generar una factura se calcula el vencimiento desde su fecha; las fechas permanecen editables durante el borrador. Los impuestos de Ventas y Compras incluyen porcentajes configurables sin cargar tasas legales predeterminadas.

Esta etapa registra documentos y pagos manuales. Todavía no genera asientos de partida doble, conciliación bancaria, documentos fiscales impresos ni PDF. Las operaciones financieras no alteran existencias. El costo del producto es una referencia de la última recepción de compra; el margen de venta es estimado según el costo guardado al cotizar, no una valoración contable del inventario.

Instalación completa: php/install-commerce.php. Actualización: php/install-workflow.php, después del circuito y sus estados. Pruebas por HTTP y repositorios: test/commerce-workflows.php, test/document-statuses.php y test/commerce-partials.php. Requieren Apache, MySQL, PDO MySQL y BCMath.

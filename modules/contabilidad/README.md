# Contabilidad

Clientes → Facturas y Proveedores → Facturas tienen listas y formularios independientes, búsqueda, filtros y paginación. Las facturas se generan desde las órdenes de venta/compra; las órdenes de tipo Factura permiten facturar tras confirmar y las de inventario requieren validar la entrega/recepción. Visitar una página por GET no crea documentos.

La factura copia contacto, dirección guardada, términos de pago, vendedor (en clientes), productos, cantidades, precios, nombres de impuestos, bases e importes. Se copia la cantidad de la orden, incluso si el movimiento de inventario fue parcial, conforme al flujo solicitado. Tiene correlativo FC00001/FP00002, fecha y vencimiento opcional. El vencimiento se indica manualmente porque los términos todavía solo tienen nombre. Los campos copiados se consultan sin editar; en borrador se pueden guardar fechas y registrar el documento. Existe una única factura por orden, con protección contra doble creación y cambios de versión. La factura de cliente muestra el botón Venta, que abre su orden de venta; la factura de proveedor muestra Orden de recepción o, si es directa y no existe recepción, Orden de compra.

Todos los estados aparecen en una única barra en la ficha de la factura, resaltando los que aplican al registro. La barra permite desplazamiento horizontal en pantallas estrechas. El estado del documento es Borrador, Registrado o Cancelado. Enviado es una marca separada: «Marcar como enviada» registra la fecha localmente, sin enviar correos. El estado de pago se calcula por separado: Sin pagar, En proceso de pago, Parcialmente pagado, Pagado o Revertido. Los filtros permiten consultar cada uno de estos estados.

En Pagos y reversión, registrar un pago lo deja pendiente; confirmarlo lo suma al importe pagado. Los pagos admiten importe, fecha y referencia, y se pueden cancelar conservando su historial. Se rechazan sobrepagos, fechas inválidas, pagos de otras facturas y acciones duplicadas. Una factura con pagos activos no puede cancelarse ni revertirse. Revertir exige un motivo y crea un registro vinculado con correlativo REV00001, fecha e importe completo; la factura original conserva su estado Registrado y muestra Revertido. Una factura cancelada o revertida no admite nuevos pagos.

Esta etapa registra documentos, pagos manuales y registros de reversión. No genera asientos de partida doble, conciliación bancaria, impuestos porcentuales, PDF ni documentos completos de nota de crédito. Los impuestos actuales son etiquetas y los totales excluyen impuestos. Crear, registrar, pagar, cancelar o revertir una factura no modifica existencias.

Instalación del recorrido completo después de los demás módulos: `php/install-commerce.php`. Para actualizar un entorno que ya tiene el circuito instalado, ejecutar `php/install-statuses.php`; conserva documentos y estados anteriores. Pruebas por HTTP: `test/commerce-workflows.php` y `test/document-statuses.php`, que crean datos identificados y los limpian al terminar.

Configuración → Términos de pago permite crear y editar términos con un único campo: Nombre. Tiene un listado con búsqueda y paginación y una vista de formulario independiente. Los registros se pueden seleccionar desde las cotizaciones de Ventas y las órdenes de Compras.

El catálogo comienza vacío. Esta etapa no incluye plazos, cuotas ni cálculos de vencimientos.

Para instalar únicamente el catálogo en otro entorno:

```powershell
& C:\xampp\php\php.exe php/install-accounting.php
```

La instalación de Ventas también crea la tabla de términos de pago.

# Lotes, números de serie y vencimientos

## Uso en Vent-As

En **Inventario → Productos → Lotes y números de serie** se consultan y crean identificadores. Se puede buscar por nombre del identificador o producto y filtrar por producto, seguimiento, ubicación y vencimiento. La ficha muestra cantidades a la mano, reservadas y disponibles por ubicación, más los últimos 200 movimientos que el usuario tiene permiso de consultar.

En la ficha del producto, pestaña **Inventario**, el seguimiento admite **Sin seguimiento**, **Por lotes** o **Por número de serie**. Son modalidades alternativas: esta entrega no agrega un lote de fabricación adicional a una serie. Cada variante conserva su propia configuración. Los productos existentes permanecen sin seguimiento hasta que el usuario lo active. Los servicios no admiten seguimiento.

Una serie identifica una unidad entera; un lote puede agrupar cantidades con dos decimales. Los identificadores son únicos por producto y empresa y conservan mayúsculas y ceros iniciales. No se puede cambiar de modalidad después de crear identificadores. Crear una ficha de lote o serie no aumenta existencias.

## Recepciones, entregas y traslados

En las líneas de una operación, el botón **Lotes / series** abre el detalle. Se pueden agregar líneas, generar un identificador o pegar varias series, una por línea. En recepciones normales se crean identificadores o se reutilizan lotes existentes. En salidas, traslados y devoluciones deben existir previamente.

Se registra la cantidad real a mover, se distribuye entre los identificadores y se guarda. Después se confirma, se comprueba disponibilidad y se valida, con los estados habituales del sistema. La suma del detalle debe coincidir con la cantidad a mover. Cada serie representa exactamente una unidad y no puede estar duplicada en existencias internas.

En salidas o traslados con origen interno, dejar el detalle vacío permite asignación automática al **Comprobar disponibilidad**. La prioridad es la fecha de retirada y, en su ausencia, el vencimiento; los identificadores sin fecha quedan al final. Si un lote no alcanza, se continúa con el siguiente. Las asignaciones de una operación lista quedan reservadas. Editar la operación devuelve el documento a espera y libera la reserva; cancelar también la libera.

Solo validar modifica los saldos generales y los saldos por identificador, en una misma transacción. Las operaciones parciales conservan el flujo existente de pendientes. Las devoluciones requieren seleccionar identificadores del movimiento original y no pueden superar lo entregado/recibido menos lo ya devuelto por identificador. Los traslados conservan la identidad entre ubicaciones. Los documentos imprimibles de inventario incluyen identificadores, cantidades y vencimiento.

La selección definitiva corresponde a la entrega o recepción. Esta entrega no reserva series desde la orden comercial ni agrega series a las líneas de factura. La consulta se realiza desde el movimiento relacionado y desde la ficha del identificador. El checkout de restaurante utiliza las mismas reglas de disponibilidad y asignación automática del inventario.

## Vencimientos

El control es opcional para lotes y series. El vencimiento real se captura al crear el identificador. Si se configura vida útil mayor que cero y se omite la fecha, se calcula desde el día de creación del identificador en la recepción o ficha. Vida útil cero requiere indicar la fecha manualmente cuando el producto controla vencimiento.

Los días de alerta, retirada y consumo preferente se restan del vencimiento. Se consultan en la ficha y se recalculan al guardar sus fechas usando la configuración del producto. Los cambios de plazos del producto no modifican automáticamente las fichas existentes. Una modificación concurrente de la ficha se rechaza mediante versión.

Las entregas comerciales bloquean identificadores con vencimiento o retirada anterior al día actual de Caracas. El día de vencimiento todavía se considera vigente. Las devoluciones y movimientos internos siguen permitidos para poder devolver o retirar físicamente mercancía. El consumo preferente y la fecha de alerta informan; no bloquean por sí solos. No se envían correos ni notificaciones automáticas en esta entrega: las alertas se consultan en el listado y sus filtros.

## Existencias anteriores

Después de activar seguimiento en un producto con existencias, usar **Identificar existencias**, accesible desde el listado de lotes o la ficha del producto. Elegir producto y ubicación y distribuir exactamente la cantidad pendiente entre los identificadores reales.

La carga inicial no incrementa ni reduce cantidades generales. Registra movimientos de identificación con usuario, fecha y empresa, sin reescribir documentos históricos. No permite cargar dos veces las mismas cantidades ni repetir una serie que ya está en existencias. Hasta identificar el saldo, no se puede utilizar esa cantidad sin asignación en salidas. Las devoluciones de documentos históricos que no identificaban lotes/series no adquieren trazabilidad retroactiva.

## Permisos y empresas

En **Ajustes → Grupos de acceso → Inventario** hay permisos de consultar, crear y editar lotes y series. Crear permite también la carga inicial; editar permite corregir fechas. La asignación de líneas utiliza los permisos normales de las operaciones. La trazabilidad filtra documentos según sus permisos, incluidas las entregas asignadas al vendedor. Todos los datos nuevos usan aislamiento y auditoría por empresa.

La instalación registra los permisos nuevos para el grupo Administrador la primera vez que aparecen. No modifica los permisos existentes de otros grupos ni restaura permisos retirados posteriormente. Los demás grupos se configuran desde Ajustes.

## Instalación y comprobación

`php php/install-inventory-tracking.php` genera primero un respaldo privado y aplica la migración repetible. Agrega configuración a productos y tablas de identificadores, asignaciones, saldos y movimientos, sin cambiar cantidades existentes.

`php test/inventory-tracking.php` usa una base temporal para comprobar lotes, series, vencimientos, FEFO, reservas, concurrencia con dos procesos, cancelaciones, devoluciones parciales, identificación inicial, impresión, permisos, aislamiento por empresa y guardado de la configuración del producto. Incluye navegador con guardado/recarga y revisión de anchura de escritorio y móvil.

Los campos de configuración del producto también se pueden importar/exportar mediante el CSV de productos. En CSV los valores de seguimiento son `none`, `lot` y `serial`. La carga de identificadores se realiza mediante la ventana de operaciones o la identificación inicial; no se incorpora un importador CSV de movimientos por lotes en esta entrega.

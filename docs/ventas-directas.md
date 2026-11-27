# Ventas Directas

Módulo integrado de Vent-As para celular y computadora. Usa los documentos nativos: órdenes, entregas, facturas, cobros, notas de crédito y transferencias.

## Instalación y permisos

En **Ventas → Estado de factura** puedes filtrar las últimas 100 ventas autorizadas por estado de factura (borrador, registrado, cancelado o enviado), estado de pago (sin pagar, en proceso, parcialmente pagado, pagado o revertido) o **Vencidas** (facturas registradas con saldo pendiente y fecha de vencimiento anterior a hoy). La lista se actualiza automáticamente al seleccionar un estado. Selecciona **Limpiar** o **Todos los estados** para volver a ver todos. El filtro se aplica antes del límite de 100 registros y conserva las restricciones de empresa y vendedor.

Las migraciones 20261113131544, 20261113161546 y 20261113171011 agregan tablas empresariales mediante registerCompanyTable(), catálogos y permisos. Aplicar con php php/migrate.php o el arranque de Dokploy. No importar respaldos ni reinstalar una base existente.

- **Vendedor de venta directa:** opera con su contacto vendedor propio, vende, solicita mercancía y retornos de sobrantes, registra abonos pendientes, solicita devoluciones del cliente y envía su cierre.
- **Supervisor de venta directa:** consulta inventario de abastecimiento y ventas, aprueba solicitudes, confirma su entrega física, revisa devoluciones y valida cierres. No recibe permisos generales para editar inventario o confirmar dinero.
- **Administrador:** supervisa y configura crédito sin actuar como vendedor. Para vender además debe asignarse expresamente un grupo vendedor y vincular su propio contacto. Los permisos de confirmación de cobros siguen siendo contables.

Las migraciones no asignan grupos automáticamente. Revisar también grupos personalizados, pues los permisos se combinan. Crear requiere contacto vendedor propio aunque el alcance sea Todos. Aprobar, entregar, recibir devoluciones y validar cierres requiere alcance Todos. El vendedor necesita almacén y ubicación interna; si hay una única ubicación interna en su almacén puede resolverse automáticamente. Con alcance Propios, los clientes deben estar asignados al vendedor.

## Venta, plazo y cobro

El vendedor elige cliente y **plazo de pago**. Contado vence hoy; los plazos de días vencen después. La clasificación interna contado/crédito se deriva del plazo y no significa que se recibió dinero.

Cliente y plazo se eligen antes del catálogo, en celular y computadora. El catálogo muestra 12 productos por página y la búsqueda revisa todos los productos disponibles. Cambiar de página no cambia el carrito. El acceso móvil al carrito evita recorrer el catálogo; desde allí Elegir/Cambiar cliente vuelve directamente al selector. La lista del carrito tiene desplazamiento propio para ventas con muchas líneas.

Confirmar crea atómicamente orden confirmada, entrega realizada y factura registrada. No crea un pago. El precio usa catálogo más comisión configurada e impuestos, sin descuentos ni edición de precios. Se respetan variantes concretas, reservas y disponibilidad. Series, lotes y vencimientos conservan su comportamiento automático; no se agregó captura manual ni escaneo.

El carrito se guarda por usuario, empresa y vendedor. No reserva stock. Sin conexión se conserva, pero no puede confirmarse. Al reconectar se vuelve a validar. No hay ventas ni pagos en cola sin conexión.

Desde **Mis ventas → abrir venta → Registrar cobro** se registra importe, moneda, fecha, cuenta, medio, referencia y fotos/PDF. Funciona con cualquier vencimiento. Se usa la tasa vigente cuando corresponde otra moneda y se impide superar el saldo por cubrir. Cuenta y medio deben pertenecer a la empresa activa.

El cobro nativo queda aplicado a la factura y pendiente de validación del jefe. La pantalla distingue saldo sin confirmar, cobros en revisión y saldo por cubrir. Las claves de reintento impiden duplicados y los comprobantes se guardan privadamente dentro de la transacción. El cobro no confirma dinero ni liquida comisiones. La conciliación conserva el flujo y permisos de Contabilidad.

## Imprimir documentos desde una venta

El detalle de una venta incluye **Imprimir factura**, **Imprimir ticket de factura**, **Imprimir orden de venta** e **Imprimir entrega**. Cada acceso abre en una pestaña nueva el formato existente del documento relacionado, conservando abierta la venta. Desde la vista de impresión se imprime o se guarda como PDF; el ticket permite elegir papel de 80 o 58 mm. Se requieren los permisos de consulta del documento correspondiente. Se sustituye el botón que imprimía únicamente el resumen de la venta directa.

## Enviar documentos desde una venta

En **Ventas Directas → Mis ventas / Ventas realizadas → abrir una venta** aparecen **Enviar por correo** y **WhatsApp** cuando el usuario puede consultar y enviar la factura de cliente relacionada y está activa la empresa del documento. Ambos abren los mismos asistentes de la factura dentro de una ventana emergente, sin salir de la venta directa.

El destinatario, la plantilla, las tasas, las opciones Mensaje / Factura / Orden de venta / Entrega y el historial provienen de los mecanismos existentes. No se crea otra factura ni otro historial independiente. Correo utiliza el SMTP de la empresa; WhatsApp conserva Preparar mensaje, Abrir WhatsApp y Copiar. Las opciones de documentos también respetan los permisos de consulta de cada módulo.

La incorporación de los botones no concede permisos a grupos existentes. Para autorizar un vendedor, configurar en su grupo la consulta y el envío de **Facturas y notas de crédito de clientes**, con alcance **Propios** cuando corresponda (`contabilidad.customer_invoices.read` y `.send`; el permiso de envío también habilita marcar como enviada). Los asistentes mantienen su propia validación en el servidor.

## Crédito

Ventas Directas → Crédito permite definir por cliente un límite y **Advertir y permitir** (predeterminado) o **Bloquear**. Límite vacío significa sin límite; cero advierte ante cualquier nuevo crédito. Guarda la moneda del límite y convierte según configuración vigente.

El saldo incluye facturas de cliente registradas de la empresa activa, menos notas registradas y cobros confirmados. Los cobros pendientes siguen siendo deuda. Se advierte por vencidos o si saldo más nueva venta supera el límite. Se muestra en el carrito y se conserva en el resultado. El servidor revalida, bloquea solo con política explícita Bloquear y serializa la comprobación por cliente.

Aplica a nuevos plazos con vencimiento futuro en Ventas Directas; no modifica reglas de otros módulos ni bloquea ventas con vencimiento hoy. Los términos se administran en Contabilidad.

## Abastecimiento y retorno de sobrantes

El administrador entra sin seleccionar vendedor. **Inventario para abastecer** consulta cantidades a la mano, reservadas y disponibles en almacenes de la empresa activa no asignados a ningún vendedor, incluidos inactivos.

1. El vendedor envía Solicitar mercancía o Retornar sobrantes, con cantidades y nota. No mueve stock.
2. El supervisor consulta el almacén central y aprueba cantidades, totales o parciales. Queda Aprobada, sin reserva ni transferencia.
3. Cuando ocurre la entrega física, pulsa Entregar mercancía o Recibir retorno. Se genera y completa la transferencia interna en el sentido correspondiente.

El servidor revalida almacén central, ubicación del vendedor, cantidades y seguimiento. Si falta stock permanece aprobada, sin movimiento parcial. Reintentar un movimiento completado devuelve el mismo. Antes del movimiento pueden cancelarse/rechazarse solicitudes; rechazar exige motivo. Un cambio de ubicación del vendedor exige cancelar y volver a solicitar. Lo no aprobado no se transfiere ni se vuelve a solicitar automáticamente. No incluye tránsito en dos movimientos entre almacenes.

## Devoluciones del cliente

Desde una venta puedes elegir **Devolver toda la venta** o **Devolución parcial** e indicar el motivo. La total incluye automáticamente las cantidades que aún pueden devolverse, calculadas en el servidor según lo entregado y las devoluciones/notas previas; la parcial permite escribir cantidades dentro de ese límite. Solo puede existir una solicitud pendiente por venta. Enviar no cambia stock ni factura.

El supervisor confirma la recepción física. Se generan atómicamente devolución de la entrega original y nota de crédito registrada, conservando precios, impuestos y seguimiento. La devolución total revierte la factura mediante notas de crédito: conserva el documento registrado y marca su cobro como **Revertido**. La parcial ajusta únicamente lo devuelto. La última devolución de cada línea absorbe sus diferencias de redondeo para no dejar centavos pendientes. El saldo y las comisiones se ajustan con las reglas nativas. La venta y la devolución muestran el resultado. No hay sobredevoluciones ni duplicación por reintento.

Deben resolverse antes los cobros pendientes de revisión y otros borradores de devolución/nota. La mercancía vuelve a la ubicación de la entrega original, aunque después se haya cambiado la asignación del vendedor. No se reembolsa dinero automáticamente: se gestiona desde la nota en Contabilidad, con sus permisos. El supervisor puede rechazar con motivo.

## Jornada y cierre

**Cierre** reúne ventas directas facturadas en la fecha, vencimientos, saldos, cobros registrados ese día asociados a esas ventas (también abonos de facturas anteriores), confirmados/pendientes, cuenta/medio, reembolsos y comisiones de ventas de la jornada. Incluye movimientos de inventario por fecha real de registro y ubicación actualmente asignada; para investigar cambios de ubicación se usan los reportes nativos.

El efectivo esperado cuenta cobros/reembolsos de caja registrados por el propio vendedor o su registro directo. No atribuye al vendedor cobros hechos por el administrador. No es arqueo de la caja completa: no incluye fondos iniciales ni gastos ajenos a estas ventas. Las existencias se capturan al generar el resumen, con fecha/hora visible.

El vendedor declara efectivo por moneda y envía un cierre por jornada. Se conservan snapshot y diferencias. El supervisor valida su revisión. No confirma pagos, liquida comisiones, mueve caja ni bloquea operaciones posteriores. Los cobros posteriores aparecen en la consulta actual y no reescriben el cierre enviado. Es inmutable; no incluye reapertura, corrección de declaraciones ni conteo por denominaciones.

## Verificación

- php test/direct-sales.php --browser: base aislada, documentos nativos, plazos, crédito, permisos y aislamiento empresarial, abonos idempotentes pendientes, comprobantes privados, devoluciones, transferencias y cierres. Chrome verifica celular/escritorio, desconexión, carrito, cobro desde el módulo y jornada.
- php test/migrations.php y node test/deploy-startup.js: migraciones y arranque.
- php test/fresh-installation.php: instalación vacía, cinco migraciones y 17 vistas en raíz, sin datos operativos de ejemplo.

Los historiales muestran los últimos 100 registros autorizados. La prueba local no despliega ni valida la configuración del servidor de producción.

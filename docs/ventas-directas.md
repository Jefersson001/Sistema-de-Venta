# Ventas Directas

Módulo integrado de Vent-As para celular y computadora. Usa los documentos nativos: órdenes, entregas, facturas, cobros, notas de crédito y transferencias.

## Instalación y permisos

Las migraciones 20261113131544, 20261113161546 y 20261113171011 agregan tablas empresariales mediante registerCompanyTable(), catálogos y permisos. Aplicar con php php/migrate.php o el arranque de Dokploy. No importar respaldos ni reinstalar una base existente.

- **Vendedor de venta directa:** opera con su contacto vendedor propio, vende, solicita mercancía y retornos de sobrantes, registra abonos pendientes, solicita devoluciones del cliente y envía su cierre.
- **Supervisor de venta directa:** consulta inventario de abastecimiento y ventas, aprueba solicitudes, confirma su entrega física, revisa devoluciones y valida cierres. No recibe permisos generales para editar inventario o confirmar dinero.
- **Administrador:** supervisa y configura crédito sin actuar como vendedor. Para vender además debe asignarse expresamente un grupo vendedor y vincular su propio contacto. Los permisos de confirmación de cobros siguen siendo contables.

Las migraciones no asignan grupos automáticamente. Revisar también grupos personalizados, pues los permisos se combinan. Crear requiere contacto vendedor propio aunque el alcance sea Todos. Aprobar, entregar, recibir devoluciones y validar cierres requiere alcance Todos. El vendedor necesita almacén y ubicación interna; si hay una única ubicación interna en su almacén puede resolverse automáticamente. Con alcance Propios, los clientes deben estar asignados al vendedor.

## Venta, plazo y cobro

El vendedor elige cliente y **plazo de pago**. Contado vence hoy; los plazos de días vencen después. La clasificación interna contado/crédito se deriva del plazo y no significa que se recibió dinero.

Confirmar crea atómicamente orden confirmada, entrega realizada y factura registrada. No crea un pago. El precio usa catálogo más comisión configurada e impuestos, sin descuentos ni edición de precios. Se respetan variantes concretas, reservas y disponibilidad. Series, lotes y vencimientos conservan su comportamiento automático; no se agregó captura manual ni escaneo.

El carrito se guarda por usuario, empresa y vendedor. No reserva stock. Sin conexión se conserva, pero no puede confirmarse. Al reconectar se vuelve a validar. No hay ventas ni pagos en cola sin conexión.

Desde **Mis ventas → abrir venta → Registrar cobro total o parcial** se registra importe, moneda, fecha, cuenta, medio, referencia y fotos/PDF. Funciona con cualquier vencimiento. Se usa la tasa vigente cuando corresponde otra moneda y se impide superar el saldo por cubrir. Cuenta y medio deben pertenecer a la empresa activa.

El cobro nativo queda aplicado a la factura y pendiente de validación del jefe. La pantalla distingue saldo sin confirmar, cobros en revisión y saldo por cubrir. Las claves de reintento impiden duplicados y los comprobantes se guardan privadamente dentro de la transacción. El cobro no confirma dinero ni liquida comisiones. La conciliación conserva el flujo y permisos de Contabilidad.

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

Desde una venta se solicitan cantidades y motivo. Solo puede existir una solicitud pendiente por venta. Enviar no cambia stock ni factura, ni permite superar cantidades pendientes de devolver.

El supervisor confirma la recepción física. Se generan atómicamente devolución de la entrega original y nota de crédito parcial registrada, conservando precios, impuestos y seguimiento. El saldo y las comisiones se ajustan con las reglas nativas. No hay sobredevoluciones ni duplicación por reintento.

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

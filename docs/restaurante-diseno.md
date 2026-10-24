# Restaurante: auditoría y diseño propuesto

Fecha: 24 de octubre de 2026. Estado: base instalada localmente con pedido editable, facturación, pagos combinados, vuelto y reparto por importe o productos sobre una factura compartida. Cocina, anticipos durante el servicio y facturas independientes por comensal siguen pendientes. InfinityFree gratuito queda fuera de los requisitos por indicación del usuario. Instalación, alcance y pruebas: [Restaurante](../modules/restaurante/README.md).

## Ajuste de experiencia solicitado: flujo corto

El usuario pidió simplificar la experiencia a **mesas → productos → facturar**. Esta decisión sustituye el recorrido principal de apertura manual y pantallas operativas separadas descrito originalmente. La configuración inicial permite crear varias mesas de una vez; el mesero se asigna al usuario que toca la mesa. Pedidos e importes aparecen juntos y las opciones avanzadas quedan plegadas.

Se incorporó un pedido editable mediante `restaurant_account_carts` y `restaurant_account_items`, enlazado al catálogo y documentos existentes. El cobro emite una factura y confirma el pago completo con uno o varios medios, de forma atómica. Permite repartir por persona/importe o productos, conservando una factura compartida. El efectivo y vuelto se registran con el mecanismo de Tesorería existente. Al terminar se ofrece un comprobante imprimible y guardable como PDF desde el navegador. El cliente habitual es una preferencia por usuario y empresa guardada en el dispositivo. Las facturas independientes por comensal, cocina y anticipos siguen pendientes. El README indica el alcance operativo actual.

**Inventario del flujo corto:** mientras no haya envío a cocina, agregar productos solo guarda un borrador. Confirmar factura y cobro es el único punto que valida y descuenta existencias. La propuesta original de consumir al enviar una comanda corresponde al desarrollo posterior de cocina; no se aplicarán simultáneamente ambos descuentos.

## 1. Diagnóstico comprobado

Se inspeccionaron rutas, controladores, repositorios, instaladores, pruebas y metadatos de la base local mediante consultas de lectura. No se consultaron datos personales ni saldos para elaborar este documento.

- PHP 8.4.24 en el ejecutable CLI de XAMPP; la versión de Apache o PHP del alojamiento no se deduce de ese dato.
- MariaDB 10.4.32 local. PDO MySQL, BCMath, sesiones, DOM y mbstring disponibles en CLI.
- Aplicación PHP propia, con funciones y vistas PHP; no se detectaron Laravel, Symfony ni una aplicación SPA. JavaScript nativo y CSS compartido.
- Rutas públicas en `modules/`, lógica en `php/`, plantillas en `html/`, recursos en `css/` y `js/`, pruebas en `test/`, documentación en `docs/`.
- `php/config/modules.php` registra los módulos; `php/module-page.php` y `html/module-placeholder.php` construyen sus páginas. El nuevo módulo debe usar el diseño actual de los módulos.
- Los instaladores son scripts CLI. Hay migraciones SQL y ampliaciones idempotentes en archivos `*-schema.php`.
- No se detectaron entidades de restaurante, recetas, turnos de caja, reservas de mesas ni una cola de impresión.

### Componentes que se reutilizarán

| Necesidad | Tablas o archivos existentes | Uso previsto |
|---|---|---|
| Productos y categorías | `inventory_products`, `inventory_categories` | Único catálogo de productos; solo artículos habilitados para venta |
| Existencias y trazabilidad | `inventory_stock`, `inventory_stock_moves`, `inventory_operations`, `inventory_operation_lines` | Todas las salidas y devoluciones físicas |
| Reservas de mercancía | `inventory_reservations` | Misma comprobación de disponibilidad, sin otro balance |
| Almacenes y ubicaciones | `inventory_warehouses`, `inventory_locations` | Origen configurable del restaurante |
| Clientes y vendedores | `contacts` | Cliente de facturación y, si corresponde, relación comercial del vendedor |
| Venta definitiva | `sales_quotations`, `sales_quotation_lines` | Documento existente en estado `sale` al consolidar la cuenta |
| Facturas y notas de crédito | `accounting_invoices`, `accounting_invoice_lines` | Mismo circuito de facturación y corrección |
| Cobros y aplicaciones | `accounting_payments`, `accounting_invoice_payments` | Anticipos, pagos parciales y múltiples medios |
| Caja y bancos | `accounting_payment_accounts`, `accounting_payment_methods`, `accounting_treasury_entries`, `accounting_treasury_documents`, `accounting_cash_concepts` | Dinero real, vuelto, ingresos y reversiones |
| Monedas e impuestos | `accounting_currencies`, `accounting_currency_rates`, `accounting_currency_settings`, `business_taxes` | Cálculos y conversión actuales |
| Usuarios y permisos | `auth_users`, `auth_user_groups`, `access_groups`, `access_group_permissions`, `access_permissions` | Sesión y grupos existentes |
| Empresa y autoría | `CompanyConnection`, `registerCompanyTable`, `company_user_access` | Aislamiento y relaciones de la misma empresa |

### Relaciones relevantes verificadas

`sales_quotation_lines.quotation_id` identifica su venta; cada línea contiene producto, nombre, cantidad, precio, impuesto, costo, política de facturación y datos de comisión históricos.

`inventory_operations.quotation_id` relaciona la entrega con la venta. `inventory_operation_lines.sale_line_id` enlaza la cantidad física con su línea comercial. Las devoluciones usan `return_of_id` y `return_line_id`. Las existencias pertenecen al par producto/ubicación; los movimientos conservan operación y línea.

`accounting_invoices.sale_id` enlaza la factura con la venta; `accounting_invoice_lines.sale_line_id` conserva la trazabilidad por artículo. Las notas de crédito referencian la factura original.

Un pago pertenece a un contacto, cuenta monetaria y método. `accounting_invoice_payments` distribuye el pago entre facturas. Se admiten pagos confirmados sin aplicar: permiten reutilizar el mecanismo para anticipos. El movimiento real de dinero se genera al confirmar el pago, no al mostrar el cobro.

### Límites y riesgos concretos

1. **La venta confirmada no admite edición.** `saveSalesQuotation()` rechaza cambios en estado `sale` y al guardar un borrador reemplaza sus líneas. No debe usarse como cuenta de mesa continuamente editable.
2. **Los repositorios abren y cierran sus propias transacciones.** Ya existe `TransferTransactions::atomicTransfer()`, usado para operaciones de importación, con transacción exterior y savepoints e integrado en `CompanyConnection`. Se evaluará reutilizar ese mecanismo mediante una interfaz de operación atómica de propósito general, conservando el comportamiento de importaciones. No se encadenarán commits independientes ni se duplicará el coordinador. Solo se extraerán primitivas adicionales cuando el enlace comercial o la validación del restaurante lo requiera.
3. **Multiempresa es obligatorio.** Las nuevas tablas deben registrarse en `registerCompanyTable()`. Las relaciones cruzadas se comprueban con triggers, además del filtro de conexión. El código actual depende de esos triggers; la copia previamente adaptada para alojamiento es otro artefacto y no demuestra compatibilidad del árbol actual con ese alojamiento.
4. **La auditoría actual registra autor y última modificación**, pero no un historial completo de estados, motivos y valores anteriores. Hace falta un registro de eventos del restaurante.
5. **Hay cajas y bancos, pero no un circuito de turnos y arqueos.** `opening` es saldo inicial de tesorería; no equivale a apertura diaria de cajero.
6. **No se detectó impresión especializada ni emisión fiscal externa.** Se reutilizará la factura interna existente. Eso no certifica integración con equipos fiscales ni cumplimiento fiscal específico.
7. **El producto no tiene código comercial ni código de barras.** La primera búsqueda por código puede usar su ID identificado como tal; se propone agregar `internal_code` opcional al catálogo existente para un código configurable, único por empresa cuando exista.
8. **No existen descuentos y propinas estructurados para este flujo.** Deben conservarse por separado y concordar con documentos y dinero; no basta con restarlos o sumarlos en pantalla.
9. **El mesero es un usuario; el vendedor actual es un contacto.** Asignar un mesero no debe activar automáticamente comisiones comerciales. Su asociación será explícita.
10. **No se comprobó infraestructura de conexiones persistentes.** El destino de despliegue condiciona SSE y la impresión directa.

## 2. Decisiones propuestas

### Cuenta operativa y venta definitiva

La cuenta del restaurante agrupa pedidos durante la atención. Sus artículos se conservan como registros operativos e históricos, sin crear ventas por cada actualización.

Al finalizar el consumo se bloquea la cuenta para nuevas comandas y se confirma un plan de facturación: una factura para toda la mesa o facturas independientes por comensal. Cada unidad de facturación genera una venta existente y su factura, con sus artículos y cliente. Solo después de comprobar todos los documentos y saldos se cierra la cuenta. Si hay saldo pendiente queda en cobro: los documentos ya generados se reutilizan, nunca se generan otros al reintentar.

Una cuenta consolidada no vuelve al estado de pedidos editables. Las correcciones posteriores usan devoluciones, notas de crédito y reversiones autorizadas. Los cobros se pueden hacer antes de consolidar como anticipos vinculados al mismo cliente. Cambiar cliente después de recibir dinero exige resolver los anticipos; no se trasladan silenciosamente a otro contacto.

El usuario ha elegido **ambas opciones**, seleccionables por el cajero antes de emitir:

- **Factura compartida:** una unidad de facturación con el consumo completo. Las personas pueden repartirse el pago por artículos o importes y usar varios medios, conservando una sola factura.
- **Facturas independientes:** varias unidades de facturación, cada una con su cliente y consumo asignado. Un artículo compartido se reparte por cantidad, dentro de la precisión admitida, conservando precio e impuesto. La suma de cantidades e importes asignados debe coincidir con la cuenta; ningún consumo se puede asignar dos veces. Dividir solo el pago por un importe arbitrario no inventa líneas comerciales: si el reparto no puede representarse en cantidades válidas, se ajusta el reparto o se utiliza factura compartida.

Ejemplo: Ana consume 10 y Luis 15. El cajero puede emitir una factura de 25 con dos pagos o dos ventas/facturas, de 10 y 15, vinculadas a la misma atención. Cada pago real continúa siendo `accounting_payments`.

El plan se congela al emitir documentos. Cambiarlo después requiere el proceso de corrección correspondiente. Los anticipos se aplican únicamente a documentos del mismo cliente y sentido; un anticipo de un cliente no se reasigna automáticamente a la factura de otro comensal. Antes de emitir se mostrará cualquier anticipo incompatible y se exigirá resolverlo mediante devolución/reversión y nuevo cobro autorizado, sin duplicar ingresos.

### Punto único de consumo

**Confirmar y enviar una comanda** confirma el consumo de todos sus artículos de mercancía en una transacción. Borradores no reservan ni descuentan. Si no hay existencias disponibles, se rechaza el envío completo y cocina no recibe una comanda parcial oculta.

La transacción crea y valida operaciones del inventario existente, conserva los enlaces artículo/línea de operación y publica el evento de envío. No escribe balances mediante un segundo motor. Los servicios no generan movimientos.

Como todavía no existe la venta definitiva, la operación nace con referencia al pedido y sin `quotation_id`. Al consolidar se crean las líneas de venta y asignaciones comerciales de las cantidades ya consumidas. Una operación puede abastecer varias ventas cuando se divide la factura: no se fuerza un único `quotation_id` ni un único `sale_line_id` sobre una salida compartida. Una tabla de asignaciones enlaza cada cantidad consumida con su línea de venta mediante un servicio restringido, auditado e idempotente. No modifica cantidades, fechas ni movimientos físicos realizados ni utiliza la pantalla genérica de edición de operaciones hechas.

En la misma transacción se verifican cantidades netas consumidas frente a cantidades facturables. `commerceLineProgress()` debe reconocer las asignaciones del restaurante como entregadas, netas de devoluciones, sin sumarlas otra vez por el enlace tradicional. Crear la factura o abrir la venta no debe producir una segunda salida. Las devoluciones y notas de crédito deben respetar las cantidades asignadas a cada comensal. Las operaciones de artículos anulados con merma conservan su trazabilidad operativa y no se atribuyen como venta de esos artículos.

### Anulaciones, devoluciones y merma

- Borrador: anulación sin movimiento físico, conservando el motivo cuando corresponda.
- Enviado, producto recuperable: devolución mediante el circuito actual, enlazada a la salida original y limitada a su cantidad pendiente de devolver.
- Preparado no recuperable: se mantiene la salida original y se registra una clasificación de merma y su motivo. **No se descuenta otra vez ni se devuelve automáticamente a stock vendible.**
- Cantidad parcialmente anulada: se registra la cantidad afectada y su destino; no se cambia un estado para ocultar la parte restante.
- Facturado/cobrado: crédito y reembolso según los servicios actuales. No se borran ventas o pagos confirmados.

### Precios, descuentos, extras y propinas

Precio, moneda, tasa, impuesto, costo y descripción se congelan al confirmar cada artículo. Un cambio del catálogo solo afecta artículos nuevos. Se conservan importes como DECIMAL y se usa BCMath, con las escalas actuales: cantidades de tres decimales e importes de dos.

Los extras cobrables deben referenciar productos existentes. Así conservan sus propios impuestos y, si son mercancía, sus propias existencias. Las instrucciones gratuitas se guardan como modificadores/observaciones. No se implementan recetas ni consumo de ingredientes.

El descuento autorizado se registra antes de facturar, con importe original, descuento y precio neto; el cálculo de impuestos sigue una política única. Un descuento global se distribuye por líneas con redondeo determinista. Las líneas de venta/factura conservarán estos datos para que el comprobante coincida con el cobro. Los documentos anteriores conservarán descuento cero.

La propina será opcional, explícita y separada de ventas e impuestos de productos. Se registrará con un concepto de tesorería configurado y enlaces al pago/documento real, incluyendo su reversión. Si un cobro incluye consumo y propina, se desglosa en importes enlazados que sumen lo recibido, sin duplicar el movimiento. No se publicará una opción de propina hasta verificar ese circuito.

## 3. Diseño de datos propuesto

Se usarán nombres singulares como en gran parte del esquema actual cuando corresponda; los siguientes nombres nuevos se fijan con prefijo `restaurant_` para distinguir el dominio. Las tablas son necesarias por funcionalidad, pero se crearán por fase, no de una vez.

Todas las tablas empresariales tendrán las columnas y relaciones de empresa/autoría instaladas por `registerCompanyTable()`. IDs referenciados conservarán el tipo `INT UNSIGNED` del esquema actual. Cantidades `DECIMAL(12,3)`, precios `DECIMAL(12,2)` y totales `DECIMAL(18,2)`; las magnitudes acumuladas se comprobarán antes de guardar.

| Tabla nueva | Contenido y relaciones principales | Integridad e índices |
|---|---|---|
| `restaurant_settings` | Empresa, ubicación de salida, cliente de consumo general configurable, moneda, política de propina, umbral de retraso | Una configuración por empresa; no crear cliente ficticio automáticamente |
| `restaurant_areas` | Nombre, orden, activo | Nombre único por empresa; archivar sin borrar historial |
| `restaurant_tables` | Área, nombre/número, capacidad, coordenadas del mapa, activo, estado de servicio, versión | Nombre único por empresa/área; capacidad positiva; estado libre/reservada/limpieza; ocupación derivada del enlace activo |
| `restaurant_kitchen_stations` | Nombre, tipo cocina/barra/otro, activo, umbral de retraso | Nombre único por empresa |
| `restaurant_product_settings` | Producto existente, estación, habilitado en carta, orden visual | Una fila por producto; producto y estación de la misma empresa |
| `restaurant_accounts` | Referencia, cliente inicial, mesero usuario, estado, apertura/cierre, versión, moneda y tasa, modalidad de facturación | Índice estado/apertura y mesero/estado; documentos a través de unidades de facturación |
| `restaurant_table_assignments` | Cuenta, mesa, asignación, liberación, usuario y motivo | Historial conservado; índice cuenta/fechas |
| `restaurant_active_tables` | Mesa y asignación vigente | PK sobre mesa: impide dos cuentas simultáneas incluso con carreras; relación validada con asignación y cuenta |
| `restaurant_orders` | Cuenta, secuencia, estado borrador/enviado/anulado, versión, envío | Secuencia única por cuenta; índice cuenta/estado |
| `restaurant_order_items` | Pedido, producto, cantidad, snapshots comerciales, observaciones, asiento/persona opcional, estado de preparación, cantidades anuladas | Índices pedido y producto; cantidades positivas y anulaciones limitadas; enviados inmutables comercialmente |
| `restaurant_modifier_options` | Producto padre, nombre, producto extra nullable, activo, selección obligatoria/opcional | Referencia al catálogo existente; sin inventario propio |
| `restaurant_order_item_modifiers` | Artículo, opción nullable, nombre e importe históricos, artículo extra nullable | Mantener snapshot aunque se archive la opción; extra cobrado enlazado a su artículo real |
| `restaurant_dispatches` | Pedido, lote de envío y hora | Lote único por pedido/secuencia; historial sin borrar |
| `restaurant_order_item_dispatches` | Artículo, lote, estación congelada, recepción, versión | Un envío inicial por artículo; reimpresión no crea otro envío |
| `restaurant_item_stock_links` | Artículo, línea de inventario, finalidad consumo/devolución, cantidad | Línea de operación única; índice artículo/finalidad; movimientos reales en tablas existentes |
| `restaurant_billing_units` | Cuenta, secuencia, cliente, estado, versión, venta existente nullable | Secuencia única por cuenta; venta única cuando exista; una unidad en modo compartido, varias en modo independiente |
| `restaurant_billing_items` | Unidad de facturación, artículo, cantidad, importes e impuesto históricos, línea de venta existente nullable | Suma asignada limitada al consumo facturable; línea comercial única; redondeo distribuido y conciliado |
| `restaurant_stock_sale_allocations` | Vínculo de consumo/devolución, artículo facturado y cantidad asignada | Unicidad vínculo/artículo facturado; sumas limitadas bajo bloqueo; no crea movimientos físicos |
| `restaurant_item_adjustments` | Artículo, cantidad, recuperable/merma/cortesía, motivo, enlaces de devolución/crédito cuando existan | Cantidad limitada al remanente, historial inmutable |
| `restaurant_account_payments` | Cuenta, pago existente, finalidad anticipo/consumo/propina, importe atribuido en moneda de cuenta y tasa aplicada | Sin otro saldo de dinero; unicidad pago/cuenta/finalidad; límite global de atribuciones bajo bloqueo |
| `restaurant_account_splits` | Cuenta, etiqueta persona/porción, modo, importe objetivo, versión | Suma de repartos igual a total; residuo de redondeo explícito |
| `restaurant_split_items` | Reparto, artículo, cantidad e importe asignados | No exceder cantidad/importe del artículo; control transaccional |
| `restaurant_split_payments` | Reparto, vínculo de pago de cuenta, importe asignado | No superar pago atribuido ni saldo del reparto; es distribución, no otro cobro |
| `restaurant_account_documents` | Cuenta, unidad de facturación, factura/nota de crédito existente | Documento único; enlace al circuito existente, no una factura paralela |
| `restaurant_audit_events` | Cuenta/pedido/artículo/mesa afectados, acción, actor, motivo, estado anterior/nuevo, cantidad, datos de evento | Append-only; índice empresa/id y entidad/fecha; sirve también para notificaciones recuperables |
| `restaurant_requests` | Operación, clave idempotente, hash de parámetros, resultado, estado | Única empresa/operación/clave; clave y resultado en la misma transacción del efecto |
| `restaurant_print_jobs` | Lote, estación, destino, número de copia, estado, intentos, confirmación | Un trabajo inicial por lote/destino; reimpresiones explícitas auditadas |

Los eventos pueden usar JSON para detalles complementarios; las FK y cantidades críticas serán columnas tipadas. No se usará JSON como sustituto de relaciones de pagos o existencias.

Los estados se limitarán con ENUM o CHECK donde sea compatible, además de la máquina de estados del servidor. Las FK no borrarán documentos operativos en cascada desde catálogos. Las restricciones UNIQUE se adaptarán al registro multiempresa; se verificará especialmente su efecto en las tablas de enlace.

### Turnos y arqueos: ampliación compartida de Contabilidad

Se proponen `accounting_cash_sessions` (caja existente, cajero, inicio, fin, estado y versión), `accounting_active_cash_sessions` (ocupación única de caja), `accounting_cash_counts` (sesión, moneda, saldo inicial declarado, esperado y contado) y `accounting_cash_session_entries` (sesión y entrada real de tesorería única).

El dinero seguirá exclusivamente en `accounting_treasury_entries`. El turno atribuye esos movimientos, incluidos anticipos, transferencias y reversiones, para calcular esperado/contado/diferencia por moneda. El saldo inicial del turno no vuelve a ingresar dinero ya existente. Los cobros nuevos y sus reversiones deberán asociarse al turno correspondiente mediante los servicios compartidos cuando la caja opere con control de turnos.

### Ampliaciones puntuales del esquema existente

- `inventory_products.internal_code`, opcional y único por empresa para códigos comerciales.
- Datos de descuento originales y netos en líneas de venta/factura, con valores neutros para registros anteriores y copia correcta a créditos.
- Enlaces restaurante→operaciones y restaurante→pagos mediante las tablas anteriores; no otras tablas de ventas, balances o cobros.
- Registro de nuevas tablas en el aislamiento, etiquetas, firma de contexto, mantenimiento y eliminación de empresas.
- Permisos nuevos en el catálogo actual, sin ampliar automáticamente los grupos existentes ajenos al restaurante.

La migración final deberá especificar nombres de restricciones e índices tras generar y verificar el SQL en una copia aislada. Este diseño es lógico y técnico; todavía no es una migración ejecutada.

## 4. Operaciones y consistencia

### Abrir, mover y unir mesas

Bloquear las mesas afectadas en orden de ID; comprobar empresa, actividad, estado y ocupación. Crear cuenta/asignación y su ocupación activa en una sola transacción. La PK de mesa activa resuelve la carrera aunque dos usuarios hayan visto «libre».

Unir mesas libres las asigna a la misma cuenta. Separar una mesa libera su asignación, sin perder pedidos. Unir dos mesas ya ocupadas exige una transferencia explícita entre cuentas abiertas compatibles, con historial por pedido, cliente y moneda; no se hace por simple arrastre del mapa. Después de consolidar documentos no se fusionan cuentas. Las cuentas con anticipos requieren conservar y validar sus atribuciones monetarias.

Reservada será un bloqueo manual con usuario y motivo; la agenda de reservas con fecha, cliente y vencimiento pertenece a una fase posterior.

### Enviar una comanda

1. Validar sesión, permiso, empresa, versión y clave idempotente con hash del contenido.
2. Bloquear cuenta/pedido y confirmar que siguen abiertos; congelar precios, impuestos y estación.
3. Confirmar disponibilidad y salida con el motor actual, bajo los mismos bloqueos ordenados de existencias.
4. Crear lote, vínculos de artículos, evento y trabajos de impresión; incrementar versiones.
5. Confirmar la transacción. Cocina solo consulta datos confirmados. El transporte y la impresión ocurren después del commit.

El mismo reintento devuelve los IDs originales; una clave repetida con contenido distinto devuelve conflicto. Un fallo antes del commit no deja ni salida ni comanda. Un fallo de conexión después del commit se resuelve consultando la misma clave, no generando otra.

Los artículos nuevos se guardan en otro pedido/lote; no se modifica ni se reenvía la cantidad ya confirmada. Cocina opera sobre pendiente → en preparación → listo → entregado. Anulación sigue su circuito separado con motivo. Cada transición valida versión y estación autorizada.

### Cobrar y cerrar

Los anticipos usan los pagos actuales y su clave de creación. El saldo mostrado se calcula desde pagos confirmados y atribuciones efectivas; un pago pendiente o revertido no habilita cierre.

La consolidación congela el plan de facturación y crea una venta/factura existente por unidad de facturación, enlazando las cantidades consumidas previamente. Las unidades y sus documentos se crean en una transacción; la referencia única de venta por unidad y la clave de solicitud protegen el reintento. Registrar cada cobro y aplicarlo a su factura se hará con transacción e idempotencia; si hay varios medios, el lote se confirma completo o se revierte completo. Ningún pago se aplica por encima de su disponible, aunque figure repartido entre varias personas.

Cerrar exige: ningún borrador o preparación sin resolver, documentos válidos en todas las unidades, cantidades e importes de reparto consistentes, saldo exactamente cero en cada factura por las reglas actuales de conversión y propina resuelta si existe. Pagar una factura independiente no cierra la mesa si queda otra pendiente. El evento de cierre, liberación de mesas y paso a limpieza serán atómicos. «Liberada de cuenta» no significa «limpia»: el personal marca posteriormente libre.

Los bloqueos se adaptarán al orden usado por ventas, facturas, pagos y tesorería. Se verificará también el acceso desde las pantallas antiguas a documentos relacionados, para impedir carreras entre cocina/caja y una reversión contable. No bastará con bloquear solo la pantalla nueva. Ante deadlock se reintenta la transacción completa con la misma clave y un límite; no un fragmento ya confirmado.

## 5. Rutas, permisos y experiencia visual

Rutas PHP bajo `modules/restaurante/`: `index.php` (mesas), `cuenta.php`, `pedidos.php`, `cocina.php`, `caja.php`, `historial.php`, `configuracion.php`, `reportes.php` y endpoints JSON para comandos/notificaciones. Se mantendrán controladores y repositorios en `php/`, plantillas en `html/restaurant/`, estilos y JS específicos.

Cada POST tendrá CSRF, firma de empresa, permiso y validación de entidad; JSON devolverá errores consistentes: 401 sesión, 403 permiso, 409 versión/clave/estado incompatible, 422 validación y 503 indisponibilidad. Los detalles internos irán al registro del servidor, no a la respuesta.

| Grupo de referencia | Acceso propuesto |
|---|---|
| Mesero | Consultar mapa y sus cuentas; abrir, tomar y enviar pedidos; marcar entrega según permiso |
| Cocina/barra | Consultar comandas de estaciones autorizadas; recibir y cambiar preparación; sin datos financieros globales |
| Cajero | Consultar cuentas para cobro, anticipos, medios múltiples y cierre; turnos y arqueos autorizados |
| Supervisor | Transferir/unir cuentas, autorizar descuentos, anulaciones y mermas; reportes según alcance |
| Administrador | Configuración y asignación de permisos; las acciones operativas siguen siendo verificadas |

Se ampliará `permissionAssignedWhere()` para cuentas propias por usuario mesero; no se forzará la relación de vendedor comercial. Las acciones contables invocadas desde restaurante requieren una autorización explícita del comando que cubra sus efectos, sin dar al mesero permisos generales de ventas, inventario o pagos.

Mapa táctil por áreas con nombre, estado, tiempo, importe y mesero; estados identificados con texto además de color. En escritorio, carta y cuenta lateral; en móvil, selección de productos y resumen accesible con acciones grandes. Cocina agrupa por estación y hora, con observaciones visibles, temporizador y retrasos calculados desde fechas reales del servidor.

La interfaz mostrará confirmación pendiente, desconexión y reintento. No anunciará «enviado» o «pagado» sin confirmación del servidor. Borradores locales, si se añaden, se identificarán como no enviados y nunca producirán cobros offline.

### Actualización e impresión

Se propone SSE con cursor de eventos persistidos y reconexión, únicamente si el servidor permite transmitir y liberar la sesión PHP antes de mantener la conexión. Revalidará empresa y permisos, con duración limitada y reconexión; los eventos no expondrán datos ajenos. No requiere un servidor WebSocket adicional.

Si el despliegue no admite SSE, se necesita validar una alternativa de consultas periódicas con cursor y latencia documentada. No se presentará polling como WebSocket o SSE. Este punto se debe confirmar antes de desarrollar cocina.

La primera impresión puede ser una vista de comanda para el diálogo del navegador con formato de papel configurable. La impresión silenciosa o el envío directo a impresoras térmicas requiere identificar equipo/protocolo y, probablemente, un agente local. Una cola persistente no garantiza por sí sola impresión física exactamente una vez: ante un envío con resultado desconocido se mostrará pendiente de verificación; la reimpresión tendrá marca de copia y auditoría.

## 6. Reportes sin duplicación

- Ventas e impuestos: facturas registradas menos notas de crédito; pedidos borradores o comandas no cuentan como ventas.
- Cobros por método/turno: pagos confirmados y entradas de tesorería, considerando reversiones y vuelto; consumo y propina separados.
- Por mesa y mesero: enlaces de cuenta a documentos y asignaciones históricas. No sumar dos veces una cuenta con varias mesas ni una factura con varios pagos.
- Productos vendidos: líneas facturadas netas de crédito. Mermas/cortesías/anulaciones se muestran por separado.
- Preparación y ocupación: fechas de eventos y asignaciones reales; los servicios abiertos se identifican como aún no terminados.
- Cuentas abiertas/saldos: consumo vigente y pagos efectivos, separado por moneda.
- Existencias: movimientos reales enlazados a artículos. Diferencias de caja: contado frente a esperado por turno y moneda.

## 7. Entregas y validación

| Fase | Entrega funcional | Verificación mínima |
|---|---|---|
| 1. Auditoría | Este diagnóstico y diseño para validación | Lectura de arquitectura y esquema local; sin cambios de datos |
| 2. Base | Configuración, áreas, mapa, mesas, cuentas y traslado/unión | Migración repetible, aislamiento, permisos, apertura concurrente sin duplicados |
| 3. Pedidos | Carta compartida, precios históricos, observaciones, extras, borradores | Productos inactivos, cambio de precio, dos meseros, ediciones obsoletas, borrador sin consumo |
| 4. Cocina | Envío transaccional con consumo integrado, estaciones, estados, notificación e impresión | Reenvíos, falta de stock, fallo antes/después del commit, misma comanda una vez |
| 5. Caja | Venta/factura existentes, pagos parciales/múltiples, reparto, descuentos, propina, devoluciones, merma y turnos | Saldo, redondeo, exceso de pago, cierre concurrente, reembolso, ningún segundo descuento de stock |
| 6. Despliegue | Reportes, documentación, instalación y recuperación | Regresión de módulos actuales, pruebas móviles y ensayo de migración/restauración |

El envío de cocina debe incluir desde el principio la integración física de inventario: no se pospone a una fase posterior un envío aparentemente funcional que ignore existencias. En fase 5 se completa su enlace comercial y el circuito de correcciones.

Cada fase incluirá instalador/migración y pruebas aisladas. Las migraciones no borrarán datos del negocio. Antes de DDL se hará respaldo verificable; MariaDB no revierte DDL con un rollback transaccional. La reversión automática solo quitará estructuras nuevas vacías; con movimientos reales requerirá un procedimiento de recuperación probado, nunca borrar el historial para desinstalar.

Se reutilizará `test/auth-server.php` para una base temporal y usuarios de prueba. Se añadirán trabajadores concurrentes para apertura, envío, consumo de la última unidad, pago y cierre. Se comprobarán conflictos de empresa/permiso y reintentos tras pérdida de respuesta.

La regresión completa cubrirá `test/commerce-workflows.php`, `test/commerce-partials.php`, `test/accounting-payments.php`, `test/treasury.php`, `test/cash-change.php`, `test/multi-company.php`, `test/role-workflows.php` y pruebas de interfaz relevantes. Para la entrega de fase 2 pasaron `test/restaurant.php` con revisión real en navegador a 1440 y 390 píxeles, `test/role-workflows.php` y `test/multi-company.php`. El alcance y las instrucciones están en el README del módulo.

## 8. Decisiones del usuario y comprobaciones de implementación

1. Ambas modalidades de factura quedan incluidas: compartida y separada por comensal. La elección precede a la emisión.
2. InfinityFree gratuito queda fuera del alcance. Su compatibilidad no condicionará la arquitectura; la transmisión SSE se comprobará en el servidor de despliegue.
3. La impresión puede comenzar con el diálogo del navegador. El equipo y protocolo solo se necesitan antes de integrar impresión directa.
4. Se desarrollará primero la base de áreas, mesas y cuentas, sin activar funciones económicas incompletas en producción.

Las pruebas de cobro incluirán ambos modos, un mismo producto compartido entre comensales, anticipos de cliente incompatible, reintento tras emitir varias facturas, devolución parcial de un artículo repartido, saldo pendiente en una sola factura y conciliación de cantidades e importes con el consumo original.

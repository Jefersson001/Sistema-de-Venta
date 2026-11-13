# Ventas Directas

Acceso desde la tarjeta **Ventas Directas** del inicio y desde la búsqueda de secciones. La aplicación funciona con navegador en teléfonos y computadoras; requiere conexión para confirmar operaciones. Rutas: `modules/venta_directa/index.php` y `modules/venta_directa/solicitudes.php`. No es una aplicación independiente ni crea un inventario paralelo.

## Configuración y permisos

La migración adicional `20261113161546_separar_supervision_ventas_directas` separa supervisión y venta. El grupo Administrador conserva consulta y aprobación, y recibe `venta_directa.stock.read`, pero deja de incluir `venta_directa.sales.create` y `venta_directa.requests.create`. El Supervisor recibe consulta del inventario de abastecimiento. Se conservan los demás permisos personalizados y las asignaciones de usuarios. Para que un administrador también venda debe asignarse expresamente un grupo vendedor y vincularse con su propio contacto vendedor: no se permite operar con un vendedor ajeno, aunque Crear tenga alcance Todos.

Los administradores y supervisores entran al inventario para abastecimiento sin seleccionar vendedor. Pueden consultar productos, cantidades a la mano, reservas y disponibilidad por almacén/ubicación. Solo aparecen almacenes de la empresa activa que no estén asignados a ningún contacto vendedor, incluyendo vendedores inactivos. No se muestran costos ni se conceden permisos generales de inventario al supervisor. El vendedor mantiene Mi inventario y no tiene acceso a esta consulta central.

Al abrir una solicitud pendiente, el supervisor elige el origen y pulsa Consultar existencias. Cada línea indica lo solicitado y lo disponible en ese origen. Aprobar y transferir utiliza el origen consultado y vuelve a comprobar stock; la disponibilidad mostrada no constituye una reserva. Para aprobar se requiere `venta_directa.requests.confirm` con alcance Todos y `venta_directa.stock.read`. El servidor también impide usar como origen un almacén asignado a un vendedor.

Desplegar el código con la migración `20261113131544_ventas_directas`. En Dokploy se aplica mediante `scripts/start-dokploy.sh`; localmente ejecutar `php php/migrate.php`. No importar respaldos ni reinstalar el esquema. La migración crea tres tablas empresariales y registra sus relaciones y auditoría mediante `registerCompanyTable()`. Puede reintentarse sin duplicar datos. Se agregan permisos al catálogo y únicamente los permisos del módulo al grupo Administrador existente; no se restablecen permisos de otros módulos ni se asignan nuevos grupos a usuarios automáticamente.

En Ajustes → Usuarios, asignar el grupo **Vendedor de venta directa** a los usuarios correspondientes y relacionarlos con su contacto vendedor de cada empresa. El contacto debe tener almacén y ubicación interna válidos. Los clientes deben estar asignados al vendedor para el alcance Propios. Los productos deben ser vendibles y concretos (variantes cuando corresponda), con existencias en la ubicación del vendedor. La empresa necesita moneda, tasa vigente y un término Contado de cero días; para crédito se utilizan términos existentes con días mayores que cero.

El grupo **Supervisor de venta directa** consulta ventas y solicitudes y permite aprobar/rechazar solicitudes con alcance Todos. Los grupos pueden administrarse desde Ajustes. El permiso Crear en Ventas Directas autoriza el circuito automatizado completo desde el contacto vendedor propio; no concede al vendedor la edición o validación manual de movimientos, la modificación de precios ni la confirmación de pagos. El supervisor puede seleccionar ubicaciones internas de la empresa activa en almacenes no asignados a vendedores. No se permite aprobar con alcance Propios.

## Venta

Si el contacto vendedor tiene almacén y no tiene ubicación explícita, Ventas Directas utiliza la única ubicación interna de ese almacén en la empresa activa. Si no existe o hay varias, se debe configurar en Contactos → ficha del vendedor → Inventario → Ubicación interna del vendedor. Una ubicación explícita inválida nunca se sustituye automáticamente.

El vendedor utiliza Vender, Mi inventario, Solicitudes y Mis ventas. Desde una computadora, el catálogo y el carrito aparecen en columnas; en teléfonos se apilan y hay navegación inferior y acceso al carrito. Los productos se buscan por nombre. El cliente utiliza el selector de contactos existentes; la creación de contactos continúa disponible en Contactos para quien tenga ese permiso.

Los precios se calculan en el servidor con las reglas nativas: precio de catálogo, comisión de vendedor e impuesto del producto, en la moneda principal de la empresa. El cliente no envía precios ni descuentos. Los padres con variantes no se venden y los artículos por series requieren unidades enteras. Se muestran existencias disponibles de la ubicación autorizada, descontando reservas y, para artículos con seguimiento, existencias vencidas o retiradas. Lotes y series se asignan automáticamente por el mecanismo nativo al comprobar disponibilidad; su detalle queda en la entrega.

Al confirmar se ejecutan, dentro de una transacción con puntos de guardado, `saveSalesQuotation`, confirmación de orden, `createLinkedStockOperation`, comprobación y validación de entrega y `workflowCreateInvoice`. La factura se registra con sus snapshots de moneda, tasa, dirección, vendedor, impuestos y comisiones. Contado vence en la fecha de factura. Crédito utiliza el término seleccionado y su vencimiento. El estado de pago se calcula mediante `invoiceRecomputePaymentState`.

No se crean pagos ni movimientos de dinero al vender. El comprobante permite abrir la factura para registrar el cobro por el flujo existente. El vendedor del nuevo grupo puede registrar cobros de sus facturas para revisión, pero no confirmarlos. Solo los cobros confirmados habilitan comisiones. Las ventas directas aparecen en Ventas, Inventario, Contabilidad, cuentas por cobrar y comisiones mediante los documentos habituales.

Si falla una validación (stock, tasa, cliente, seguimiento), se revierten los documentos y existencias de toda la operación. Una clave por confirmación y un hash de su contenido evitan duplicados por doble toque, recarga o reintento; reutilizar una clave con otro carrito devuelve conflicto. El carrito se conserva localmente por usuario, empresa y vendedor y se retira al mostrar el resultado confirmado. Esto no habilita ventas sin conexión ni reserva stock mientras se prepara el carrito. Los precios y cantidades se revalidan al confirmar.

Puede capturarse opcionalmente la geolocalización del vendedor. La del cliente se toma de sus direcciones mediante el funcionamiento normal de las órdenes. La ubicación del vendedor queda vinculada al usuario que realiza la operación. El historial muestra las últimas 100 ventas autorizadas y conserva accesos a sus documentos; para informes completos se utilizan los módulos habituales.

## Solicitudes

El vendedor selecciona productos, cantidades y una nota. Enviar no modifica ni reserva inventario. El destino se fija desde su ubicación, sin aceptar un almacén arbitrario enviado por el navegador.

El supervisor elige origen y cantidades a aprobar entre cero y lo solicitado. **Aprobar y transferir** representa que la mercancía se entrega físicamente al vendedor: crea y valida una transferencia interna, descuenta el origen y aumenta el destino en la misma transacción que resuelve la solicitud. La aprobación parcial conserva lo solicitado y lo aprobado; lo restante no se transfiere ni genera otra solicitud automáticamente. El vendedor puede solicitar nuevamente lo que necesite.

Si falta stock o cambió el destino del vendedor, se conserva la solicitud pendiente y no se crea un movimiento parcial. Si cambia su destino debe cancelarse la solicitud anterior y enviarse una nueva. Repetir una aprobación ya completada devuelve la transferencia existente. Los rechazos requieren motivo y no mueven existencias; el vendedor puede cancelar solicitudes propias pendientes. No incluye tránsito entre despacho y recepción: se requeriría otro flujo si la autorización y la entrega física ocurren en momentos distintos.

## Verificación

- `php test/direct-sales.php --browser`: base aislada; contado/crédito, documentos, precios, comisiones, impuestos, vencimiento, stock, pagos pendientes, permisos, reintentos, reversión ante errores, solicitudes, transferencia parcial, rechazo y migración repetida con datos previos. Chrome revisa celular/escritorio, carrito, vencimiento, recuperación por recarga, confirmación real y comprobante.
- `php test/migrations.php` y `node test/deploy-startup.js`: mecanismo de actualización y arranque.
- `php test/fresh-installation.php`: instalación vacía con todas las migraciones versionadas.

La revisión local no despliega el código en el servidor ni valida allí permisos y configuración de producción.

# Grupos y permisos — contrato de la fase 2

Los grupos definen permisos; no son tipos de contacto. Esta fase no crea usuarios ni aplica restricciones a los módulos actuales. La autenticación y su integración se completan en las fases 3 y 4.

## Catálogo

`php/access-catalog.php` define los módulos, secciones, acciones y grupos iniciales. Las claves de permiso son estables, por ejemplo `ventas.orders.read`, `ventas.orders.confirm`, `inventario.deliveries.validate` y `ventas.commissions.pay`.

La matriz distingue:

- Dashboard: resumen, alertas, tareas, ingresos/egresos y comisiones.
- Contactos: registros y configuración de localización/tipos.
- Ventas: órdenes, comisiones, pagos de comisiones e impuestos.
- Compras: órdenes e impuestos.
- Inventario: productos, entregas, recepciones, internos, existencias, movimientos y configuración de categorías/almacenes/ubicaciones.
- Contabilidad: facturas de clientes/proveedores, pagos de clientes/proveedores, otros ingresos, otros egresos, transferencias, saldos iniciales, historial y configuración financiera.
- Ajustes: ajustes generales, Empresa, grupos y las operaciones de Usuarios que se habilitarán en la fase 3.

Generar documentos desde una orden es una acción diferente de editarlos, confirmarlos o cancelarlos. Registrar un pago desde una factura tampoco equivale a confirmarlo. Los costos de producto y la configuración de comisiones tienen permisos separados.

## Combinación de grupos

1. Solo los grupos activos conceden permisos. Archivar conserva la definición, pero deja de aportar autorizaciones.
2. Los permisos se suman por acción. Una casilla sin marcar no es una prohibición que anule otros grupos.
3. Para una misma acción, Todos tiene prioridad sobre Propios.
4. El permiso Consultar es obligatorio para realizar acciones en la sección. El servidor rechaza matrices que conceden acciones sin Consultar.
5. Una acción nunca supera el alcance efectivo de consulta de su sección.
6. Una clave desconocida, un permiso sin conceder o un alcance inválido no concede acceso.

Ejemplo: grupo A concede Consultar todas las ventas; grupo B concede Consultar y Editar ventas propias. La combinación permite consultar todas, pero editar únicamente las propias. Si otro grupo concede Editar todas, la edición se amplía a todas.

`resolveAccessGroupPermissions()` obtiene los permisos combinados de grupos activos. `accessPermissionScope()` calcula el alcance efectivo de una acción y `accessPermissionAllows()` comprueba la relación con el vendedor del usuario. Estas funciones se prueban en esta fase; todavía no se llaman desde las acciones del negocio para restringirlas.

## Alcance Propios

Una sección permite Propios únicamente cuando se puede determinar una relación con el vendedor. Cada sección de un grupo tiene un alcance compartido por sus acciones seleccionadas; la combinación entre grupos se resuelve por acción.

La implementación de la fase 4 utilizará estas relaciones:

| Sección | Relación para registros propios |
|---|---|
| Contactos | Clientes asignados al vendedor relacionado con el usuario, y su propio contacto. |
| Cotizaciones y órdenes de venta | Vendedor asignado en la orden. |
| Entregas | Venta de origen y su vendedor; una entrega sin venta relacionada no recibe acceso propio implícito. |
| Facturas de clientes y notas de crédito | Vendedor conservado en la factura/nota. |
| Comisiones y sus pagos | Vendedor de la factura que genera la comisión. |
| Dashboard: resumen, tareas y comisiones | Registros de origen que el usuario tenga permiso de consultar, filtrados por sus relaciones. |

Propios se determina por el vendedor asignado, no por quién creó el registro. Si el usuario no tiene contacto relacionado o el registro no tiene vendedor, no hay acceso por Propios. Crear con alcance propio deberá asignar el vendedor del usuario; no permitirá asignarse registros de otro vendedor.

Las compras, recepciones, internos, bancos y demás secciones sin una relación de vendedor admiten Todos o ningún acceso. No se inventa una regla de propietario que los registros existentes no tengan.

## Grupos iniciales

| Grupo | Definición inicial |
|---|---|
| Administrador | Todos los permisos iniciales. La administración de Ajustes, Empresa, grupos y usuarios permanece protegida. |
| Ventas | Clientes propios, productos en consulta, gestión de ventas propias, generación de entrega/factura y consulta de sus comisiones. No paga comisiones ni valida movimientos. |
| Compras | Proveedores, productos/costos en consulta, gestión de órdenes de compra y generación de recepción/factura. No confirma pagos. |
| Inventario | Gestión de productos y operaciones, existencias, movimientos y configuración de almacenes. No factura ni paga comisiones. |
| Contabilidad | Facturas, cobros, pagos, Tesorería, configuración financiera y gestión de comisiones. |
| Consulta | Lectura operativa de contactos, productos, órdenes e inventario. No trae acceso financiero ni a comisiones por defecto. |

Estos son puntos de partida configurables. Administrador conserva su estado activo y los permisos administrativos mínimos para evitar perder la gestión del sistema. Los demás permisos de ese grupo son editables; no tiene un acceso implícito que ignore casillas quitadas. La protección del último usuario administrador se incorpora en la fase 3.

## Integración pendiente

En la fase 4 se comprobarán las autorizaciones antes de leer datos o ejecutar acciones en el servidor. Además, se ajustarán módulos visibles, botones, enlaces, listados, formularios, selectores relacionados y respuestas automáticas del Dashboard.

Un permiso del Dashboard no autoriza datos del negocio por sí solo. Sus indicadores respetarán también los permisos de las secciones de origen. La generación entre módulos se autoriza por la acción de origen y solo ofrecerá enlaces hacia documentos que el usuario pueda consultar. Las reversiones de comisiones deberán comprobar tanto la autorización del circuito de comisiones como la del egreso correspondiente. Los permisos no eliminan las comprobaciones de estado, cantidades, cobros, saldo de cuenta y versión de los documentos.

## Persistencia y migraciones

`access_permissions` conserva el catálogo técnico, `access_groups` la identidad/estado/versión del grupo y `access_group_permissions` los permisos con su alcance. Los usuarios y sus asignaciones aún no se crean.

El instalador agrega los grupos iniciales que falten y actualiza los textos del catálogo. No restablece grupos modificados o archivados, ni concede nuevos permisos automáticamente a grupos existentes. Nuevas capacidades necesitarán asignación explícita.

Guardar reemplaza la matriz dentro de una transacción junto con los datos del grupo. Un error revierte todo el cambio. La versión evita sobrescribir otra edición y la clave de creación evita duplicados por reenvío. Los grupos se archivan; no existe una acción para eliminarlos desde la interfaz.

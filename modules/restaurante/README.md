# Restaurante — mesas, productos y facturación rápida

## Flujo principal

1. Abrir Restaurante. Si no hay mesas, indicar cuántas se necesitan y pulsar **Crear mis mesas**.
2. Tocar una mesa libre muestra la carta sin crear una cuenta ni ocupar la mesa. El primer producto abre la cuenta a nombre del usuario y guarda el artículo en una sola transacción. Salir sin agregar productos deja la mesa libre. Tocar una ocupada abre su pedido.
3. Agregar productos desde la carta, buscar por nombre o filtrar categorías. Ajustar cantidades con −/+, quitar artículos o añadir observaciones. El pedido se guarda sin salir de la pantalla.
4. Pulsar **Facturar y cobrar**, elegir cliente, medio, caja y ubicación, y confirmar. Se usan los documentos y servicios existentes de ventas, inventario, facturas y pagos. Si un selector tiene una sola opción, ya aparece seleccionada. La caja, medio y ubicación elegidos se recuerdan durante la sesión; el cliente no se cambia automáticamente para otra mesa.

El cierre rápido registra el cobro completo con un medio de pago. La mesa queda pendiente de limpieza y el comprobante se consulta en Facturas, según los permisos del usuario. El botón **Opciones de mesa**, arriba del pedido, reúne el detalle de la atención, traslado, unión, separación, historial y cierre sin consumo. Cada cambio abre un asistente de dos pasos: seleccionar la mesa y registrar el motivo, luego revisar y confirmar. Separar solo aparece cuando hay varias mesas unidas; las acciones respetan los permisos del usuario. El historial se consulta en su propia ventana, sin desplegar formularios debajo de los productos.

Los precios e impuestos aplicados se conservan en el pedido. En este flujo sin envío a cocina, cargar productos guarda un borrador: el punto único de salida de inventario es confirmar la facturación y cobro. La venta, entrega, factura, pago y liberación de mesa se coordinan en una transacción; una falta de existencias revierte todo. Los reintentos devuelven el documento original.

### Categorías y subcategorías de la carta

En **Inventario → Configuración → Categorías**, marcar **Es para restaurante** únicamente en las categorías principales que se quieran usar en la carta. Por ejemplo, marcar `Restaurante` y elegirlo como **Categoría superior** de `Cocina`, `Pollo` o `Bebidas`. Las subcategorías heredan automáticamente esta selección y no necesitan su propia casilla. Nombrar una categoría `Restaurante - Pollo` por sí solo no establece esa relación; debe configurarse su categoría superior.

La primera fila muestra exclusivamente las principales marcadas, incluso si todavía no tienen productos. Al elegir una aparecen sus subcategorías; si tienen más niveles, se despliegan al seleccionarlas. Una categoría incluye sus productos directos y los de todas sus descendientes. **Todos** se limita a estas familias: las categorías principales sin marcar y los productos sin categoría no se ofrecen en Restaurante. El servidor también rechaza agregar productos ajenos a las familias habilitadas. Desmarcar una principal retira su familia de la carta sin borrar pedidos que ya estuvieran guardados.

La instalación agrega `inventory_categories.restaurant_enabled` con valor inicial cero, sin marcar categorías existentes automáticamente. El instalador `php/install-restaurant.php` aplica la ampliación con respaldo previo; no usar el instalador antiguo de Inventario para resembrar una base multiempresa. La casilla también se conserva en la importación/exportación de categorías. Reubicar una categoría como subcategoría elimina su marca propia y pasa a depender de la principal.

La búsqueda por nombre se combina con la categoría elegida. La selección se conserva al cambiar de mesa durante la sesión y dentro de la misma selección de empresas. El filtro no modifica productos ni existencias.

Se conservan como ampliaciones pendientes la cocina y sus comandas, pagos parciales/múltiples y ambas modalidades de división de facturas elegidas por el usuario. Esas opciones no están habilitadas en el cobro rápido de esta entrega. Al incorporar cocina deberá revisarse el punto de confirmación del consumo, sin descontar dos veces.

Permisos para facturar y cobrar desde Restaurante: **Facturar** y **Cobrar** en Cuentas del restaurante. Los permisos se validan en servidor, además de ocultar los botones. Un mesero con Crear/Editar propios puede tomar pedidos sin recibir acceso de cobro.

Prueba del circuito rápido: `php test/restaurant-pos.php`. Cubre precio histórico, reintentos de productos y cobro, borradores sin movimientos, venta/factura/pago únicos, existencias y rollback por falta de stock.

## Base de mesas y cuentas

Disponible: configuración de áreas y mesas, mapa por áreas, estados libre/ocupada/reservada/por limpiar, apertura de cuentas con mesero y comensales, traslado de cuenta, unión de mesas libres, separación, cierre sin consumo e historial con usuario y motivo. El mapa y los formularios se adaptan a dispositivos móviles.

El cierre sin consumo descarta los productos del borrador y cierra la atención en una sola operación, sin generar ventas, cobros ni movimientos de inventario. Conserva los artículos descartados y el motivo en auditoría. Se bloquea si existen ventas, facturas, pedidos confirmados o pagos relacionados. Unir mesas libres no fusiona cuentas ocupadas.

## Instalación

Requiere la aplicación existente y su esquema multiempresa, PHP con PDO MySQL, BCMath, DOM y mbstring, y MariaDB con permisos para crear tablas y triggers. Ejecutar la migración con la aplicación en mantenimiento, sin escrituras concurrentes. No usar los instaladores antiguos para resembrar datos.

```powershell
& C:\xampp\php\php.exe php/install-restaurant.php
```

El instalador crea primero un respaldo completo en `php/backups/before-restaurant-*.sql`, incluido el esquema, datos y triggers. Si el respaldo falla, no inicia la migración. La ruta del ejecutable `mysqldump` sigue la distribución XAMPP actual. No se imprimen las credenciales. En otro sistema operativo debe ajustarse la ubicación de ese ejecutable antes de instalar.

La instalación es repetible y no inserta áreas, mesas, cuentas ni usuarios de ejemplo. Registra las tablas empresariales y sus restricciones de aislamiento, incluidos el pedido editable y su enlace a venta/factura. Agrega los permisos nuevos al grupo de sistema Administrador cuando se instalan por primera vez; repetirla no restaura permisos que se hayan retirado intencionadamente.

La configuración detallada de áreas y mesas sigue disponible para distribuirlas por salones o terrazas. Las columnas 1–6 y filas 1–20 indican la distribución del mapa en escritorio; en pantallas pequeñas las mesas se reorganizan en tarjetas legibles. No se permite superponer mesas activas en una posición.

## Permisos

Los permisos se gestionan en Ajustes → Grupos de acceso:

- Mapa y estado de mesas: Consultar; Editar permite marcar reserva, limpieza o disponibilidad sin solicitar motivo. El cambio conserva su registro de auditoría.
- Cuentas del restaurante: Consultar, Crear, Editar y Cancelar. Cancelar autoriza el cierre de una cuenta sin consumo. Editar permite trasladar, unir mesas libres y separarlas.
- Áreas y mesas del restaurante: Consultar y Configurar.

Para un mesero, asignar Consultar mapa y Consultar/Crear/Editar cuentas con alcance **Propios**. No necesita asociarse a un contacto vendedor: la propiedad se basa en el usuario responsable de la cuenta. El permiso de Crear con alcance Propios solo permite asignarse a sí mismo. Un administrador puede asignar otro usuario activo con acceso a la empresa.

El listado y mapa muestran la empresa principal. Las fichas, permisos, firma de contexto y relaciones respetan el mecanismo multiempresa existente. Las cuentas ajenas no se consultan mediante enlaces directos cuando el alcance es Propios.

## Integridad y funcionamiento

La ocupación activa usa una clave primaria por mesa. La apertura se realiza bajo bloqueo de área y mesa, comprobando su versión y disponibilidad; las solicitudes repetidas conservan su resultado mediante una clave de 64 caracteres y un hash de los datos. Una clave reutilizada con otro contenido devuelve conflicto.

Las acciones sobre cuentas bloquean la cuenta y las áreas/mesas en orden, comprueban versión y permiso y guardan el historial en la misma transacción. Una mesa ocupada no se edita ni desactiva. No se desactiva un área con cuentas activas. Al trasladar, separar o cerrar, las mesas liberadas quedan por limpiar; deben marcarse libres antes de otra apertura.

La gestión de mesas por sí sola no modifica precios, inventario, facturas ni pagos. Facturar sí registra los documentos y movimientos indicados en el flujo principal. No se muestran importes ficticios. Las observaciones son texto escapado al renderizar.

## Pruebas

```powershell
& C:\xampp\php\php.exe test/restaurant.php
& C:\xampp\php\php.exe test/role-workflows.php
& C:\xampp\php\php.exe test/multi-company.php
```

Las pruebas usan una base y cuentas temporales. La prueba del restaurante cubre migración repetida, validación y versiones, estados y capacidad, apertura concurrente con claves distintas, reintento concurrente con la misma clave, permisos propios sin contacto vendedor, acceso HTTP, CSRF y contexto de empresa obsoleto.

Para revisar también escritorio y móvil, iniciar Chrome con un perfil temporal y puerto de depuración 9337 y ejecutar:

```powershell
& C:\xampp\php\php.exe test/restaurant.php "$env:TEMP/nexo-restaurant-review/screenshots"
```

`test/restaurant-ui.js` utiliza las vistas reales generadas por HTTP en la base temporal, con recursos locales incrustados. Comprueba filtros, controles y ausencia de desbordamiento horizontal a 1440 y 390 píxeles, y guarda capturas. `NEXO_CDP_PORT` permite elegir otro puerto. Eliminar el perfil temporal al terminar su uso no afecta perfiles habituales del navegador.

## Recuperación

MariaDB no revierte DDL mediante una transacción. Ante un fallo de instalación, mantener la aplicación en mantenimiento, conservar el respaldo y corregir la causa antes de repetir la migración. Ensayar restauraciones en una base separada y verificar las tablas, triggers y totales antes de sustituir la base operativa.

Solo una instalación completamente vacía admite:

```powershell
& C:\xampp\php\php.exe php/install-restaurant.php --rollback-empty
```

Ese comando respalda primero y comprueba todas las tablas antes de retirarlas. Si existe cualquier registro, se niega a eliminarlo. Conserva los permisos de grupos; retirar código o permisos se administra por separado. No es un procedimiento para eliminar un restaurante que ya ha operado.

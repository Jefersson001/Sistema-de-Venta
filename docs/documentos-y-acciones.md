# Documentos imprimibles y acciones comunes

## Uso

En la ficha de una cotización, orden, movimiento de inventario, factura o nota de crédito, abrir **Opciones → Imprimir / Guardar PDF**. La vista previa utiliza los datos guardados; para incluir cambios de la ficha, guardarlos primero. El botón abre la impresión del navegador, donde se puede elegir una impresora o Guardar como PDF.

Hay dos variantes de un diseño común de Nexo:

- **Comercial:** cotizaciones y órdenes de venta/compra, facturas de cliente/proveedor y notas de crédito. Muestra empresa y logo, contraparte, referencia, fecha, condiciones, líneas, impuestos, moneda y totales.
- **Inventario:** entregas, recepciones y traslados internos. Muestra origen, destino, cantidades solicitadas y por realizar/realizadas, observaciones y firmas. No imprime costos ni comisiones.

Los títulos cambian según la operación y el estado. Los borradores y documentos cancelados se identifican. Las tablas imprimen encabezados en cada página. No se cargan fuentes, logos o bibliotecas desde servicios externos.

La aplicación actualmente admite facturas y notas de crédito. Las notas de débito requieren incorporar su operación al sistema; no se presenta una factura como si fuera una nota de débito.

## Eliminar, duplicar y navegar

El menú común de Importar/Exportar incorpora las acciones mediante el registro central de modelos; no hay que agregar un botón a cada formulario.

- **Eliminar en formulario:** abrir Opciones, elegir Eliminar y revisar la confirmación.
- **Eliminar en listado:** marcar las casillas de la página y elegir Opciones → Eliminar. La selección se confirma antes de procesarla. Hasta 100 registros por acción.
- **Duplicar:** desde una ficha guardada, Opciones → Duplicar. La nueva copia queda disponible para revisar. No confirma documentos, cobra ni mueve existencias.
- **Anterior/Siguiente:** navegan por el mismo tipo de registro, respetando empresas, permisos y filtros presentes en la URL. Los documentos se ordenan por correlación de ID descendente; los catálogos, por nombre o código.

Se conserva el historial: documentos confirmados, movimientos realizados, cobros y registros con dependencias no se borran. Las claves foráneas protegen también las relaciones que agreguen otros módulos. Un fallo revierte toda la selección. Usuarios se archivan; empresas usan su procedimiento específico de eliminación. Los registros base del sistema, datos calculados y configuraciones únicas muestran la acción deshabilitada. Las monedas principales, alternativas o utilizadas se conservan.

Las copias tienen otro correlativo y comienzan en borrador. Las operaciones de inventario conservan la demanda y reinician las cantidades por realizar en cero; no heredan órdenes, reservas, devoluciones ni pendientes. Una entrega con alcance «Propios» se genera desde la orden de venta para conservar la asignación del vendedor.

Una factura duplicada queda como borrador **sin orden vinculada**, sin cobros y sin volver a facturar las líneas de la orden original. Hay que revisar sus datos antes de registrarla. Las notas de crédito se generan desde su factura original, respetando el saldo disponible; no se duplican directamente. Las copias de contactos no conservan el documento de identidad ni la ubicación externa; las mesas se copian inactivas para elegir una posición libre antes de activarlas.

## Permisos, transacciones y auditoría

Eliminar usa un permiso específico por recurso operativo, visible en Grupos de acceso. En catálogos de configuración se utiliza Configurar. Duplicar utiliza Crear; para facturas se requieren Duplicar factura en borrador y Editar. Consultar sigue siendo obligatorio.

El instalador habilita únicamente los permisos recién incorporados para el grupo Administrador del sistema. No modifica otros grupos ni restaura permisos retirados en una ejecución posterior. Los permisos y el estado se vuelven a comprobar en el servidor al ejecutar.

La confirmación vence a los 30 minutos y conserva una huella de la ficha y sus líneas. Si cambian, se exige revisar otra vez. Las acciones se ejecutan en una transacción y se registran en `business_record_actions` con empresa, usuario, fecha, modelo, acción, referencias y resultado. La clave de solicitud conserva el resultado ante reintentos y evita repetir una copia o un borrado.

## Instalación y recuperación

La instalación local ya está actualizada. En otro XAMPP, copiar el código y ejecutar desde la raíz:

```powershell
C:\xampp\php\php.exe php/install-record-actions.php
```

El instalador crea primero un respaldo completo en `php/backups/before-review-fixes-*.sql`. Si el respaldo falla, se detiene. Agrega la tabla de auditoría y actualiza el catálogo de permisos; se puede ejecutar nuevamente. Para una reversión de código se puede conservar esta tabla. Para revertir datos, detener escrituras y restaurar el respaldo correspondiente junto con el código de esa versión; eliminar la tabla perdería la auditoría.

## Pruebas

```powershell
C:\xampp\php\php.exe test/record-actions.php
C:\xampp\php\php.exe test/transfer.php
```

Usan bases temporales y verifican formatos, permisos, escape de textos, navegación, selección múltiple, copias en borrador, reintentos, dependencias, conflictos, reversión completa y separación de empresas. La prueba visual `test/document-print-ui.js` verifica escritorio y móvil, la impresión y PDF A4 de varias páginas, usando ejemplos aislados generados con `test/record-actions.php <carpeta-temporal>` y un navegador de prueba CDP en el puerto 9343.

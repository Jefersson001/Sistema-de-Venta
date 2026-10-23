# Opciones de modelos: plantillas, importación y exportación

Cada vista de lista y formulario de un modelo registrado recibe el menú **⚙ Opciones** desde el renderizador común. No se agregan botones a las plantillas de cada módulo. El menú ofrece Descargar plantilla, Importar y Exportar según los permisos y la capacidad del modelo. Una ficha nueva sin guardar no se exporta.

## Uso

Las plantillas son CSV UTF-8 con encabezados estables. Excel puede abrirlas y guardarlas como CSV UTF-8. Se aceptan coma y punto y coma como separadores. La pantalla de importación describe los campos, valores de las selecciones, fechas, referencias y campos obligatorios. Las referencias aceptan un ID o un nombre/código único dentro del contexto permitido; un nombre ambiguo exige el ID.

La importación admite hasta 4 MB y 2000 filas. Un ID vacío crea un registro; un ID existente actualiza un registro de la empresa principal para el cual el usuario tiene permisos. La columna de versión, si existe, permite detectar archivos desactualizados. Las columnas de empresa, autoría, fechas de auditoría, estados y totales son de solo lectura. La vista previa identifica las columnas que se conservarán o calcularán automáticamente.

Se ejecutan las validaciones y escrituras dentro de una transacción que se revierte para producir la vista previa. La confirmación repite la operación en una única transacción. Los repositorios que utilizan sus propias transacciones se contienen mediante SAVEPOINT, de modo que un commit de un documento no confirme parcialmente el lote. Un error revierte todo. La vista previa vence en 15 minutos, queda vinculada al usuario y a la selección de empresas, y su token se consume al confirmar para impedir duplicados por reenvío. Los registros existentes y sus detalles se comparan con la versión previa para no sobrescribir cambios concurrentes.

Los documentos con detalle utilizan una fila por línea. Las cabeceras deben coincidir y `document_key` agrupa las filas de un documento nuevo. En registros existentes, el ID agrupa las líneas. Las columnas `line.` corresponden al detalle. Ventas, compras, operaciones de inventario, pagos y documentos de tesorería se crean en borrador. Las facturas se generan a partir de órdenes confirmadas con cantidades facturables; no se importan totales calculados ni saldos directamente. La importación de pagos de comisiones es una operación confirmada de egreso y se identifica expresamente en la pantalla; requiere los permisos de pago de comisiones y creación/confirmación de egresos, y valida disponibilidad y saldo.

Productos incorpora precios de proveedor; Monedas incorpora sus tasas. El archivo que contiene detalle de órdenes, operaciones o precios reemplaza el detalle completo editable. Las tasas de monedas conservan el historial y actualizan las coincidencias por moneda base y fecha. Los usuarios se crean con contraseña temporal y las asignaciones de grupos y empresas pasan por la administración de usuarios existente. Las importaciones de usuarios, grupos y empresas requieren Administrador.

La exportación permite elegir columnas y utiliza las empresas seleccionadas, el registro de la ficha si corresponde y los filtros de búsqueda/estado disponibles del modelo. Exporta registros individuales en todas las páginas; no exporta los pivotes ni las agrupaciones visuales. Un documento se expande a sus líneas solamente si se elige alguna columna `line.`. Los informes calculados, comisiones y existencias permiten exportación y no admiten escritura directa. Límites: 20000 registros y 100000 líneas; se solicita reducir filtros al superarlos. Se protegen las celdas de texto que Excel podría interpretar como fórmulas anteponiendo un apóstrofo. Las contraseñas y hashes nunca se exportan; los costos requieren su permiso específico.

## Estructura común

- `php/transfer-models.php`: registro de modelos existentes, rutas, permisos, campos editables, detalles y adaptadores.
- `php/config/transfer-models.php`: registro adicional para modelos nuevos.
- `php/transfer-fields.php`: metadatos de columnas y relaciones, etiquetas, tipos y normalización.
- `php/transfer-adapters.php`: guardado mediante los validadores y repositorios de negocio.
- `php/transfer-service.php`: CSV, permisos, agrupación, concurrencia, importación y exportación.
- `php/transfer-transactions.php`: transacciones anidadas de los lotes.
- `php/transfer-view.php`: menú automático dentro del renderizador de vistas.
- `datos.php`: controlador HTTP común; `html/transfer/page.php`: interfaz común.

Una tabla nueva necesita primero su migración de esquema y `registerCompanyTable()` si sus datos pertenecen a una empresa. Después se registra su modelo una vez, por ejemplo en `php/config/transfer-models.php`:

```php
return [
    'brands' => [
        'table' => 'inventory_brands',
        'label' => 'Marcas',
        'resource' => 'inventario.configuration',
        'routes' => ['inventario/marcas.php'],
        'save' => 'master',
        'write' => ['name', 'active'],
        'defaults' => ['active' => '1'],
        'private' => [],
    ],
];
```

Las vistas deben usar `renderModulePage()` para obtener el menú común. Para un modelo simple, `master` aplica tipos, relaciones y restricciones del esquema. Un modelo con reglas de negocio debe declarar un adaptador `save` callable que reciba `(PDO $db, array $input, ?array $old, string $creationKey): int`, invoque sus validadores/repositorios y devuelva el ID. No debe enviar mensajes externos, ejecutar DDL ni efectuar escrituras mediante otra conexión, pues la vista previa necesita revertir todos sus efectos. El modelo puede definir `line_table`, `line_fk`, `line_write`, `labels`, campos `synthetic`, un dominio fijo y campos privados. Los permisos del recurso se registran en el catálogo de permisos habitual.

No se necesitan cambios en el HTML de sus vistas ni un nuevo endpoint de importación/exportación.

## Validación

`php test/transfer.php` ejecuta solicitudes HTTP y cargas multipart en una base temporal: menús de listas/formularios, plantillas, campos privados, errores por fila, vista previa sin guardar, confirmación, reenvío, exportación y fórmulas, actualizaciones concurrentes, documentos con líneas, rollback tras commits anidados, pagos en borrador, importación de usuarios/empresas y cambio de contexto. `php test/role-workflows.php` verifica los flujos por roles existentes; `php test/multi-company.php` verifica el aislamiento de empresas.

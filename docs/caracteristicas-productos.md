# Características de productos

En **Inventario → Productos**, la pestaña **Características** incorpora los siguientes campos opcionales tanto para productos como para variantes:

| Campo | Regla |
|---|---|
| Código de barras | Texto de hasta 100 caracteres ASCII, sin espacios. Conserva ceros iniciales y distingue mayúsculas de minúsculas. Debe ser único entre productos y variantes de una misma empresa. |
| Color | Texto de hasta 80 caracteres. |
| Peso | Kilogramos por unidad, no negativo, hasta dos decimales. |
| Ancho, alto y profundidad/fondo | Centímetros por unidad, no negativos, hasta dos decimales. |

Un campo vacío significa que no aplica o no se conoce; no se convierte automáticamente en cero. Cero puede registrarse expresamente. Los campos físicos también son opcionales para servicios. Se admite coma o punto como separador decimal, sin separadores de miles.

Al crear una variante, se proponen el color, peso y dimensiones del principal. El código de barras comienza vacío, porque identifica un producto concreto. Los cambios posteriores del principal no actualizan variantes existentes. Cada variante puede editar sus características desde la misma ficha, con los permisos habituales de Productos. El formulario detecta cambios concurrentes de estos campos y evita sobrescribirlos con una ficha antigua.

Los listados de Productos y Variantes permiten buscar por código de barras. Los campos también están disponibles en el menú Opciones para importar y exportar CSV. Una actualización que omite estos campos conserva sus valores; una celda vacía en una columna incluida los borra. Un archivo con códigos repetidos se rechaza sin dejar productos parcialmente guardados. Al abrir un CSV en una hoja de cálculo, seleccionar formato Texto para la columna del código de barras, para que esa aplicación conserve sus ceros iniciales.

Esta entrega incorpora los datos básicos. La definición de atributos por categoría queda para una etapa posterior. El seguimiento por lotes, series y vencimientos se describe en [Lotes, series y vencimientos](lotes-series-vencimientos.md). El código de barras se guarda como texto; todavía no se incorpora impresión de etiquetas ni lectura automática en el punto de venta.

## Instalación

Ejecutar `php php/install-product-details.php` con PHP CLI. Primero genera un respaldo privado completo en `php/backups/before-product-details-*.sql`. Si el respaldo falla, no modifica el esquema. La migración es repetible y agrega columnas opcionales, restricciones no negativas y un índice único por empresa y código de barras. Los productos existentes conservan sus datos y sus existencias, con las características nuevas vacías.

## Validación

`php test/product-details.php` comprueba la migración repetible, guardado HTTP, valores opcionales, ceros iniciales, duplicados, herencia de variantes, ediciones concurrentes, permisos de consulta, aislamiento por empresa, importación/exportación y rechazo atómico de archivos inválidos. Incluye una prueba de navegador en escritorio y móvil, con guardado y recarga. Las pruebas utilizan bases de datos y usuarios temporales.

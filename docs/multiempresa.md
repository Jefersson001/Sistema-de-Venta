# Multiempresa

Los datos de negocio pertenecen a una empresa. Los productos y contactos son independientes. Las identidades de usuario, grupos, permisos, países, estados, ciudades, tipos de contacto y tipos de documento son compartidos. Los catálogos compartidos y sus relaciones incluyen autoría y fechas; las tablas internas de autenticación y de versión del esquema no representan registros del negocio.

## Uso

El selector del encabezado muestra primero y resalta la empresa principal. Pulsar el nombre de otra empresa la convierte en principal; sus casillas añaden o quitan empresas para consulta, conservando siempre al menos una seleccionada. Los cambios se aplican automáticamente. Si se desmarca la principal, otra seleccionada toma su lugar. Los listados y reportes combinan las empresas seleccionadas; las creaciones y modificaciones corresponden a la principal. Para editar una ficha de otra empresa seleccionada, primero se pulsa su nombre en el selector. Los listados principales identifican la empresa de cada fila y las fichas muestran empresa y última autoría.

El dashboard conserva su actualización conjunta de 30 segundos. Los listados de negocio consultan cambios cada 30 segundos cuando están visibles y no hay un filtro o diálogo en uso; si cambian los datos, recargan conservando el desplazamiento. Los informes de inventario y flujo de caja se actualizan cada 30 segundos, incluidos sus gráficos. La actualización se reintenta al recuperar la visibilidad. No es una conexión WebSocket ni un historial de cada cambio.

Ajustes → Usuarios → ficha del usuario → Empresas permite marcar las empresas autorizadas, junto a los permisos de módulos. Solo un Administrador puede modificar esta asignación; se requiere al menos una empresa para un usuario sin grupo Administrador. Un Administrador tiene acceso a todas las empresas. Un usuario nuevo recibe por defecto la empresa principal de quien lo crea. Ajustes → Empresa también permite administrar los usuarios autorizados; ambas pantallas usan la misma asignación. Cambiar los accesos invalida la sesión del usuario y las versiones de los formularios relacionados. Se conservan las relaciones de vendedor de las empresas que siguen autorizadas; se puede asociar un vendedor diferente por empresa desde la ficha de Empresa. Los permisos de los grupos siguen aplicándose dentro de esas empresas. La relación anterior de usuario con contacto sigue funcionando para la empresa de ese contacto.

La eliminación muestra el número de registros relacionados y exige escribir el nombre exacto. Vuelve a comprobar la versión, los recuentos y el permiso de administrador. Conserva al menos una empresa. Desvincula los contactos de las identidades compartidas y elimina los datos empresariales en una transacción, con las claves foráneas activas. Cualquier fallo revierte toda la eliminación. Los usuarios compartidos sobreviven. Los archivos de respaldo no se eliminan con una empresa.

## Instalación y migración

Ejecutar desde la raíz, con los módulos ya instalados:

```powershell
php php/install-multi-company.php
```

El instalador genera primero un respaldo SQL completo con `mysqldump`, incluidos datos, estructura y triggers, en `php/backups/`, directorio bloqueado para HTTP y excluido de Git. Si el respaldo falla, la migración no empieza. MariaDB no permite revertir DDL mediante una transacción: la migración es repetible para retomar un fallo, y el respaldo sirve para restaurar el estado anterior.

Los registros y permisos existentes se asignan a la empresa 1. No se inventan creadores ni fechas históricas: los campos nuevos quedan NULL para los registros anteriores. Se mantienen las fechas que ya estaban guardadas. Las empresas nuevas reciben monedas USD/VES, una caja, medios y conceptos de pago, plazo Contado y ubicaciones externas; no copian contactos, productos, saldos ni operaciones. La tasa de cambio debe configurarse en cada empresa.

## Base para tablas futuras

Después de crear una tabla de negocio con sus columnas y relaciones, su migración debe llamar:

```php
require_once __DIR__.'/company-schema.php';
registerCompanyTable($db, 'nombre_de_tabla');
```

La función agrega `company_id`, `created_by`, `created_at`, `updated_by`, `updated_at`, claves foráneas e índice de empresa; registra la tabla para el aislamiento y crea los triggers. Ajusta los índices únicos de negocio para incluir empresa, conservando los IDs globalmente únicos y los índices necesarios para claves foráneas. Las relaciones con otras tablas empresariales se validan para que pertenezcan a la misma empresa. Cada migración que agregue relaciones debe volver a registrar la tabla. Los nombres de tabla y columna deben ser identificadores simples.

Las consultas HTTP usan `CompanyConnection`, que aplica el filtro a cada tabla empresarial en lecturas, JOIN y subconsultas, y restringe UPDATE/DELETE a la empresa activa. La tokenización conserva literales y parámetros; rechaza SQL compuesto, comentarios, JOIN implícitos con coma, tablas no registradas y escrituras de formas no admitidas. Los SELECT de bloqueo bloquean también las tablas físicas. Los triggers asignan empresa y autoría y rechazan el cambio de propietario y relaciones entre empresas. Las tasas usadas para convertir operaciones pertenecen a la empresa activa.

Las migraciones CLI usan un PDO de mantenimiento sin filtro. Es una interfaz de confianza para administradores del código, no un control de seguridad frente a alguien con acceso SQL root. Después de migrar, las operaciones de mantenimiento de datos deben recorrer las empresas y establecer `@nexo_company_id`; no deben ejecutar actualizaciones empresariales indiscriminadas. Los instaladores anteriores a multiempresa no deben usarse para resembrar una base con varias empresas: usar migraciones de esquema explícitas y `registerCompanyTable`.

Los formularios incluyen una firma de la selección y la empresa activa. Un formulario abierto antes de cambiar de contexto no se puede guardar. Las consultas fetch envían la misma firma y descartan respuestas de otra selección. Las pestañas del navegador se sincronizan al cambiar de empresa.

## Validación

`php test/multi-company.php` usa una base temporal y prueba migración repetible, aislamiento de fichas y subconsultas, consulta conjunta, autoría, escrituras ajenas, relaciones cruzadas, bloqueo concurrente, selector HTTP, contexto obsoleto y eliminación de una empresa sin afectar otra. `php test/role-workflows.php` recorre ventas, compras, inventario, facturación, cobros y comisiones con usuarios de distintos grupos.

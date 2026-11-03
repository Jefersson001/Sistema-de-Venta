# Actualizaciones de código y base de datos

Dokploy compila el código y, al arrancar cada contenedor, `scripts/start-dokploy.sh` ejecuta `php php/migrate.php` antes de iniciar PHP-FPM y Nginx. `nixpacks.toml` configura ese comando de arranque conservando el proveedor PHP de Nixpacks. Las migraciones no se ejecutan al construir la imagen ni en cada petición web.

## Configuración inicial

La base debe estar instalada. En Environment de **Ventas-app**, conservar `DATABASE_URL`, `NEXO_BASE_PATH=/` y `PORT=3000`, y agregar:

```dotenv
MIGRATION_DATABASE_URL=mysql://root:CLAVE_ROOT_CODIFICADA@HOST_INTERNO:3306/data
AUTO_MIGRATE=1
```

Usar el mismo host interno, puerto y nombre de base de la conexión de la aplicación. La contraseña corresponde a **Root Password** del servicio de base de datos, codificada como componente de URL si tiene caracteres especiales. No publicar esa URL ni guardarla en el repositorio. También puede utilizarse una cuenta de instalación con los privilegios necesarios; MySQL con binary logging exige permisos adicionales para crear triggers.

`DATABASE_URL` conserva `data_user` para las operaciones de la aplicación. `MIGRATION_DATABASE_URL` se utiliza únicamente desde el proceso CLI de actualización. Si no se configura, el comando utiliza las credenciales de la aplicación y puede fallar por falta de permisos. El comando rechaza una conexión de migración cuyo host, puerto o base sean distintos a los de la aplicación.

Subir los cambios y desplegar. En los logs aparecerán `Aplicando: ...` y `Migraciones aplicadas: ...`, o `Base de datos al día`. Si está configurado `NIXPACKS_START_CMD` o un comando de arranque manual que reemplace al del repositorio, retirarlo o ajustarlo a `sh /app/scripts/start-dokploy.sh`.

## Desarrollo de módulos y cambios de estructura

Cada cambio de tablas, columnas, índices, triggers o catálogos requerido por una función debe incluir una migración nueva junto con el código:

```powershell
php php/migrate.php --make=agregar_observaciones_contactos
```

El comando crea una plantilla con fecha UTC en `php/migrations/versioned/`. Completarla antes de desplegar. Por ejemplo:

```php
<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if (!$db->query("SHOW COLUMNS FROM contacts LIKE 'observaciones_extra'")->fetch()) {
        $db->exec('ALTER TABLE contacts ADD observaciones_extra VARCHAR(500) NULL');
    }
};
```

Al crear una tabla empresarial, declarar sus relaciones y llamar a `registerCompanyTable($db, 'nombre_tabla')` para instalar el aislamiento multiempresa y los triggers de auditoría. El CLI carga los helpers de empresa y `workflowColumn()`. Las migraciones de configuración deben considerar todas las empresas, establecer su contexto explícitamente y evitar duplicados.

Probar en una base aislada con registros previos y subir el archivo junto al cambio de código. El siguiente despliegue lo aplicará automáticamente. Una modificación hecha solamente desde phpMyAdmin o DBeaver local no forma parte de Git y no se transmite al VPS: debe expresarse en una migración.

Los archivos SQL antiguos en `php/migrations/` pertenecen a la instalación inicial. No se vuelven a ejecutar al desplegar. Las actualizaciones nuevas utilizan exclusivamente `php/migrations/versioned/`.

## Registro y reintentos

`application_migrations` conserva nombre, checksum, estado y fechas. El checksum normaliza los saltos de línea de Windows/Linux para mantener el mismo historial en ambos entornos. Cada archivo se aplica una vez. Los estados posibles son `running`, `failed` y `applied`. Las réplicas y despliegues concurrentes comparten un bloqueo de MySQL con el instalador inicial para impedir ejecuciones simultáneas.

No editar ni eliminar un archivo aplicado: el comando detecta alteraciones del historial y detiene el arranque. Para cambiar algo ya instalado, crear otra migración. Una migración fallida puede corregirse considerando qué operaciones llegaron a completarse.

MySQL confirma DDL de forma implícita. Por eso cada migración debe tolerar reintentos después de una ejecución parcial y cerrar las transacciones que abra. El comando registra el fallo, detiene las migraciones siguientes y evita iniciar el servidor del contenedor nuevo. No revierte automáticamente los cambios de estructura anteriores al error. No utilizar `--force` ni ignorar errores de migración para arrancar código incompatible.

En un despliegue gradual puede seguir sirviendo una versión anterior mientras se migra la base compartida. Preferir cambios aditivos compatibles con ambas versiones. Separar la eliminación de columnas o tablas del despliegue que deja de utilizarlas y conservar un respaldo antes de cambios de estructura importantes. El proceso de migración no sustituye los respaldos ni copia datos de XAMPP a producción.

Consultar el estado desde la terminal del contenedor:

```sh
php /app/php/migrate.php --status
```

Para aplicar pendientes manualmente después de corregir una causa:

```sh
php /app/php/migrate.php
```

## Instalación inicial y mantenimiento

El instalador `php/install-fresh.php` aplica el esquema inicial y luego las migraciones versionadas antes de generar el enlace del primer administrador. No usarlo como sustituto del comando de actualización.

`AUTO_MIGRATE=0` permite desactivar temporalmente la ejecución automática, por ejemplo mientras se prepara una base nueva. Su valor por defecto es `1`. No usarlo para ocultar una migración pendiente de la que dependa el código desplegado. Volver a `1` cuando la base esté preparada.

Pruebas del mecanismo:

```sh
php test/migrations.php
node test/deploy-startup.js
php test/fresh-installation.php
```

Referencias: [configuración de Nixpacks](https://nixpacks.com/docs/configuration/file), [arranque PHP de Nixpacks 1.41](https://github.com/railwayapp/nixpacks/blob/v1.41.0/src/providers/php/mod.rs).

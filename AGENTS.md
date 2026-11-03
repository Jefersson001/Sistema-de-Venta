# Cambios de base de datos

Cuando una tarea cambie tablas, columnas, índices, triggers o catálogos necesarios para una función, incluir una migración nueva en `php/migrations/versioned/` junto con el código. No depender de cambios manuales en la base local ni de volver a ejecutar los instaladores antiguos.

- Crear la plantilla con `php php/migrate.php --make=nombre_del_cambio` y completarla antes de desplegar.
- Conservar los archivos aplicados sin editarlos o eliminarlos; corregir cambios anteriores mediante una migración nueva.
- Escribir cambios idempotentes: MySQL puede confirmar DDL antes de que falle una etapa. Un reintento debe conservar datos y evitar duplicados.
- Para tablas empresariales nuevas, usar la infraestructura multiempresa y `registerCompanyTable()`. Sembrar configuraciones con el contexto de empresa explícito.
- Mantener cambios compatibles con la versión anterior mientras se reemplazan los contenedores. Separar agregados de columnas/tablas de las retiradas destructivas.
- Probar la migración sobre una base aislada, con datos previos y ejecución repetida.

Las migraciones se ejecutan al arrancar el contenedor en Dokploy mediante `scripts/start-dokploy.sh`. `php/install-fresh.php` también las aplica tras instalar el esquema inicial. Los cambios ordinarios no deben importar respaldos ni reconstruir la base.

Comprobaciones del mecanismo: `php test/migrations.php`, `node test/deploy-startup.js`; si se cambia el instalador inicial, ejecutar además `php test/fresh-installation.php`.

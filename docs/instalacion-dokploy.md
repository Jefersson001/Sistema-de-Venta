# Instalación nueva en Dokploy

El instalador `php/install-fresh.php` prepara los módulos en una base vacía y genera un enlace privado para registrar el primer administrador. No importa datos de XAMPP ni crea usuarios o contraseñas de ejemplo. Necesita PHP 8.3 o posterior y las extensiones declaradas en `composer.json`.

## Configuración de la aplicación

En Environment de la aplicación, configurar:

```dotenv
DATABASE_URL=mysql://data_user:CONTRASENA_CODIFICADA@HOST_INTERNO:3306/data
NEXO_BASE_PATH=/
PORT=3000
```

Utilizar la URL interna del servicio de MySQL. Los caracteres especiales de usuario y contraseña deben estar codificados como componentes de URL. El dominio debe apuntar al puerto 3000. Guardar y desplegar el código antes de instalar.

## Instalación

Abrir **Ventas-app → General → Open Terminal**. Ejecutar en el contenedor de la aplicación:

```sh
php /app/php/install-fresh.php --url=https://somosamdelicias.tech --db-user=root
```

La contraseña solicitada es **Root Password** del servicio MySQL. No se muestra al escribirla, no se pasa como argumento y no se guarda en la configuración de la aplicación. `DATABASE_URL` sigue utilizando `data_user` durante el funcionamiento normal. MySQL con binary logging puede requerir privilegios administrativos para crear los triggers; no hace falta modificar `log_bin_trust_function_creators`.

Si la base conserva únicamente `access_groups` de una importación fallida, el comando se niega a continuar por defecto. Para comenzar desde cero en ese caso concreto:

```sh
php /app/php/install-fresh.php --url=https://somosamdelicias.tech --db-user=root --discard-partial-import
```

Esta opción conserva primero la estructura y las filas de esa tabla en un archivo privado del directorio temporal del contenedor e imprime su ruta. Solo admite esa única tabla y sin triggers; cualquier otra instalación existente se rechaza. Conservar la copia hasta terminar. No usar esta opción para una base con operaciones o usuarios reales.

Al terminar, abrir el enlace de activación impreso en la terminal y elegir nombre, usuario y contraseña del administrador. El enlace vence en 30 minutos; es privado y no debe publicarse. El usuario administrador puede acceder a la empresa inicial y configurar el negocio desde Ajustes. No se generan productos, contactos, áreas, mesas, existencias, facturas, pagos ni tasas de cambio ficticias.

## Reanudación y límites

La instalación registra las etapas completadas y utiliza un bloqueo para evitar ejecuciones simultáneas. Si falla una etapa, corregir la causa y ejecutar el mismo comando para reanudar. Las operaciones DDL de MySQL/MariaDB no se revierten como una transacción; no debe interpretarse un fallo como una base intacta. Evitar escrituras de la aplicación mientras se completa la instalación.

Repetir el comando tras finalizar conserva datos, configuración y cuentas. Si aún no se creó el administrador, genera un enlace nuevo que invalida el anterior. Si ya hay usuarios, muestra el acceso al login. Este comando no es un actualizador general para bases anteriores ni ejecuta migraciones nuevas sobre una instalación terminada.

Si `root` no admite conexión TCP desde el contenedor de la aplicación, usar una cuenta de instalación con los privilegios necesarios sobre la base y los requeridos por MySQL para crear triggers. No cambiar la contraseña de `data_user` para resolver un error de permisos administrativos.

## Validación

```sh
php test/fresh-installation.php
```

La prueba utiliza una base temporal y verifica la conservación de tablas ajenas, copia de la importación parcial, reanudación, creación de los módulos, ausencia de operaciones ficticias, activación del administrador, login y ejecución repetida sin sobrescribir datos.

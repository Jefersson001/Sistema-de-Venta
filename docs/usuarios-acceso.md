# Usuarios y acceso — fase 3

La fase 3 incorpora autenticación y gestión de usuarios internos. La fase 4 aplica los permisos por sección, acción y alcance: los usuarios de grupos personalizados pueden utilizar sus módulos autorizados sin pertenecer a Administrador. La estructura [multiempresa](multiempresa.md) añade empresas autorizadas y relaciones de vendedor por empresa, conservando las identidades y grupos compartidos. Para validar los permisos operativos, consultar [permisos-operativos.md](permisos-operativos.md).

## Activar la primera cuenta

La instalación local de las tablas ya está aplicada. En otra instalación, ejecutar primero `php/install-settings.php`. Estos instaladores conservan las cuentas, contraseñas, grupos y datos existentes al repetirse.

Desde `C:\xampp\htdocs\project`, ejecutar:

```powershell
& C:\xampp\php\php.exe php/prepare-admin.php
```

Abrir el enlace que muestra esa terminal en el navegador del negocio. Vence en 30 minutos y permite crear una sola cuenta: elegir nombre, usuario y contraseña propia. El primer usuario queda activo y pertenece al grupo Administrador. Generar otro enlace antes de completar la activación invalida el anterior. Después de crear la cuenta, la activación queda cerrada incluso si se vuelve a ejecutar el instalador.

El enlace contiene un secreto de activación; no incluirlo en capturas ni commits. Al abrirlo, se retira el secreto de la dirección del formulario. No existen contraseñas predeterminadas ni un formulario público para registrar más cuentas.

## Crear y gestionar usuarios

1. Entrar como administrador y abrir Inicio → Ajustes → Usuarios → Nuevo.
2. Escribir nombre y usuario de acceso; el correo y contacto relacionado son opcionales. El login se guarda en minúsculas y admite letras sin acentos, números, punto, guion, guion bajo, + y @.
3. Seleccionar los grupos. Para vendedores, asociar su contacto de tipo vendedor para preparar los permisos sobre registros propios. Usuarios y tipos de contacto son conceptos distintos.
4. Elegir una contraseña temporal y repetirla. Guardar y comunicarla al usuario por el canal elegido por el negocio.
5. Al entrar, el usuario debe cambiar la contraseña temporal antes de continuar. Su menú permite abrir Mi perfil, Cambiar contraseña y Cerrar sesión.

Un contacto solo puede relacionarse con una cuenta. Se puede conservar un grupo/contacto archivado que ya estaba asignado, pero no asignarlo como nuevo. Las asignaciones a grupos archivados no otorgan permisos. Un administrador puede restablecer una contraseña desde la pestaña Seguridad; define otra contraseña temporal, sin recuperar ni mostrar la anterior. No se incluye recuperación por correo.

## Reglas de seguridad y sesión

- Contraseñas de al menos 8 caracteres y como máximo 72 bytes; una frase suele resultar cómoda. Se guardan con `password_hash()` y se comprueban con `password_verify()`.
- Cookie de sesión HttpOnly y SameSite=Lax, con Secure cuando se utiliza HTTPS. El identificador de sesión cambia al entrar, cambiar contraseña o salir.
- Expiración tras 30 minutos sin solicitudes y un máximo de 8 horas desde el login. Las consultas automáticas del Dashboard cuentan como actividad.
- Diez intentos fallidos por usuario o treinta por dirección IP bloquean nuevos intentos durante 15 minutos. El mensaje de credenciales incorrectas no indica si la cuenta existe.
- Desactivar una cuenta o modificar su login, contacto, grupos o contraseña invalida las sesiones abiertas en la siguiente solicitud. Cambiar nombre/correo conserva la sesión. Al modificar datos sensibles de su propia cuenta, el administrador debe volver a entrar.
- El servidor comprueba el estado de la cuenta en cada solicitud y rechaza direcciones directas sin sesión. Las consultas JSON responden con 401 si falta sesión y 403 si hay acceso restringido.
- CSRF protege formularios y cierre de sesión. Conflictos de edición se rechazan por versión; la creación tiene una clave para evitar duplicar usuarios al reenviar.
- Siempre debe quedar al menos un usuario activo del grupo Administrador. Este grupo conserva las protecciones administrativas definidas en la fase 2.
- Apache bloquea el acceso HTTP a archivos internos de PHP, plantillas, pruebas, documentación y archivos privados. El router del servidor de pruebas aplica bloqueos equivalentes.

## Validación manual antes del push

1. Activar tu cuenta, entrar, salir e intentar abrir un módulo desde su dirección directa sin sesión: debe enviarte al login.
2. Entrar nuevamente y comprobar Empresa, grupos y los procesos habituales como administrador.
3. Crear una cuenta de prueba del grupo Ventas, asociarla a su contacto y entrar desde otro navegador o ventana privada.
4. Comprobar que obliga a cambiar la contraseña y después permite consultar el perfil y acceder a los módulos autorizados por sus grupos.
5. Desactivar esa cuenta desde el administrador y comprobar que pierde acceso en su siguiente solicitud. Reactivarla, entrar y restablecer su contraseña: debe perder la sesión y cambiar la nueva contraseña temporal.
6. Intentar desactivar tu cuenta cuando es el único administrador: debe rechazarlo sin guardar el cambio.
7. Revisar Login y Usuarios en escritorio y móvil. Validar esta fase y hacer el commit/push antes de continuar.

## Pruebas automatizadas

`test/authentication.php` comprueba activación, login, sesiones, contraseñas, protección administrativa, CSRF, límites de intentos y accesos directos. `test/run-authenticated.php` permite ejecutar las pruebas HTTP existentes con una cuenta temporal en un servidor y una base aislados. El entorno copia datos para verificar compatibilidad, pero excluye todas las tablas de autenticación; al finalizar elimina su base y sus sesiones. Nunca exige ni utiliza las credenciales del administrador real.

`NEXO_DATABASE_NAME` permite seleccionar una base desde el entorno del proceso para estas pruebas. Sin esa variable, la aplicación mantiene la base `data` configurada originalmente. No es un parámetro de formularios ni de la URL.

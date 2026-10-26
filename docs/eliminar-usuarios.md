# Eliminación de usuarios

En Ajustes → Usuarios, un Administrador con permiso para editar usuarios puede usar Opciones → Eliminar desde una ficha o seleccionar cuentas en el listado y confirmar su eliminación. La acción usa el flujo común de confirmación, CSRF, selección de empresas, detección de cambios y protección frente a reenvíos.

Se eliminan la cuenta global y sus asociaciones en `auth_user_groups` y `company_user_access`, en una transacción. Los contactos y documentos se conservan. Las referencias de autoría existentes usan `ON DELETE SET NULL`; la confirmación informa que los registros dejarán de mostrar al usuario como autor. El registro de la acción conserva el nombre y el login eliminado, sin guardar contraseñas. Las sesiones anteriores dejan de autorizarse cuando se consulta nuevamente la cuenta.

No se permite eliminar la cuenta de la sesión actual ni dejar el sistema sin un Administrador activo. Si existen relaciones históricas obligatorias, por ejemplo cuentas de restaurante vinculadas al mesero, las claves foráneas impiden eliminarlo y se ofrece desactivarlo. Si falla cualquiera de los usuarios seleccionados, se revierten todos los borrados y sus asociaciones.

No se modifican las claves foráneas ni se requiere migrar el esquema. La acción se limita a Administradores y utiliza el permiso existente de edición de usuarios; no concede permisos nuevos a otros grupos.

Pruebas en una base aislada:

```powershell
C:\xampp\php\php.exe test/user-deletion.php
C:\xampp\php\php.exe test/record-actions.php
```

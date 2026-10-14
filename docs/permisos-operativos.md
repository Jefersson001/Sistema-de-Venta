# Validar permisos operativos — fase 4

Los módulos ya están disponibles para cuentas de grupos personalizados. No es necesario asignar Administrador para abrir Contactos, Ventas, Compras, Inventario, Contabilidad o Dashboard. Inicio muestra únicamente los módulos con alguna sección autorizada y abre su primera sección disponible.

## Preparar una cuenta

1. Como administrador, abrir Ajustes → Grupos de acceso y revisar el grupo del usuario.
2. Marcar Consultar en las secciones que utilizará. Marcar Crear, Editar, Confirmar, Cancelar, Validar, Pagar o Configurar según sus responsabilidades.
3. En las secciones con alcance, elegir Propios o Todos. Los permisos se combinan por acción: Consultar Todos desde un grupo no amplía Editar Propios de otro.
4. Abrir Ajustes → Usuarios, asignar el grupo y guardar. Puede quitarse Administrador si esa cuenta no administrará el sistema.
5. Si utiliza Propios, relacionar la cuenta con su contacto vendedor. Los clientes deben tener ese vendedor asignado; las ventas y facturas conservan el vendedor de cada documento. Las entregas se autorizan por su venta de origen.
6. Entrar con esa cuenta en otro navegador o ventana privada. Si cambió la asignación de grupos mientras tenía sesión, deberá iniciar sesión otra vez.

Una cuenta sin grupos activos no tiene módulos disponibles. Un usuario sin contacto relacionado obtiene listados propios vacíos y no puede crear ventas/clientes propios. Crear una entrega propia se realiza desde su venta relacionada.

## Comprobar vistas y acciones

- Grupo Consulta: puede abrir sus fichas autorizadas; no aparece Nuevo ni las acciones de guardar, confirmar, cancelar, validar o pagar. Los campos de guardado quedan deshabilitados.
- Grupo Ventas con Propios: ve sus clientes, ventas, entregas relacionadas, facturas y comisiones según lo seleccionado. No puede abrir un registro ajeno por su ID ni asignar otro vendedor al crear una venta propia.
- Grupo Inventario: puede trabajar en los productos y operaciones que tenga autorizados. Consultar costos y Configurar comisión son permisos separados; editar el nombre del producto no modifica esos valores si faltan sus permisos.
- Contabilidad: consultar una factura o pago no concede automáticamente confirmar pagos, retirar aplicaciones, crear notas de crédito, revertir movimientos o transferir dinero.
- Configuración: Consultar permite ver los catálogos; Configurar permite crear y guardar sus registros.
- Administración: editar Usuarios, restablecer contraseñas, archivar cuentas y asignar grupos requieren sus permisos específicos. Siempre debe permanecer un administrador activo.

Las mismas restricciones se comprueban en el servidor. Ocultar un botón no sustituye la comprobación de una solicitud POST. Las rutas alternativas de compras, los selectores y las consultas JSON también utilizan la política.

## Documentos relacionados y dinero

Generar una entrega, recepción o factura requiere el permiso en el documento de origen. No concede confirmar/validar el documento nuevo ni consultar su módulo. Si el usuario puede generarlo pero no abrirlo, vuelve al origen con un aviso de creación; los enlaces hacia documentos no autorizados se ocultan.

Para aplicar un pago existente desde una factura se requieren Registrar pago en la factura y Aplicar a factura en los pagos correspondientes. Confirmar o retirar una aplicación desde la factura requiere también el permiso equivalente en el pago. Revertir un egreso de comisión exige la autorización tanto del egreso como de la comisión.

Pagar una comisión requiere Pagar comisión en Ventas y Crear/Confirmar otros egresos en Contabilidad. El botón del Dashboard conduce a Pagos de comisiones si también puede consultar esa sección. Las reversiones de Tesorería heredan el permiso de consulta del movimiento o pago original.

## Dashboard

Las pestañas se muestran según sus permisos. Los indicadores requieren además Consultar en sus fuentes: por ejemplo, Resumen del negocio no concede consultar facturas de proveedores. Los indicadores propios respetan el vendedor y no incluyen fuentes sin relación propia. Las tareas y comisiones se filtran igual que sus registros.

Ingresos y egresos requiere el permiso del Dashboard y el de Saldos e historial de cajas y bancos. Si no está autorizado, no se entregan saldos ni movimientos globales en la consulta JSON. Las otras pestañas siguen actualizándose cada 30 segundos, con Hoy, últimos 7 días, este mes y rango personalizado. Los datos se refrescan al volver a una pestaña visible del navegador.

Modificar o archivar un grupo cambia los accesos en la siguiente solicitud. Dashboard recarga su vista si cambia la matriz, para actualizar también sus pestañas y controles.

## Validación antes del push

1. Probar una cuenta personalizada sin Administrador y confirmar que aparecen sus módulos.
2. Probar Nuevo, guardar y las acciones de proceso con permisos de consulta y con permisos de trabajo.
3. Crear dos vendedores con clientes y ventas diferentes. Probar el alcance Propios, los selectores, las fichas por URL y el Dashboard.
4. Desmarcar un permiso operativo del grupo Administrador y comprobar que tampoco lo puede ejecutar.
5. Archivar un grupo de prueba y comprobar que deja de aportar acceso.
6. Revisar las vistas en escritorio y móvil, incluyendo los grupos que solo tienen acceso a configuración.

Pruebas automatizadas, desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe test/permissions.php
& C:\xampp\php\php.exe test/authentication.php
& C:\xampp\php\php.exe test/run-authenticated.php commerce-workflows dashboard accounting-payments treasury sales-commissions
```

Las pruebas usan cuentas, servidores y bases aisladas. No cambian las asignaciones ni los datos del negocio. No se necesita una migración nueva para esta fase: utiliza los grupos y usuarios ya creados.

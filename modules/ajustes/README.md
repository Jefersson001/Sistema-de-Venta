# Ajustes — fases 1, 2 y 3

Inicio → Ajustes abre las secciones General, Contactos, Ventas, Compras, Inventario y Contabilidad. Los accesos utilizan los catálogos existentes; las monedas se leen de la configuración financiera actual.

Empresa tiene listado y formulario separados. Existe una única ficha para el negocio, inicialmente sin datos. El nombre comercial es obligatorio; razón social, documento/RIF, dirección, teléfono, correo, sitio web y logo son opcionales. No se generan contactos, facturas ni movimientos de dinero al guardar Empresa.

El logo admite PNG, JPEG o WebP de hasta 2 MB y 4096 × 4096 píxeles. Se valida en el servidor y se guarda como datos de imagen en la base de datos; `logo.php` lo entrega con su tipo de imagen y sin caché. Una carga nueva reemplaza el logo; Quitar el logo guardado lo elimina al guardar. Seleccionar imagen solo previsualiza; Descartar no guarda.

Guardar utiliza CSRF y la versión de la ficha. Si dos ventanas editan al mismo tiempo, la segunda debe recargar para revisar los cambios. El instalador puede ejecutarse varias veces sin sobrescribir los datos ni el logo.

Instalación:

```powershell
& C:\xampp\php\php.exe php/install-settings.php
```

También se incluye en `php/install-commerce.php`. La instalación de esta fase se ha aplicado en el entorno local.

Pruebas: `test/business-settings.php`. Desde la fase 3, sus comprobaciones HTTP se ejecutan con `test/run-authenticated.php` en un servidor y una base aislados, con un administrador temporal de prueba. El guardado, logos, conflictos e instalación repetible conservan su propia comprobación aislada.

Validación manual:

1. Abrir Inicio → Ajustes y recorrer las seis secciones.
2. Abrir Empresa, entrar en su formulario y guardar el nombre y datos del negocio.
3. Volver al listado y reabrir la ficha para comprobar que se conservaron los datos.
4. Cargar un logo, guardar y comprobarlo en Empresa y General; cambiarlo y probar quitarlo.
5. Editar Empresa en dos ventanas: guardar la primera y comprobar el aviso de conflicto al guardar la segunda.
6. Abrir los accesos de configuración de cada módulo y revisar la vista móvil.

La fase 1 prepara Ajustes y Empresa. Los datos de Empresa se muestran en Ajustes; su utilización en documentos futuros se integrará cuando se revise la presentación de esos documentos, sin modificar registros históricos.

## Fase 2: Grupos de acceso

Ajustes → Grupos de acceso mantiene listado y formulario separados, búsqueda, filtro por estado y paginación. Nuevo crea un grupo; cada ficha tiene nombre, descripción, estado y una matriz organizada en pestañas por módulo. El alcance Propios/Todos aparece donde existe relación con el vendedor.

Los grupos iniciales son Administrador, Ventas, Compras, Inventario, Contabilidad y Consulta. Son configurables; Administrador conserva activo el acceso a la administración de Ajustes, Empresa, grupos y usuarios. Consultar se marca al seleccionar una acción y quitar Consultar desmarca las acciones de esa sección. El servidor también valida esta dependencia.

Los permisos se suman por acción al combinar grupos. Consultar todas las ventas y Editar ventas propias conserva la edición limitada a las propias. Los grupos archivados no aportan permisos. La matriz y las reglas de combinación están explicadas en [permisos-acceso.md](../../docs/permisos-acceso.md).

El instalador `php/install-settings.php` instala también el catálogo y los grupos, y se mantiene incluido en `php/install-commerce.php`. Es repetible y conserva personalizaciones. Guardar utiliza CSRF, transacción, versión y clave de creación. La instalación de la fase 2 se aplicó en el entorno local.

Pruebas: `test/access-groups.php` comprueba combinación, alcances, denegación, administrador protegido e instalación en una base aislada. Sus comprobaciones HTTP se ejecutan mediante `test/run-authenticated.php`; crean un grupo temporal dentro del entorno aislado y lo eliminan al finalizar. No modifica los grupos del negocio.

Validación manual de la fase 2:

1. Abrir Ajustes → Grupos de acceso y revisar los seis grupos iniciales.
2. Crear un grupo con nombre y seleccionar permisos en las pestañas de los módulos.
3. Probar que Editar activa Consultar y que quitar Consultar desmarca las acciones de la sección.
4. Elegir Propios o Todos en Ventas, guardar y reabrir para comprobar que se conserva.
5. Archivar un grupo personalizado quitando Activo; localizarlo con el filtro Archivados.
6. Editar en dos ventanas y comprobar el aviso al guardar una versión antigua.

La fase 2 define y guarda permisos. La fase 3 incorpora Login y Usuarios; la aplicación por acciones y registros a procesos/Dashboard corresponde a la fase 4 del [plan](../../docs/plan-ajustes.md).

## Fase 3: Usuarios e inicio de sesión

Ajustes → Usuarios tiene listado y formulario separados, búsqueda, filtros y paginación. Cada cuenta tiene nombre, usuario de acceso único, correo opcional, estado, contacto opcional y grupos. Un contacto solo puede estar asociado a una cuenta. Para un vendedor, el contacto relacionado permitirá identificar sus registros al aplicar el alcance Propios en la fase 4.

El administrador elige una contraseña temporal al crear o restablecer una cuenta. Esa cuenta debe cambiarla antes de acceder al sistema. Las contraseñas se almacenan como hash y nunca se muestran después de guardar. Se protegen los formularios con CSRF, versiones y claves de creación para evitar duplicados. No se permite desactivar ni retirar el grupo del último administrador activo.

Todas las entradas del negocio exigen sesión, incluidas las consultas JSON y el logo. En esta etapa, solo el grupo Administrador utiliza los módulos operativos; los demás usuarios acceden a Inicio, su perfil y cambio de contraseña hasta implementar la fase 4. El menú superior muestra la identidad real y permite consultar el perfil, cambiar la contraseña y cerrar sesión.

La instalación ya está aplicada, sin crear cuentas reales. El propietario prepara la primera cuenta ejecutando desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe php/prepare-admin.php
```

Debe abrir el enlace generado, que vence en 30 minutos, y elegir su nombre, usuario y contraseña. No existe una contraseña predeterminada ni un registro público de usuarios. Repetir el instalador conserva las cuentas y no vuelve a habilitar esa activación.

Pruebas de autenticación y regresión:

```powershell
& C:\xampp\php\php.exe test/authentication.php
& C:\xampp\php\php.exe test/run-authenticated.php business-settings access-groups commerce-workflows accounting-payments treasury dashboard
```

Las pruebas crean bases de datos, cuentas y sesiones aisladas; no copian contraseñas reales ni necesitan las credenciales del propietario. Guía de validación manual y reglas de sesiones en [usuarios-acceso.md](../../docs/usuarios-acceso.md).

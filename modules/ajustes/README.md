# Ajustes — fases 1 y 2

Inicio → Ajustes abre las secciones General, Contactos, Ventas, Compras, Inventario y Contabilidad. Los accesos utilizan los catálogos existentes; las monedas se leen de la configuración financiera actual.

Empresa tiene listado y formulario separados. Existe una única ficha para el negocio, inicialmente sin datos. El nombre comercial es obligatorio; razón social, documento/RIF, dirección, teléfono, correo, sitio web y logo son opcionales. No se generan contactos, facturas ni movimientos de dinero al guardar Empresa.

El logo admite PNG, JPEG o WebP de hasta 2 MB y 4096 × 4096 píxeles. Se valida en el servidor y se guarda como datos de imagen en la base de datos; `logo.php` lo entrega con su tipo de imagen y sin caché. Una carga nueva reemplaza el logo; Quitar el logo guardado lo elimina al guardar. Seleccionar imagen solo previsualiza; Descartar no guarda.

Guardar utiliza CSRF y la versión de la ficha. Si dos ventanas editan al mismo tiempo, la segunda debe recargar para revisar los cambios. El instalador puede ejecutarse varias veces sin sobrescribir los datos ni el logo.

Instalación:

```powershell
& C:\xampp\php\php.exe php/install-settings.php
```

También se incluye en `php/install-commerce.php`. La instalación de esta fase se ha aplicado en el entorno local.

Pruebas: `test/business-settings.php`. El guardado, logos, conflictos e instalación repetible se comprueban en una base de datos de prueba aislada que se elimina al terminar. Las comprobaciones HTTP sobre el proyecto se limitan a lecturas e intentos inválidos que no alteran Empresa.

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

Pruebas: `test/access-groups.php` comprueba combinación, alcances, denegación, administrador protegido e instalación en una base aislada. Sus comprobaciones HTTP crean únicamente un grupo temporal identificado y lo eliminan al finalizar. No modifica los grupos del usuario.

Validación manual de la fase 2:

1. Abrir Ajustes → Grupos de acceso y revisar los seis grupos iniciales.
2. Crear un grupo con nombre y seleccionar permisos en las pestañas de los módulos.
3. Probar que Editar activa Consultar y que quitar Consultar desmarca las acciones de la sección.
4. Elegir Propios o Todos en Ventas, guardar y reabrir para comprobar que se conserva.
5. Archivar un grupo personalizado quitando Activo; localizarlo con el filtro Archivados.
6. Editar en dos ventanas y comprobar el aviso al guardar una versión antigua.

Esta fase define y guarda permisos. Login, Usuarios y la aplicación a procesos/Dashboard corresponden a las fases 3 y 4 del [plan](../../docs/plan-ajustes.md). La interfaz informa de ello para que preparar grupos no se confunda con tener restricciones activadas.

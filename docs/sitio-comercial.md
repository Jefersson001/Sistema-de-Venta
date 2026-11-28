# Sitio comercial de Konta

## Acceso

- Sitio público: `/conoce-konta.php`.
- Portal de interesados: `/portal-demo.php`; registro: `/portal-demo.php?view=registro`.
- El inicio `/index.php` muestra el sitio comercial a visitantes sin sesión. Los usuarios autenticados conservan su panel de módulos.
- El inicio de sesión incluye el enlace «Conoce Konta».
- Administración comercial independiente: `/admin-comercial.php`; bandeja de todas las solicitudes: `/solicitudes-demo.php`.
- El administrador comercial utiliza su propio correo y contraseña. No hereda el acceso de un administrador del ERP y no obtiene acceso a los módulos operativos. La bandeja no aparece en Ajustes.

## Solicitudes

El visitante crea una cuenta de interesado, inicia sesión y solicita la demostración desde su portal. Puede consultar únicamente sus propias solicitudes y su historial de respuestas. La cuenta no crea usuarios operativos, empresas, contactos comerciales ni suscripciones dentro del ERP. La cantidad de empresas indicada en la solicitud sirve para preparar la propuesta; no aprovisiona empresas.

El portal abre un panel de seguimiento con indicadores de solicitudes, filtro inmediato por estado, estado actual e historial. Los indicadores resumen todas las solicitudes del propietario, incluso cuando el listado está filtrado o paginado. «Solicitar demo» abre una vista de formulario independiente (`/portal-demo.php?section=solicitar`), con Enviar solicitud y Cancelar; el panel principal no incluye el formulario al pie. Los enlaces antiguos `?nueva=1` también abren esta vista. Los errores de validación se muestran en la vista de creación conservando los datos ingresados. Si el cliente tiene una solicitud sin rechazar, la vista muestra un aviso y no permite crear otra.

El espacio del cliente utiliza navegación lateral en escritorio y navegación inferior en teléfonos. Mi espacio ofrece Mis solicitudes y, cuando corresponde, Solicitar demo; Seguimiento es una pestaña dentro de cada solicitud. «Conocer el sistema» está dentro de Mi espacio. El nombre aparece arriba a la derecha y abre un menú con Mi cuenta y Cerrar sesión. Mi cuenta es una vista independiente (`/portal-demo.php?section=cuenta`), donde se muestran los datos de contacto y el cambio de contraseña; no aparece en el menú lateral ni en el panel de seguimiento. Cada solicitud distingue sus datos del historial de respuestas, e incluye una explicación del estado actual. No incluye chat en tiempo real ni mensajes públicos entre clientes. Su diseño está aislado en `css/commercial-workspace.css`.

Mis solicitudes utiliza una lista tipo tree (tabla), con filtro por estado y paginación. Al pulsar una fila se abre la ficha `/portal-demo.php?request=ID`. La ficha es de consulta, muestra las fases según el historial real y ofrece las pestañas Datos de la solicitud y Seguimiento. Las pestañas funcionan mediante enlaces si no hay JavaScript y mediante navegación accesible con teclado cuando está disponible. El servidor comprueba el propietario de cada ficha y devuelve 404 para identificadores ajenos o inexistentes. Volver al listado conserva el filtro y la página.

Administración puede buscar solicitudes, cambiar su estado, escribir una respuesta visible para el solicitante y guardar notas internas privadas. Cada cambio de estado o respuesta se añade al historial visible en el portal. Editar solamente las notas privadas no añade actividad pública.

El flujo habitual es Solicitud enviada → Aprobar demo o Rechazar demo. Son acciones directas de la ficha administrativa: no necesitan pasar por Contactado ni Demo coordinada. Los estados anteriores se conservan para consultar su historial. Una solicitud Aprobada muestra al propietario el botón Acceder al sistema, dirigido al login nativo (`/login.php`), únicamente dentro de su ficha, no en el panel general. Si cambia a otro estado, el botón deja de mostrarse. La aprobación registra quién la realizó mediante la auditoría comercial y añade una actualización al historial.

**El acceso operativo se prepara manualmente:** antes de aprobar, el responsable crea el usuario, su empresa y sus permisos en el sistema principal y entrega sus credenciales al cliente. Aprobar no crea, modifica ni habilita usuarios, empresas o permisos del ERP; solo muestra el enlace al login. Los registros y contraseñas del portal no se reutilizan automáticamente como credenciales del sistema. Rechazar u ocultar el enlace tampoco desactiva un usuario operativo ya creado; sus permisos se gestionan en el sistema principal.

Cada cuenta puede solicitar una demo inicialmente. Solo puede volver a solicitarla cuando todas sus solicitudes anteriores estén en estado **Rechazada**. Nueva, Aprobada, Contactado, Demo coordinada y Cerrada bloquean solicitudes adicionales. El formulario y los accesos de nueva solicitud desaparecen mientras exista ese bloqueo; la regla se valida también en el servidor, bloqueando la fila de la cuenta dentro de la transacción para impedir duplicados concurrentes. Los reintentos idénticos conservan su respuesta sin crear otra solicitud. Administración no puede reabrir una solicitud rechazada si el cliente ya tiene otra sin rechazar. Rechazar no habilita el acceso operativo al ERP.

Las solicitudes anónimas anteriores se conservan en la bandeja administrativa y no se asignan automáticamente a una cuenta que registre el mismo correo. Así se evita revelar solicitudes antiguas a alguien que solo conozca una dirección de correo.

No se envían notificaciones por correo ni WhatsApp automáticamente. Mientras no se definan los datos comerciales públicos, el portal es el medio de contacto. No utiliza el correo ni el teléfono de las empresas clientes. El registro no verifica la propiedad del correo ni incluye recuperación de contraseña por correo; estos procesos requieren definir el correo saliente de la plataforma. El interesado sí puede cambiar su contraseña desde una sesión abierta, indicando la actual.

Las contraseñas se almacenan mediante `password_hash`. La identidad de sesión del portal está separada de la identidad operativa. Se aplican CSRF, caducidad de sesión, renovación del identificador al entrar/salir, límite de intentos de acceso/registro y control de propietario en consultas. Cambiar la contraseña invalida las otras sesiones del portal.

Las solicitudes pertenecen a la plataforma comercial, no a una empresa operativa. Por eso sus tablas no se registran como tablas multiempresa. El acceso a la bandeja exige una cuenta activa de `commercial_admin_accounts`, con identidad de sesión separada del ERP y del portal del cliente. El seguimiento nuevo registra `updated_by_commercial_admin`; la columna anterior `updated_by` se conserva por compatibilidad y no se reutiliza para identificar al administrador comercial.

## Habilitación de la administración comercial

Tras aplicar las migraciones, ejecutar una sola vez desde la terminal del servidor:

```sh
php php/commercial-admin-setup.php
```

El comando emite una ruta de habilitación inicial. Abrirla con el dominio de esa instalación y definir nombre, correo y contraseña. El enlace dura 24 horas, es de un solo uso y únicamente permite crear el primer administrador comercial. En la base se guarda su hash, no el token original. No existe registro administrativo público sin este enlace. Generar otro enlace antes de usar el anterior lo invalida.

Después se ingresa por `/admin-comercial.php`. La bandeja permite buscar y filtrar solicitudes, ver totales por estado, cambiar el seguimiento y responder. El nombre del administrador permite acceder a su cuenta y cambiar la contraseña actual; Cerrar sesión afecta solamente al acceso comercial. Deshabilitar la cuenta o cambiar su versión de autenticación revoca sus sesiones. Las cuentas y los datos comerciales reales no se copian a las bases de pruebas.

La bandeja administrativa utiliza una vista tree (tabla). Cada fila abre `/solicitudes-demo.php?request=ID`, con fases y pestañas Datos, Seguimiento y Notas internas. La ficha mantiene los cambios escritos al cambiar de pestaña, guarda estado/respuesta/notas mediante un único formulario y conserva la pestaña activa tras guardar. La respuesta y los estados forman parte del historial del cliente; las notas internas siguen siendo privadas. El enlace de regreso conserva búsqueda, estado y página. Los conflictos de edición conservan los textos enviados y requieren recargar la ficha antes de volver a guardar.

## Diseño del portal

La ficha administrativa no muestra un selector «Estado de seguimiento». Las decisiones se realizan con «Aprobar demo» y «Rechazar demo»; «Guardar cambios» conserva el estado actual al guardar respuestas o notas. El estado y el historial siguen visibles para consultar la situación de cada solicitud.

`css/commercial-design.css` unifica exclusivamente el sitio público, el portal del cliente y la administración comercial. Toma como referencia la identidad del sistema operativo: títulos azul oscuro, acciones verdes, fondos claros, tarjetas blancas y bordes suaves. Se carga al final en las cuatro plantillas comerciales; no se carga en el ERP.

El sitio presenta el recorrido de registro, solicitud y aprobación mediante `html/commercial/demo-journey.php`. El formulario de solicitud agrupa los campos en «Tu negocio» y «Tu demostración». El portal conserva las vistas tree/form, las pestañas y el menú de cuenta; mejora contraste, estados, controles y navegación móvil. No añade una sección Seguimiento al menú Mi espacio ni habilita accesos operativos antes de la aprobación.

Validación: `php test/commercial-site.php --browser`; capturas de escritorio y teléfono en `work/commercial-site-review/`.

## Contenido y precios

`php/commercial-content.php` contiene los módulos, sectores y planes. Los precios son una propuesta comercial de referencia, sujetos a confirmación del alcance y cotización. No existe cobro automático. La captura del panel usa exclusivamente datos de demostración y el ejemplo cambiario es ilustrativo.

## Despliegue

Subir el código, estilos, JavaScript e imagen incluidos en este cambio. La migración `20261127173632_solicitudes_demo_sitio_comercial.php` crea las tablas de solicitudes y control de frecuencia. La migración `20261127182658_portal_clientes_demo.php` añade las cuentas del portal, la relación de propietario y el historial público, conservando las solicitudes existentes. La migración `20261127222824_administracion_comercial_independiente.php` añade las cuentas administrativas comerciales, la habilitación inicial y su identidad de auditoría. Dokploy las ejecuta mediante el mecanismo habitual de arranque; no requiere importar respaldos. En una instalación existente sin arranque automático, ejecutar `php php/migrate.php`.

## Validación

- `php test/commercial-site.php`: solicitudes reales sobre una base aislada, validación, permisos, CSRF, reintentos, frecuencia y migración repetida.
- `php test/commercial-site.php --browser`: añade pruebas reales de navegador en móvil y escritorio, envío del formulario y seguimiento privado.
- `node test/commercial-site-ui.js --preview-only`: revisión del sitio local sin guardar solicitudes en la base real.
- `php test/migrations.php` y `node test/deploy-startup.js`: mecanismo de migraciones y despliegue.

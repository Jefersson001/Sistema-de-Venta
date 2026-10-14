# Plan de acción: Ajustes, usuarios y acceso

Objetivo: completar la administración de un negocio que vende productos, compra, controla existencias y gestiona su dinero. Mantener el diseño y las relaciones existentes, con una empresa y usuarios internos.

Cada fase se entrega con cambios concretos, pruebas y una lista de validación. El usuario valida y hace su commit/push antes de continuar con la siguiente fase. No se realiza un push automático.

## Fase 1 — Ajustes generales y Empresa

Estado: implementada y verificada el 14/10/2026; pendiente de validación y push del usuario.

1. Agregar Ajustes al inicio y su navegación propia.
2. Organizar Ajustes generales por General, Contactos, Ventas, Compras, Inventario y Contabilidad.
3. Reutilizar los catálogos y monedas ya existentes, sin duplicar configuraciones ni cambiar documentos históricos.
4. Crear Empresa con listado y formulario: nombre comercial, razón social, documento/RIF, dirección, teléfono, correo, sitio web y logo.
5. Instalar una ficha única del negocio, sin inventar sus datos, mediante un instalador repetible.
6. Proteger el guardado con CSRF, validación de imagen y comprobación de cambios simultáneos.

Validación del usuario: abrir Ajustes desde Inicio, recorrer las secciones, guardar los datos de Empresa, cargar/cambiar/quitar logo y volver a abrir la ficha. Revisar móvil y los accesos a la configuración de los módulos.

Criterio de cierre: ficha persistente, una sola fuente para los catálogos actuales, pruebas de guardado e instalación, vistas revisadas. Esta fase no activa usuarios ni restricciones de acceso.

Verificación realizada: `test/business-settings.php`, `test/commerce-workflows.php`, `test/dashboard.php`, sintaxis PHP/JavaScript y revisión en navegador a 1440 y 390 píxeles. El instalador `php/install-settings.php` se ejecutó en el proyecto. Empresa permanece sin datos reales, lista para que el usuario la configure. La carga y persistencia del logo se probaron en una base aislada; la selección y vista previa se comprobaron en navegador sin guardar datos del proyecto.

## Fase 2 — Grupos y definición de permisos

Estado: implementada y verificada el 14/10/2026; pendiente de validación y push del usuario.

1. Crear Grupos de acceso con listado y formulario.
2. Definir un catálogo de permisos por módulo, sección, operación y alcance de registros.
3. Preparar grupos configurables: Administrador, Ventas, Compras, Inventario, Contabilidad y Consulta.
4. Separar consultar/crear/editar de confirmar/cancelar/pagar/revertir/configurar.
5. Definir cómo se combinan permisos al asignar varios grupos y mantener denegado lo no autorizado.
6. Acordar las reglas de ventas propias por vendedor asignado y de acceso a todos los registros.

Validación del usuario: crear/editar un grupo y revisar su matriz de permisos. Los permisos aún no se consideran operativos hasta su aplicación en las fases 3 y 4.

Criterio de cierre: matriz documentada y comprobada, sin confundir tipos de contacto con grupos de acceso.

Entregado: Grupos de acceso con listado/formulario, catálogo por módulo/sección/acción, seis grupos iniciales, alcances Propios/Todos, combinación por acción y protección de permisos administrativos. Las reglas y relaciones para su aplicación se documentan en [permisos-acceso.md](permisos-acceso.md).

Verificación: `test/access-groups.php`, `test/business-settings.php`, `test/commerce-workflows.php`, sintaxis PHP/JavaScript y revisión de matriz/controles en navegador a 1440 y 390 píxeles. El instalador `php/install-settings.php` se ejecutó en el proyecto. No se activó aún la autenticación ni la aplicación de permisos a los módulos.

## Fase 3 — Usuarios e inicio de sesión

Estado: pendiente.

1. Crear Usuarios con listado/formulario, nombre, login único, correo, estado, grupos y contacto relacionado.
2. Crear el primer administrador mediante una preparación controlada, sin una contraseña pública predeterminada.
3. Implementar login, cierre de sesión, cambio/restablecimiento de contraseña y cambio obligatorio de contraseña temporal.
4. Guardar contraseñas mediante hash; gestionar sesiones y limitar intentos fallidos.
5. Proteger todas las entradas del sistema, incluidas consultas JSON, y reservar Ajustes administrativos al administrador.
6. Invalidar sesiones al desactivar usuarios o cambiar credenciales; proteger al último administrador activo.
7. Sustituir el avatar genérico por la identidad y opciones del usuario.

Validación del usuario: entrar/salir, crear un usuario, cambiar contraseña, desactivar y comprobar que no entra. Mientras se completan los permisos del negocio en la fase 4, las cuentas sin autorización no reciben acceso general.

Criterio de cierre: autenticación obligatoria y administración protegida. Probar que una dirección directa no evita el login.

## Fase 4 — Permisos aplicados al negocio y Dashboard

Estado: pendiente.

1. Aplicar permisos antes de consultar datos o ejecutar acciones, además de ajustar menús, enlaces y botones.
2. Proteger Contactos, productos, almacenes, operaciones, ventas, compras, facturas, pagos, Tesorería y comisiones.
3. Respetar permisos en las operaciones entre módulos: crear entrega no equivale a validarla; generar factura no concede acceso a bancos.
4. Aplicar las reglas de registros propios/todos en listados, formularios, selectores relacionados y acciones.
5. Filtrar Dashboard y sus respuestas automáticas por los permisos del usuario, incluyendo importes, tareas y comisiones.
6. Controlar pagar comisiones, transferir dinero, crear saldos iniciales y revertir movimientos con permisos específicos.
7. Comprobar que un cambio de grupos se refleja sin conservar permisos anteriores en la sesión.

Validación del usuario: probar cuentas de Ventas, Compras, Inventario, Contabilidad y Consulta; intentar también acceder por direcciones directas y confirmar acciones sin permiso.

Criterio de cierre: acceso coherente en servidor y vistas, sin exponer datos por el Dashboard ni los endpoints.

## Fase 5 — Validación completa y cierre

Estado: pendiente.

1. Probar venta → entrega → factura → cobro → comisión → pago al vendedor con usuarios separados.
2. Probar compra → recepción → factura de proveedor → pago.
3. Comprobar parciales, devoluciones, cancelaciones, reversiones, monedas y los saldos de Tesorería y Dashboard.
4. Verificar que cambios de Empresa y ajustes no reinterpretan importes ni documentos registrados.
5. Ejecutar las comprobaciones de migración, concurrencia, accesos y versiones relevantes.
6. Entregar una guía de operación y una lista final de validación del usuario.

Criterio de cierre: procesos completos con permisos coherentes y resultados financieros conservados. Validación final del usuario y su push de cierre.

## Alcance

Una empresa del negocio y usuarios internos. La configuración financiera existente conserva sus reglas. Esta planificación no incluye multiempresa, portales externos ni un historial general de acciones; las reversiones y relaciones ya existentes se conservan.

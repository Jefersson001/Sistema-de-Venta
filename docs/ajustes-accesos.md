# Actualización de Ajustes y accesos

La revisión detectó opciones operativas nuevas que no aparecían en Ajustes generales y descripciones de permisos que correspondían a una versión anterior.

## Opciones actualizadas

- **Usuarios y accesos:** enlaces a grupos, usuarios y empresas autorizadas.
- **Dashboard:** acceso al panel y a la configuración de permisos de sus indicadores.
- **Inventario:** productos, variantes, categorías, almacenes y ubicaciones.
- **Restaurante:** áreas y mesas, categorías de la carta, productos, cajas, medios de pago, vuelto, monedas y permisos.
- **Contabilidad:** acceso a monedas/tasas y explicación de los medios con vuelto. El enlace antiguo `section=Contabilidad` sigue funcionando.

La navegación muestra las secciones con opciones autorizadas para el usuario. Una sección solicitada directamente sin opciones autorizadas responde 403; los destinos conservan también sus propias comprobaciones de permisos.

## Grupos de acceso

La sección **Productos y variantes** utiliza los permisos existentes de Productos. Consultar, crear, editar, consultar costos y configurar comisión se aplican a ambas fichas. No se añadieron permisos duplicados.

En **Restaurante**, Facturar y Cobrar se necesitan juntos para completar el proceso de cobro. Incluyen pagos combinados, reparto y comprobante. Cerrar sin consumo autoriza descartar el borrador. Los permisos de configuración de áreas y mesas no conceden permiso para cobrar.

El alcance **Propios** tiene significados específicos: en Ventas se relaciona con el contacto vendedor; en Restaurante corresponde a las cuentas cuyo responsable es el usuario mesero. Un mesero no necesita un contacto de tipo Vendedor. Las empresas autorizadas siguen limitando el acceso a los registros.

Al crear un grupo se pueden aplicar estas plantillas:

| Plantilla | Uso |
|---|---|
| Mesero | Mapa y pedidos de sus propias cuentas; consulta de productos. |
| Caja de restaurante | Consulta y cobro de todas las cuentas; actualización del estado de mesas y consulta de clientes/productos. |
| Encargado de restaurante | Gestión de todas las cuentas, cierre sin consumo, áreas/mesas y cobros; consulta de productos, existencias y clientes. |

Las plantillas rellenan la selección del formulario. Se pueden revisar y ajustar antes de guardar. Su aplicación es explícita y no asigna usuarios ni cambia otros grupos. Los grupos existentes y sus permisos se conservan.

## Sincronizar el catálogo en otra instalación

```powershell
& C:\xampp\php\php.exe php/sync-access-catalog.php
```

El script actualiza las entradas y descripciones del catálogo. No concede permisos a los grupos ni restaura permisos retirados. La actualización local cambió cinco descripciones de Productos para incluir sus variantes y conservó las asignaciones de permisos. No es necesario ejecutar el instalador antiguo de Ajustes para esta actualización.

## Comprobaciones

- `php test/run-authenticated.php business-settings access-groups`: accesos reales, compatibilidad de enlaces, validación de plantillas, alcances y preservación de permisos al sincronizar.
- `php test/settings-access-updates.php`: secciones actualizadas, opciones de Restaurante y filtrado por permisos.
- `php test/settings-access-updates.php <carpeta-de-capturas>` con navegador CDP temporal en el puerto 9340: selección de permisos y alcances de cada plantilla, escritorio y móvil.

Las pruebas utilizan bases y usuarios temporales.

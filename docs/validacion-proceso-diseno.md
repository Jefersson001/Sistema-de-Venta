# Validación de proceso y diseño — 14 de octubre de 2026

## Resultado

Los circuitos probados de ventas, compras, inventario, facturación, pagos, comisiones y Tesorería funcionan con los permisos aplicados. No se detectaron errores de cálculo en esos escenarios. La interfaz revisada tiene una base moderna: campos compactos, tipografía legible, colores consistentes, pestañas y adaptación a móvil.

Esto valida los escenarios descritos; no equivale a certificar todas las combinaciones posibles ni a declarar el sistema listo para producción. Hay mejoras de presentación concretas y falta la aceptación manual del negocio, incluida la comprobación de respaldo y restauración antes de usar datos definitivos.

Se trabajó sobre bases temporales que excluyen los usuarios, contraseñas y secretos de autenticación reales. Los usuarios y operaciones del circuito nuevo se crearon exclusivamente allí. Se añadieron pruebas y este informe; no se modificó el comportamiento de la aplicación ni se hizo commit/push.

## Circuito completo con usuarios separados

La nueva prueba `test/role-workflows.php` utiliza cinco usuarios, cada uno con los permisos iniciales de su función: Ventas, Compras, Inventario, Contabilidad y Consulta. Ninguno utiliza el grupo Administrador para ejecutar el circuito.

1. **Contabilidad** registra y confirma un saldo inicial de 100 en una cuenta de caja.
2. **Compras** crea y confirma una orden de 10 unidades a 3 cada una y genera su recepción.
3. **Inventario** registra las cantidades recibidas y valida la recepción. Compras no puede validar inventario mediante un POST directo.
4. **Compras** genera la factura del proveedor; **Contabilidad** la registra y confirma su pago de 30. Compras no puede registrar la factura sin ese permiso.
5. **Ventas** crea una orden para un cliente relacionado con su vendedor. El producto tiene precio de tienda 5, comisión fija 2 por unidad e impuesto de prueba del 10 %. Se venden 2 unidades: precio unitario 7, base 14, impuesto 1,40, total 15,40 y comisión 4.
6. **Ventas** confirma la orden y genera la entrega. **Inventario** valida 2 unidades entregadas. Quedan 8 unidades internas.
7. **Ventas** genera la factura del cliente y **Contabilidad** la registra.
8. Un cobro de 7,70 registrado pero aún sin confirmar deja la factura **En proceso de pago**. Al confirmar ese cobro queda **Parcialmente pagado** y habilita 2 de comisión.
9. Al registrar y confirmar otros 7,70 queda **Pagado** y habilita los 4 de comisión.
10. **Contabilidad** paga la comisión de 4 desde la cuenta y se registra el egreso correspondiente. **Consulta** no puede cancelar la venta; **Ventas** no puede abrir los egresos generales.

| Comprobación final de la cuenta de prueba | Resultado |
| --- | ---: |
| Saldo inicial | 100,00 |
| Cobros confirmados | 15,40 |
| Pago al proveedor | 30,00 |
| Pago de comisión | 4,00 |
| Egresos del período | 34,00 |
| Saldo final | 81,40 |
| Existencias internas del producto | 8 unidades |

El saldo final es dinero disponible; no representa la utilidad del negocio. El impuesto del ejemplo sirve únicamente para validar el cálculo.

## Otras comprobaciones realizadas

| Prueba | Resultado y alcance |
| --- | --- |
| `commerce-workflows.php` | Compra → recepción → factura y venta → entrega → factura; copia de datos/líneas, enlaces de origen, factura directa, generación repetida y CSRF. |
| `commerce-partials.php` | Cantidades parciales, pendientes automáticos, facturas sucesivas, reservas, cancelación, sustitución de operaciones, devoluciones, notas de crédito parciales y precios por proveedor. |
| `accounting-payments.php` | Pagos independientes, excedente 50/30 que conserva 20 disponibles, aplicación posterior a otra factura, clientes/proveedores, monedas, redondeo y estados mixtos. |
| `sales-commissions.php` | Reglas fija/porcentual, referencia histórica, separación de impuestos, cobros parciales, egreso al pagar, protección contra duplicados/sobrepago, devolución y reversión. |
| `treasury.php` | Saldo inicial, ingresos/egresos, conceptos, transferencias, insuficiencia de fondos, concurrencia, reversión y conservación del movimiento original. |
| `document-statuses.php` | Estados, filtros, envío marcado localmente, pagos parciales/completos, cancelación y reversión. |
| `currency-catalog.php` | Monedas, tasas fechadas, tasas inversas, principal/alterna, archivo, versiones, historial y conservación de snapshots. |
| `dashboard.php` | Indicadores JSON contrastados con la base, pestañas, rangos de fecha, rechazo de entradas inválidas y respuestas sin caché. |
| `business-settings.php`, `access-groups.php` | Empresa, logos, cambios por versión, instalación repetible, grupos y permisos combinados, alcances y filtros. |
| `authentication.php`, `permissions.php` | Login, contraseñas, sesiones, último administrador, URL y POST protegidos, registros propios, grupos archivados, costos y fuentes financieras del Dashboard. |
| Siete pruebas JavaScript | Pestañas/teclado, monedas, Dashboard, operaciones, asistente de pagos, comisiones y líneas de venta. |

La prueba de pagos completó sus comprobaciones. Uno de sus primeros intentos quedó suspendido por el entorno de ejecución de Windows y terminó requiriendo limpieza manual de procesos temporales. También se interrumpieron intentos iniciales de otras suites por esa suspensión. Al reejecutar con supervisión de sus propios procesos se completaron las comprobaciones; no se atribuyó esa incidencia a los procesos del negocio.

Dos fixtures JavaScript estaban desactualizados respecto a los permisos incorporados: faltaba `can_pay` en la comisión de prueba y `dataset` en el formulario simulado de inventario. Se corrigieron los fixtures y se agregaron verificaciones de consulta sin botón de pago y sin edición. Las siete pruebas pasan.

### Repetir las pruebas

Desde la carpeta del proyecto:

```powershell
& C:\xampp\php\php.exe test/role-workflows.php
& C:\xampp\php\php.exe test/authentication.php
& C:\xampp\php\php.exe test/permissions.php
& C:\xampp\php\php.exe test/run-authenticated.php commerce-workflows commerce-partials accounting-payments sales-commissions treasury document-statuses currency-catalog dashboard business-settings access-groups
Get-ChildItem test -Filter '*.js' | ForEach-Object { node $_.FullName }
```

Cada invocación crea y elimina su entorno de prueba; no necesita credenciales del administrador real. `role-workflows.php --export-views` exporta HTML para revisión a la carpeta temporal, incluyendo la vista del Dashboard del entorno clonado. Esas vistas son privadas de revisión y no deben publicarse.

## Revisión visual realizada

Se renderizaron con Microsoft Edge las vistas exportadas de venta, lista de ventas, factura de cliente, producto y Dashboard, en anchos de 1440 y 390 píxeles. No se detectó desbordamiento horizontal del documento. Las tablas y la barra larga de factura utilizan desplazamiento dentro de su contenedor.

Los campos principales miden aproximadamente 36–38 px de alto con texto de 15 px en escritorio; en móvil, 40 px con texto de 16 px. La revisión visual utilizó HTML generado por el servidor, CSS y JavaScript reales. No sustituye una prueba interactiva completa en cada navegador o dispositivo.

### Mejoras concretas, en orden recomendado

1. **Desglosar el impuesto en los totales de factura.** La venta muestra base, nombre/porcentaje/importe de impuesto y total. La factura muestra base, comisión incluida y total, pero omite el importe separado del impuesto. En el ejemplo debe verse base 14, impuesto 1,40 y total 15,40, conservando la comisión incluida de 4 como información. El cálculo guardado es correcto; la presentación deja la diferencia sin explicar. Referencias: `html/accounting/invoice-form.php:52` y `html/sales/form.php:58`.

2. **Compactar la cabecera y mantener accesibles las acciones.** Entre la barra superior, Volver al inicio, menú, mensajes, título y formulario hay mucho espacio vertical. En móvil las líneas de productos quedan muy abajo. Usar una cabecera de control compacta con ruta, título y acciones; colocar Guardar/Descartar allí, mantenerla visible durante la edición y reducir mensajes de éxito a una notificación breve. El correlativo puede quedar en el título sin repetir un campo y una explicación en registros guardados. Referencias: `html/sales/form.php:34`, `html/sales/form.php:70`, `html/accounting/invoice-form.php:60`.

3. **Hacer inequívoco el estado actual de la factura.** Se resaltan Registrado y Pagado simultáneamente con el mismo estilo. En móvil Pagado queda fuera del tramo visible inicialmente. Mantener todos los estados en una misma barra, como se solicitó, pero diferenciar visualmente estado del documento y del pago, y desplazar la barra al estado de pago activo al abrirla. Esto mejora la lectura sin cambiar la gestión de estados.

4. **Dar más fluidez a las listas.** El estilo actual ya es moderno; mejoraría el uso con encabezados de tabla visibles al desplazarse, búsqueda con una pequeña espera al escribir, aplicación de filtros al cambiar el estado y ordenación por fecha, correlativo o importe. Cualquier filtro debe conservarse al entrar en un registro y volver al listado. Son mejoras de uso; los filtros existentes se validaron y funcionan.

5. **Reducir texto permanente y actualizar mensajes del producto.** Las instrucciones largas de comisiones, impuestos y existencias pueden ir en ayuda desplegable. En Inicio varias áreas operativas siguen figurando como «En desarrollo» y el pie de los módulos dice «7 módulos · En construcción». Como el sistema sigue en pruebas, conviene un aviso global coherente de entorno de pruebas y descripciones que reflejen lo que ya hace cada módulo. Referencias: `html/home.php` y `html/module-placeholder.php`.

6. **Aclarar qué significa el período en cada pestaña del Dashboard.** Hoy/7 días/mes filtran movimientos de dinero y comisiones; existencias son actuales y los pendientes abarcan documentos abiertos de cualquier fecha. Esa separación es razonable, pero merece etiquetas explícitas para evitar interpretar los pendientes como «solo de hoy». La actualización automática comprobada consulta cada 30 segundos; no es transmisión instantánea de eventos.

### Mejora de comportamiento de sesión

El límite de 30 minutos cuenta inactividad de solicitudes. Un Dashboard visible consulta cada 30 segundos y renueva `auth_seen`, aun sin interacción de la persona. Se conserva el límite absoluto de 8 horas. Recomiendo que las consultas automáticas no prolonguen la sesión por sí solas si se desea cerrar una cuenta abandonada después de 30 minutos. Es una decisión de comportamiento pendiente, no un fallo de los permisos comprobados. Referencia: `php/auth.php:53` y `php/auth.php:56`.

## Relación con el proceso de Odoo

La facturación según cantidades ordenadas o entregadas permite separar confirmación comercial, movimiento físico y factura. Esa distinción coincide con la [política de facturación documentada por Odoo](https://www.odoo.com/documentation/18.0/applications/sales/sales/invoicing/invoicing_policy.html).

Los pagos pueden vincularse a una factura o registrarse independientes para aplicarlos después; ese comportamiento también está descrito en la [documentación de pagos de Odoo](https://www.odoo.com/documentation/19.0/applications/finance/accounting/payments/online.html). Aquí la confirmación del dinero es manual: no se comprobó ni se implementó sincronización bancaria o conciliación automática con extractos.

Los grupos y alcances propios/todos siguen una idea similar a la [gestión de accesos de Odoo](https://www.odoo.com/documentation/19.0/applications/general/users/access_rights.html). Se comprobaron las restricciones del servidor, además de ocultar opciones en la interfaz.

No hace falta agregar módulos nuevos para resolver las mejoras anteriores.

## Qué queda por validar antes de cerrar la fase

- Aceptación manual del dueño: repetir un circuito corto con sus usuarios y configuraciones elegidas, incluida la entrega parcial, un cobro parcial y el pago de comisión.
- Edición interactiva en móvil: teclado, búsquedas de relaciones, asistentes y retorno a listas con filtros. La revisión de capturas cubre distribución, no toda esa interacción.
- Volumen representativo y operación simultánea de varios usuarios reales. Las pruebas de concurrencia existentes cubren casos concretos, no una prueba de carga general.
- Crear un respaldo y restaurarlo en otra base para verificar recuperación antes de usar el sistema con información definitiva.
- Decidir y aplicar las mejoras seleccionadas. La fase no se marca como totalmente terminada solo por superar las pruebas técnicas.

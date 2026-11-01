# Entrega de las fases 1 a 5

## Alcance

Esta entrega estabiliza la consulta del BCV, aclara configuración y permisos, incorpora municipios y permite varias direcciones por contacto, con selección en ventas, compras y operaciones de inventario. No incluye mapas, coordenadas, nuevos atributos de productos ni números de serie.

## Cambios y reglas

| Fase | Resultado | Regla verificable |
| --- | --- | --- |
| 1. BCV | En Windows la consulta utiliza curl del sistema con Schannel y el almacén de certificados de Windows. En otros entornos utiliza PHP/cURL y certificados configurados. | HTTPS permanece validado. Consulta cada 15 minutos, conserva la última publicación válida ante fallos y respeta Fecha Valor: una publicación futura no es la tasa de hoy. |
| 2. Configuración | Monedas explica el maestro único por empresa; Contactos distingue su clasificación Administrador del grupo de acceso. | El tipo Administrador no concede permisos. Principal, alterna y tasas se administran en Contabilidad → Configuración → Monedas; Ajustes enlaza al mismo maestro. |
| 3. Municipios | Contactos → Configuración → Municipios, relacionados con un estado. Las ciudades pueden relacionarse con un municipio del mismo estado. | Los municipios son opcionales en datos anteriores. No se permite asociar un municipio de otro estado ni cambiar arbitrariamente el padre de un catálogo existente. Importación y exportación incorporan municipio. |
| 4. Direcciones | Pestaña Direcciones en la ficha del contacto. La dirección anterior queda como Principal; se agregan otras direcciones con nombre, calle, geografía, código postal y uso. | Cada dirección admite facturación, entrega o ambos usos. Hay una predeterminada por uso. Archivar conserva el registro; una dirección archivada no puede ser predeterminada. Los guardados detectan cambios simultáneos. |
| 5. Documentos | Ventas selecciona facturación y entrega; Compras selecciona la dirección del proveedor. Las operaciones manuales seleccionan la dirección correspondiente; las relacionadas reciben la copia de su orden. | Cambiar el maestro no modifica documentos anteriores. Un borrador conserva su texto hasta seleccionar otra dirección o pulsar Actualizar desde el contacto. Las facturas, notas de crédito, entregas, recepciones, pendientes y devoluciones conservan sus copias históricas. |

Se mantiene la política actual de dos decimales para tasas e importes. Una tasa inversa que redondee a cero se rechaza: requiere una tasa directa representable. La sincronización oficial puede sustituir una tasa manual del mismo par y Fecha Valor, identificando su fuente BCV; no recalcula documentos ya registrados.

## Procesos esperados por módulo

| Módulo | Propósito y flujo esperado |
| --- | --- |
| Contactos | Clasificar personas y empresas, sus documentos, vendedor, geografía y direcciones. Cada dirección pertenece al contacto y empresa correspondientes. Validar relaciones, campos y permisos antes de guardar. |
| Ventas | Crear orden editable, elegir cliente y direcciones, agregar líneas, confirmar y generar entregas o facturas. Confirmar bloquea la edición; entregar y cobrar son pasos separados. |
| Compras | Elegir proveedor, dirección y productos; confirmar; recibir mercancía; registrar la factura del proveedor. La dirección del proveedor no sustituye la ubicación interna de recepción. |
| Inventario | Gestionar existencias por ubicación. Confirmar/reservar/validar son acciones distintas; solo validar mueve mercancía. Las operaciones relacionadas conservan el contacto y la dirección de origen. |
| Facturación | Resolver la tasa histórica por fecha y empresa al registrar. Conservar importes, direcciones y procedencia. Las notas de crédito conservan los datos del documento original. |
| Pagos y Tesorería | Registrar, confirmar y aplicar cobros/pagos según permisos. Conservar moneda, tasa, referencia y comprobantes. Una dirección modificada no altera saldos ni pagos. |
| Comisiones | Calcular por factura y vendedor y habilitar pago según cobros confirmados. Las direcciones no cambian comisiones. |
| Restaurante | Facturar y cobrar usando los servicios de venta, inventario y pagos existentes. Los cambios de contactos mantienen compatible la creación de clientes desde su ventana. |
| Ajustes y usuarios | Asignar grupos, empresas y alcances. Los catálogos de ubicaciones geográficas son compartidos; contactos, direcciones y documentos pertenecen a una empresa. |

## Recorrido para validar en pantalla

1. Revisar el indicador BCV. En Monedas, abrir Tasas y comprobar fecha, fuente y captura manual; no alterar documentos registrados para probarlo.
2. Crear un municipio dentro de un estado en Contactos → Configuración. Crear o editar una ciudad y relacionarla con ese municipio.
3. Abrir un contacto, seleccionar su municipio y guardar. Comprobar que país, estado, municipio y ciudad son compatibles.
4. En Direcciones, agregar una sucursal, completar su dirección y marcarla como Entrega predeterminada. Mantener Principal como Facturación predeterminada. Guardar.
5. Crear una orden de venta y elegir ese cliente. Comprobar que factura y entrega toman las direcciones predeterminadas distintas. Cambiar una selección y revisar el texto mostrado.
6. Guardar la orden. Editar después la dirección del contacto. Volver a la orden: el texto guardado debe mantenerse. En un borrador, pulsar Actualizar direcciones desde el contacto para traer el texto actual.
7. Confirmar una venta y generar su entrega y factura. Revisar las direcciones guardadas, la impresión y los enlaces entre documentos.
8. Archivar la sucursal y elegir otra dirección como predeterminada. Las órdenes nuevas no deben ofrecer la archivada; los documentos anteriores deben conservarla.
9. Repetir el circuito con un proveedor y su factura de compra. La recepción debe mantener la ubicación interna del almacén.
10. Verificar que un usuario limitado no puede seleccionar contactos ni direcciones de otra empresa o fuera de su alcance.

## Instalación y pruebas

`php/install-phases-1-5.php` genera primero un respaldo SQL completo en `php/backups/`. La migración es repetible y conserva los textos de documentos anteriores; crea una dirección Principal por contacto sin inventar municipios. MariaDB confirma DDL de forma independiente: si falla una etapa, conservar el respaldo y resolver el error antes de repetir.

Pruebas específicas: `test/phases-1-5.php`, `test/bcv-rates.php`, `test/sales-customer-wizard.php`. Las regresiones usan `test/run-authenticated.php` para Contactos, Monedas, Ventas, Compras e Inventario, y `test/transfer.php` para importación/exportación. Se ejecutan en bases y cuentas temporales; las tasas de prueba no se insertan en el negocio.

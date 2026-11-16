# Revisión visual de Konta: computadora y teléfono

Revisión del 15 de noviembre de 2026. Cambios aplicados en el proyecto local.

## Qué se revisó

Se prepararon datos aislados con nombres largos, una venta nativa de 13 productos, cantidades, precios e importes grandes. La venta generó su orden, entrega y factura. Se generaron capturas de la parte superior, central e inferior de 17 vistas representativas de los nueve módulos, en computadora y teléfono; se inspeccionaron muestras de esas capturas para detectar problemas que el control automático de tamaños no identifica.

La revisión visual se complementó con verificaciones a 390, 768 y 1440 píxeles, en claro y oscuro. Después de los ajustes se repitió el recorrido automatizado de 110 vistas y variantes a 320, 390 y 430 píxeles.

## Cambios aplicados

1. **Información del registro compacta.** Empresa, creador y fechas se consultan desplegando «Información del registro». Cuando pertenece a otra empresa, la información y la advertencia permanecen abiertas; se conserva el bloqueo de edición.
2. **Nombres completos de productos.** Las órdenes confirmadas muestran el nombre completo en lugar de recortarlo dentro del selector deshabilitado.
3. **Líneas de documentos adaptadas al teléfono.** Las tablas de productos de solo lectura con cantidad y total se presentan como fichas. «Ver detalle» permite consultar precio, base, comisión e impuestos cuando existan. En computadora mantienen su tabla. Las líneas editables conservan sus controles originales.
4. **Acciones de factura compactas.** En móvil se destaca el registro del pago y se distribuyen las demás acciones en dos columnas.
5. **Menos contenido tapado.** Una barra que solo contiene «Volver al listado» deja de quedar fija sobre el documento. Los formularios que permiten guardar mantienen sus acciones accesibles.
6. **Totales con jerarquía clara.** Se destaca el total de factura y se reduce el énfasis de la referencia en otra moneda, conservando todos los importes originales.
7. **Dashboard más compacto.** Los accesos rápidos se organizan en dos columnas en teléfono, acercando los indicadores a la primera pantalla.
8. **Lectura y alineación.** Se ajustaron nombres, cantidades, importes y títulos secundarios, y el enlace para cambiar de cliente en Ventas Directas sigue la paleta de Konta.

## Resultado de las comprobaciones finales

| Comprobación | Resultado |
| --- | --- |
| Revisión representativa | 17 vistas, 102 combinaciones de ancho y tema; sin incidencias detectadas |
| Recorrido móvil completo | 110 vistas y variantes, 660 combinaciones; sin incidencias detectadas |
| Documentos de 13 productos | Fichas y detalles verificados en móvil; tabla conservada en computadora |
| Multiempresa | Auditoría, consulta conjunta y bloqueo de edición entre empresas verificados |
| Sintaxis y diferencias | Comprobaciones PHP y JavaScript correctas; sin errores de formato en las diferencias |

Se conservaron los procesos, permisos, campos originales y cálculos. No se añadieron cambios de esquema. Las cuentas y las operaciones de prueba se crearon en bases aisladas que se eliminaron al finalizar.

## Ejemplos de capturas

- [Dashboard en teléfono](02-390-top.png)
- [Ficha con nombre largo](04-390-top.png)
- [Líneas de orden en teléfono](09-390-middle.png)
- [La misma orden en computadora](09-1440-middle.png)
- [Acciones de factura en teléfono](11-390-top.png)
- [Totales de factura](11-390-bottom.png)

## Repetir la validación

- `php test/mobile-audit.php --visual`: datos de 13 productos, revisión representativa y capturas de computadora y teléfono.
- `php test/mobile-audit.php`: recorrido móvil amplio.
- `php test/multi-company.php`: comprobación de aislamiento y controles multiempresa.

Las pruebas se realizaron en Chrome con emulación de tamaños de pantalla. No cubren todas las combinaciones posibles de datos, estados y permisos. El teclado y los gestos en dispositivos Android y iPhone físicos quedan fuera de esta revisión.

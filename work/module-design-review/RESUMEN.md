# Revisión visual por módulos — Ventas, Inventario y Contabilidad

Fecha: 16 de noviembre de 2026.

Esta etapa se centró en pantallas concretas de los tres módulos acordados. Se inspeccionaron capturas con datos de prueba representativos: nombres largos, una venta de 13 productos, documentos relacionados y saldos de seis cifras. Se conservaron los elementos que ya funcionaban visualmente.

## Hallazgos y cambios

| Módulo | Incomodidad observada | Cambio aplicado |
| --- | --- | --- |
| Ventas | Los listados no resumían qué filtros estaban aplicados. | Etiquetas informativas debajo de los filtros con la búsqueda, el estado y los filtros especiales presentes en la consulta. |
| Inventario | El nombre del producto quedaba después de los indicadores y su explicación, fuera de la primera pantalla del teléfono. | Nombre al inicio de la ficha; disponibilidad separada y explicación de indicadores desplegable. |
| Inventario | El formulario nuevo mostraba indicadores deshabilitados de un producto todavía sin guardar. | Formulario de creación más directo; los indicadores aparecen al existir el producto. |
| Contabilidad | Al abrir las facturas de un cliente en teléfono, las columnas comprimían referencias y fechas hasta partirlas casi letra por letra. | Fichas con referencia, vencimiento y saldo; «Ver detalle» permite consultar los demás importes. La computadora conserva la tabla, con fechas y referencias sin fragmentar. |
| Contabilidad | El saldo principal y los otros importes tenían un peso visual similar. | Moneda identificada y saldo principal destacado; vencido y pagos en proceso quedan diferenciados. |
| Contabilidad | No era evidente dónde abrir el detalle por cliente o proveedor. | Indicación «Ver facturas» y una señal de apertura en cada grupo. |
| Contabilidad | La explicación larga ocupaba espacio antes de los datos. | Fecha de consulta visible y explicación desplegable sobre el cálculo de saldos. |
| Contabilidad | La sección sin deuda aparecía como texto aislado. | Estado vacío contenido y coherente con los paneles de saldos. |

Las etiquetas de filtros son informativas: representan parámetros aplicados de la URL y no modifican la búsqueda, los permisos ni los datos. Los cambios de presentación de saldos conservan las cifras originales.

## Comprobaciones

- 21 vistas y variantes de estos tres módulos.
- 126 combinaciones de tamaño y tema: 390, 768 y 1440 píxeles; claro y oscuro.
- Pestañas y menús comprobados adicionalmente en ancho de teléfono.
- Verificación del nombre antes de los indicadores, filtros visibles, apertura de facturas y acceso al detalle de importes.
- Pruebas nativas de creación y edición de productos aprobadas.
- Pruebas de pagos, saldos, monedas, pagos parciales y excedentes aprobadas.

La revisión representativa terminó sin incidencias en las comprobaciones automatizadas. Las capturas se generaron en una base aislada; no se crearon operaciones de prueba en la empresa real.

## Capturas de referencia

- [Ventas: filtros aplicados en teléfono](02-390-top.png)
- [Producto existente: nombre e indicadores](09-390-top.png)
- [Producto nuevo: formulario simplificado](10-390-top.png)
- [Saldos en computadora](20-1440-top.png)
- [Facturas desplegadas en teléfono, con modo oscuro](accounting-open-balance.png)

## Repetir esta etapa

Ejecutar `php test/mobile-audit.php --modules`.

La inspección se realizó en Chrome con emulación de tamaños de pantalla. Esta etapa cubre Ventas, Inventario y Contabilidad; no equivale a terminar la revisión visual detallada de todos los módulos ni a probar dispositivos físicos.

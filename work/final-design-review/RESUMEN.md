# Cierre de revisión visual: Inicio, Dashboard, Ventas Directas, Restaurante y Ajustes

Fecha: 16 de noviembre de 2026.

El inicio funciona como acceso a módulos y acciones, mientras que el Dashboard presenta el seguimiento del negocio. Se conserva esa separación: permite encontrar operaciones sin mezclar el catálogo de módulos con todas las cifras financieras.

## Ajustes del Dashboard

- Abre en **Resumen del negocio**: ventas y compras por completar, cobros, pagos, vencimientos y pagos sin aplicar. Ingresos y egresos queda en la pestaña siguiente.
- Accesos rápidos agrupados en un desplegable, para reducir el espacio antes de los indicadores.
- Indicadores con cifras más destacadas y tarjetas distribuidas en tres columnas en computadora, dos en ancho intermedio y una en teléfono.
- Pie coherente con la marca Konta, sin el texto anterior de «En construcción».

Se conservan las seis secciones, los vínculos de cada indicador, los permisos y la actualización automática. No se añadieron cifras ilustrativas ni porcentajes sin una fuente de cálculo.

## Alcance de revisión

Inicio, Dashboard, Restaurante (mapa de mesas, atención y configuración), Ajustes (empresa, usuarios y grupos), Ventas Directas como administrador y como vendedor (venta, inventario, solicitudes, historial, devoluciones, cierre y venta realizada). Se usan datos aislados con nombres largos y una venta de 13 productos.

Las capturas del inicio, formularios de usuarios y Ventas Directas conservan la distribución existente: navegación, formularios y acciones mantienen una jerarquía clara. No se realizaron cambios adicionales únicamente para alterar su apariencia.

Resultado: 18 vistas y 108 combinaciones de ancho y tema (390, 768 y 1440 píxeles, claro y oscuro), sin incidencias detectadas en las comprobaciones automatizadas. Pestañas y menús también se comprueban a 320 píxeles. Restaurante se revisó con tres mesas y la pantalla inicial de atención, todavía sin productos en la carta de esta base aislada.

Pruebas del Dashboard aprobadas: datos del resumen, rangos y métodos HTTP; actualización de secciones, errores y recuperación. La revisión visual se repite con `php test/mobile-audit.php --remaining`; resultados en [report.json](report.json).

## Capturas

- [Inicio en computadora](01-1440-top.png)
- [Dashboard en computadora](02-1440-top.png)
- [Dashboard en teléfono](02-390-top.png)
- [Mesas de Restaurante](03-1440-top.png)
- [Atención de una mesa](04-390-top.png)
- [Usuario nuevo](08-390-middle.png)
- [Venta directa en teléfono](12-390-top.png)
- [Detalle de venta y cobros](18-390-bottom.png)

## Validación pendiente en dispositivo físico

Desde este entorno se utiliza Chrome con emulación de tamaños y entrada táctil. Falta probar en un teléfono real: teclado abierto al buscar cliente/producto; desplazamiento y acceso a guardar/confirmar; cámara y selector de archivos; diálogos de nuevo cliente y cobros; navegación inferior y orientación. Conviene completar una venta y un cobro de prueba en un entorno de pruebas, evitando crear operaciones ficticias en la empresa real.

Con la revisión emulada y las etapas anteriores se puede cerrar el trabajo visual de escritorio y tamaños móviles probados. La validación física queda expresamente pendiente.

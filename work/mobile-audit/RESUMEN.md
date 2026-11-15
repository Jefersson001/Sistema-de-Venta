# Validación móvil de Konta

Revisión automatizada realizada el 15 de noviembre de 2026 en Chrome, con emulación de teléfono y entrada táctil.

## Resultado

- 110 vistas y variantes de rutas revisadas con administrador y vendedor.
- 660 comprobaciones: anchos de 320, 390 y 430 píxeles, con temas claro y oscuro.
- 76 aperturas de pestañas y 502 aperturas de menús, comprobadas adicionalmente a 320 píxeles.
- Cero incidencias pendientes en los criterios automatizados de la última ejecución.
- Sin desbordamiento horizontal de página ni errores de JavaScript en el recorrido probado. Las tablas anchas mantienen su desplazamiento dentro de su contenedor.

| Área | Vistas y variantes |
| --- | ---: |
| Inicio y cuenta | 3 |
| Dashboard | 1 |
| Contactos | 18 |
| Inventario | 22 |
| Compras | 4 |
| Ventas | 7 |
| Ventas Directas | 14 |
| Contabilidad | 28 |
| Restaurante | 7 |
| Ajustes | 6 |

## Correcciones aplicadas

- Texto y superficie de entrada más adecuados en la búsqueda del encabezado.
- Texto de los campos de Restaurante a 16 píxeles en móvil para reducir el riesgo de zoom automático al escribir.
- Enlaces de edición con una superficie mínima de 44 por 44 píxeles.
- Menús desplegables que ajustan su posición horizontal al espacio disponible, incluso al redimensionar la pantalla.
- Botón de guardar con texto visible abreviado en pantallas de hasta 360 píxeles; conserva el nombre accesible completo.

## Cobertura y límites

Se recorrieron listados, configuraciones, formularios nuevos, fichas de prueba, documentos de una venta nativa y las vistas autorizadas del vendedor. Los datos incluyen nombres largos y una venta que generó orden, entrega y factura. La base, las cuentas y las operaciones de prueba estuvieron aisladas del negocio y se eliminaron al finalizar.

Los controles se revisan por tamaño, los campos por tamaño de texto y las páginas por desbordamiento y errores de JavaScript. Esto no constituye una certificación de accesibilidad ni una prueba exhaustiva de todas las combinaciones de datos, permisos y estados.

La revisión visual por muestreo complementa las comprobaciones automáticas. Queda por probar en dispositivos físicos Android y iPhone el teclado virtual, los gestos y las particularidades de sus navegadores.

## Repetir la revisión

Ejecutar `php test/mobile-audit.php`. La prueba crea una base aislada, prepara los datos, recorre las vistas y genera `report.json` con el detalle. No requiere cuentas reales ni modifica la base del negocio.

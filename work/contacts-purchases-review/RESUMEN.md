# Revisión visual — Contactos y Compras

Fecha: 16 de noviembre de 2026.

Se revisaron 15 vistas y variantes de Contactos y Compras con datos representativos en una base aislada: clientes, proveedores, vendedores, nombres largos y una orden de compra confirmada con 13 productos.

## Cambios aplicados

- **Contactos:** el aviso sobre el tipo Administrador aparece al seleccionar ese tipo; deja de ocupar espacio en las demás fichas.
- **Direcciones:** paneles y geolocalización secundaria con contraste coherente en modo oscuro; opciones de dirección predeterminada más cómodas para tocar en teléfono.
- **Fotos y documentos:** campos de archivo legibles en ambos temas, filas adaptadas al teléfono y nombre completo del archivo elegido debajo del selector.
- **Pestañas:** al seleccionar una pestaña fuera de la franja visible, esta se desplaza horizontalmente para mostrarla sin mover la página verticalmente.
- **Compras:** Proveedor aparece antes de Tipo de orden. Se conserva el comportamiento de los campos y documentos.
- **Listados:** Contactos y Compras muestran un resumen de los filtros aplicados, con el mismo diseño que Ventas, Inventario y Contabilidad.

## Validación

- 90 combinaciones de tamaño y tema: 15 vistas a 390, 768 y 1440 píxeles, en claro y oscuro, sin incidencias en las comprobaciones automatizadas.
- Pestañas y menús comprobados adicionalmente a 320 píxeles.
- Capturas inspeccionadas de formularios, direcciones, archivos y compras con múltiples productos; en teléfono se mantiene la presentación por fichas y en computadora la tabla.
- Prueba nativa de compras y ventas parciales aprobada, incluyendo recepciones y facturas relacionadas.
- Creación de cliente desde la ventana de ventas aprobada: archivo adjunto guardado, selección automática del cliente, direcciones, conservación de líneas sin guardar, cancelación y presentación móvil.
- Sintaxis PHP y JavaScript y revisión de espacios del cambio aprobadas.
- Revisión móvil general posterior: 110 vistas, 660 combinaciones de ancho y tema (320, 390 y 430 píxeles), 76 pestañas y 502 menús, sin incidencias detectadas en las comprobaciones automatizadas. Resultado en [el informe general](../mobile-audit/report.json).

Las operaciones de prueba se ejecutaron en bases aisladas. Esta etapa no cambia tablas, permisos ni reglas de negocio. Los ajustes de pestañas y geolocalización usan componentes compartidos.

## Capturas de referencia

- [Contacto nuevo en teléfono](03-390-top.png)
- [Direcciones y geolocalización en modo oscuro](contact-4-address.png)
- [Fotos y documentos en modo oscuro](contact-4-files.png)
- [Filtros de compras en teléfono](12-390-top.png)
- [Nueva compra: proveedor primero](13-390-top.png)
- [Compra confirmada en teléfono](14-390-middle.png)
- [Compra confirmada en computadora](14-1440-middle.png)

Para repetir esta revisión: `php test/mobile-audit.php --contacts-purchases`.

Se utilizó Chrome con emulación de tamaños de pantalla. La revisión no sustituye una prueba física del teclado, los gestos y la carga de archivos desde un teléfono. Esta etapa cubre Contactos y Compras; complementa la revisión detallada anterior de Ventas, Inventario y Contabilidad.

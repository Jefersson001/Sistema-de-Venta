# Búsquedas y filtros automáticos

Las listas de contactos, productos, operaciones e informes de inventario, categorías, almacenes, ubicaciones, ventas, compras, facturas, pagos, tesorería, monedas, impuestos, configuraciones contables, usuarios y grupos aplican sus filtros sin botones Buscar, Filtrar o Consultar.

El texto se consulta después de 300 ms sin escribir. Selectores, casillas y radios se aplican al cambiar. Se actualiza el contenido sin recargar la página, conservando foco, selección de texto y filtros. Las peticiones anteriores se cancelan y las respuestas obsoletas se descartan. Se reinicia la paginación al modificar filtros y se mantiene el contexto de sección, tipo de documento y empresa. Los enlaces y el menú de exportación se renuevan con los resultados. Atrás/adelante restaura filtros y resultados. Ante un fallo se conservan los últimos resultados y se muestra el aviso.

`php/live-filters-view.php`, invocado por el renderizador común, reconoce los formularios GET con clase `contact-filters`, elimina sus botones de consulta y les asigna el controlador compartido `js/live-report.js`. Las vistas nuevas con ese contrato reciben el comportamiento automáticamente. No se altera ningún formulario POST ni sus acciones de guardado o confirmación. Los controles de período del dashboard, comisiones e ingresos/egresos conservan su automatización específica; se evita registrar dos controladores sobre un mismo filtro.

La consulta de cuentas por cobrar/pagar utiliza el mismo controlador con un contenedor de resultados específico. En los otros listados se renueva el contenido principal conservando el formulario real. El evento `live-filters:updated` reinicializa las agrupaciones de informes cuando cambia el contenido.

Validación:

- `php test/live-filters.php`: 29 rutas/variantes, botones, búsqueda, sección, scripts y formularios de guardado. Para dividir la ejecución: `php test/live-filters.php 0 10`, `php test/live-filters.php 10 5`, `php test/live-filters.php 15 5`, `php test/live-filters.php 20 10`.
- `node test/live-report-ui.js`: búsqueda, selectores/casillas, sección y paginación, cancelación, respuestas fuera de orden, foco/selección de texto y fallos.
- `php test/outstanding.php`, `php test/role-workflows.php`: informe de saldos y flujos de negocio existentes.

También se corrigió el gráfico de inventario sin coincidencias, que intentaba calcular un máximo sobre una lista vacía y fallaba al aplicar ciertos filtros.

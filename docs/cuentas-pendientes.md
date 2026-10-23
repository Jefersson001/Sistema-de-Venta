# Cuentas por cobrar y por pagar

Acceso: **Contabilidad → Informes → Cuentas por cobrar y por pagar**. El informe presenta primero clientes y después proveedores. También existe una pestaña del mismo nombre en el Dashboard; los indicadores del resumen enlazan al informe.

Son saldos actuales acumulados de las empresas seleccionadas, separados por moneda. No se filtran por el período de ingresos y egresos ni se convierten usando la tasa actual. Se incluyen facturas registradas con saldo positivo, descontando notas de crédito registradas y aplicaciones de pago confirmadas. Se excluyen borradores, cancelaciones, facturas revertidas y documentos completamente pagados. Los pagos en proceso se informan por separado, sin disminuir aún la deuda. Los anticipos sin aplicar no se descuentan automáticamente; siguen disponibles en la consulta existente de pagos sin aplicar.

Cada grupo corresponde a un contacto, empresa y moneda. El informe ofrece totales pendientes, vencidos y en proceso; al desplegar un grupo se ven sus facturas, fechas, vencimientos, importes, notas de crédito, pagos y saldo. Incluye búsqueda por contacto/referencia, filtro de vencidos, paginación de grupos y exportación CSV mediante el menú común Opciones. La exportación incluye todas las facturas pendientes autorizadas que cumplen los filtros, independientemente de la página.

El Dashboard usa los mismos cálculos y se actualiza con la consulta existente de 30 segundos. Muestra únicamente tarjetas con importes por moneda y acceso al informe completo; los contactos y facturas se consultan en el informe. El informe se consulta con los permisos de lectura de facturas de clientes/proveedores existentes. Un usuario sin acceso a proveedores no recibe ese bloque; los permisos propios se aplican a las facturas. En el Dashboard también se exige el permiso de resumen del negocio y su alcance.

No requiere una migración de datos ni nuevos permisos. Los cálculos comunes están en `php/outstanding-repository.php`; informe y dashboard comparten la presentación de `html/partials/outstanding.php`. El modelo de exportación se registra una vez en `php/transfer-models.php`.

La búsqueda del informe se aplica automáticamente al escribir y el vencimiento al cambiar su selección, sin botón Consultar. Se actualizan los resultados sin recargar la página ni perder el foco de escritura. Las consultas anteriores se cancelan para evitar mostrar resultados obsoletos; la exportación conserva los filtros aplicados.

Validación: `php test/outstanding.php`, `php test/run-authenticated.php dashboard`, `node test/dashboard-ui.js` y `php test/role-workflows.php`.

# Compras

Órdenes → Solicitudes de cotización ofrece lista y formulario separados, búsqueda, filtro de estado y paginación. La solicitud tiene correlativo automático P00001, proveedor, fecha, términos de pago, tipo de orden y líneas de productos habilitados para Compra, cantidad, precio, impuesto de Compras e importes sin impuestos. Los estados son Solicitud de cotización, Solicitud enviada y Orden de compra. Marcar como enviada solo cambia el estado local. Confirmar requiere productos y bloquea la edición.

Orden de recepción requiere una ubicación interna de destino. Desde la orden confirmada, Recepción genera un único borrador con el proveedor, su ubicación externa, el destino y las líneas; las cantidades compradas pasan a Demanda y Cantidad comienza en cero. Validar la recepción modifica existencias y habilita Factura en la orden de compra. El tipo Factura omite la recepción y permite facturar directamente tras confirmar.

Factura crea un único borrador de proveedor en Contabilidad y conserva los datos y líneas de la orden. Los botones muestran Recepción · 1 y Factura · 1 cuando existen. La orden muestra Recepción y Factura; la recepción muestra solo Orden de compra; la factura muestra solo Orden de recepción, o la orden de origen cuando es una factura directa. Los guardados verifican versión y las creaciones bloquean la orden para evitar duplicados concurrentes.

El indicador Comprado consulta líneas de órdenes de compra confirmadas (`purchase_orders` y `purchase_order_lines`), suma unidades confirmadas en los últimos 12 meses y abre el historial completo de compras confirmadas. El historial tiene lista, agrupaciones, tabla dinámica y gráfico; `orden.php` conserva el acceso al formulario. Las recepciones manuales no se consideran compras.

Después de instalar Contactos, Inventario, impuestos, términos de pago y Ventas, ejecuta `php/install-commerce.php` con el ejecutable de PHP. Verifica el recorrido con `test/commerce-workflows.php`.

Configuración → Impuestos permite crear y editar impuestos de compra con un único campo visible: Nombre. El listado con búsqueda y paginación y el formulario son vistas separadas. Los impuestos de compra se pueden seleccionar en Información general de los productos de Inventario.

Los impuestos de Compras y Ventas utilizan registros diferenciados por módulo. No se pueden seleccionar impuestos de Ventas como impuestos de compra. Los catálogos comienzan vacíos y esta etapa no incluye tasas ni cálculos.

Para instalar únicamente los catálogos de impuestos en otra base:

```powershell
& C:\xampp\php\php.exe php/install-taxes.php
```

La instalación de Inventario también crea los catálogos y las relaciones de productos necesarias.

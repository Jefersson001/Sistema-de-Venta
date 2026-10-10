# Compras

El indicador Comprado del producto consulta líneas de órdenes de compra confirmadas (`purchase_orders` y `purchase_order_lines`), suma unidades confirmadas en los últimos 12 meses y abre el historial completo de compras confirmadas del producto. El historial tiene lista, agrupaciones, tabla dinámica y gráfico; la referencia abre una consulta de la orden en `orden.php`. Las tablas se instalan desde `php/install-inventory.php`. El formulario para crear y confirmar órdenes de compra se desarrollará en la siguiente etapa. Las recepciones manuales no se consideran compras.

Configuración → Impuestos permite crear y editar impuestos de compra con un único campo visible: Nombre. El listado con búsqueda y paginación y el formulario son vistas separadas. Los impuestos de compra se pueden seleccionar en Información general de los productos de Inventario.

Los impuestos de Compras y Ventas utilizan registros diferenciados por módulo. No se pueden seleccionar impuestos de Ventas como impuestos de compra. Los catálogos comienzan vacíos y esta etapa no incluye tasas ni cálculos.

Para instalar únicamente los catálogos de impuestos en otra base:

```powershell
& C:\xampp\php\php.exe php/install-taxes.php
```

La instalación de Inventario también crea los catálogos y las relaciones de productos necesarias.

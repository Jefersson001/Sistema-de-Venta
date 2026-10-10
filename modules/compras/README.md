# Compras

Configuración → Impuestos permite crear y editar impuestos de compra con un único campo visible: Nombre. El listado con búsqueda y paginación y el formulario son vistas separadas. Los impuestos de compra se pueden seleccionar en Información general de los productos de Inventario.

Los impuestos de Compras y Ventas utilizan registros diferenciados por módulo. No se pueden seleccionar impuestos de Ventas como impuestos de compra. Los catálogos comienzan vacíos y esta etapa no incluye tasas ni cálculos.

Para instalar únicamente los catálogos de impuestos en otra base:

```powershell
& C:\xampp\php\php.exe php/install-taxes.php
```

La instalación de Inventario también crea los catálogos y las relaciones de productos necesarias.

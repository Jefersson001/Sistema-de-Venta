# Inventario

La vista principal abre Productos en un listado con búsqueda y paginación. «Nuevo producto» o un registro abre un formulario separado con nombre y casillas independientes Venta y Compra. Ambas casillas comienzan desmarcadas. El formulario tiene seis pestañas, en este orden: Información general, Variantes, Ventas, Compras, Inventario y Contabilidad. Los datos se guardan en MySQL.

Información general incluye precio de venta, impuesto de venta, impuesto de compra y categoría. El precio se almacena como decimal con dos posiciones, comienza en 0.00 y acepta punto o coma decimal. Las relaciones son opcionales; cada impuesto pertenece al módulo correspondiente, Ventas o Compras, y la categoría usa el catálogo de Inventario. Los catálogos de impuestos se administran en Configuración de Compras y Ventas, con solo el nombre. Las demás pestañas siguen vacías.

Configuración → Productos → Categorías abre el listado de categorías. «Nueva categoría» o «Editar» abre un formulario independiente con nombre y categoría superior opcional. Guardar vuelve al listado. Los datos se almacenan en MySQL y el catálogo empieza vacío.

Configuración → Gestión de almacenes incluye Almacenes y Ubicaciones, cada uno con vistas de listado y formulario separadas, búsqueda y paginación. Los almacenes tienen nombre. Las ubicaciones tienen nombre, almacén obligatorio y ubicación superior opcional para organizar sububicaciones dentro del mismo almacén. Se impiden ciclos y duplicados dentro de la misma ubicación superior; una ubicación con sububicaciones no puede cambiar de almacén. Los catálogos comienzan vacíos.

Las categorías pueden organizarse en subcategorías. El sistema impide relaciones circulares y nombres duplicados dentro de la misma categoría superior. El listado admite búsqueda por nombre o ruta y paginación de 20 registros.

La migración ya se ejecutó en la base local. Para instalar esta sección en otro entorno, ejecuta desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe php/install-inventory.php
```

Para verificar creación, edición y jerarquía:

```powershell
& C:\xampp\php\php.exe test/inventory-categories.php
& C:\xampp\php\php.exe test/inventory-products.php
& C:\xampp\php\php.exe test/product-taxes.php
& C:\xampp\php\php.exe test/inventory-storage.php
```

La prueba utiliza registros identificados y los elimina al terminar. Requiere Apache y MySQL en ejecución.

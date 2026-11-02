# Variantes de productos

En **Inventario → Productos** se despliegan **Productos** y **Variantes del producto**.

1. Crea o abre el producto principal, por ejemplo **Torta**.
2. En su pestaña **Variantes**, pulsa **Nueva variante**. También puedes entrar desde el listado general de variantes.
3. Se abre la misma ficha del producto, con sus indicadores, campos y pestañas, cargada con los datos del principal. Cambia el nombre por **Torta de chocolate** o **Torta de fresa** y guarda. No necesitas atributos, sabores predefinidos ni combinaciones de talla/color.
4. Puedes modificar precio, impuestos, categoría, moneda, uso de venta/compra y demás datos desde esa misma ficha, según tus permisos. Si solo cambias el nombre, conserva los valores cargados del principal. Al elegir otro principal antes de guardar, se recargan sus datos.

La variante toma inicialmente del principal categoría, impuestos, moneda, tipo, permisos de venta/compra, costo, comisión y precios por proveedor. Los cambios posteriores del principal no reemplazan los valores de variantes ya creadas. El nombre escrito es el que aparece en compras, ventas, entregas, facturas y Restaurante. La categoría heredada permite utilizarla en Restaurante cuando su categoría principal esté habilitada para ese módulo.

Cada variante tiene su propio registro del catálogo e inventario y comienza sin existencias. Al tener variantes, el principal funciona como **plantilla**: las compras, ventas, recepciones, entregas, traslados, importaciones de operaciones y pedidos de Restaurante nuevos deben utilizar un producto concreto. Un producto sin variantes mantiene su comportamiento normal. La plantilla conserva opciones y precios como valores iniciales para crear variantes; no se selecciona como artículo nuevo en las operaciones.

La ficha principal suma existencias, reservas, disponibilidad e indicadores comerciales de sus variantes. Su pestaña Variantes muestra el desglose de cantidades. Los enlaces a informes y sus exportaciones consultan la misma familia sin insertar saldos adicionales ni duplicar cantidades. Por ejemplo, 10 tortas de fresa y 10 de chocolate muestran 20 en el principal; vender 3 de fresa deja 7 de fresa, 10 de chocolate y un total de 17.

Crear variantes no mueve existencias anteriores del principal ni supone a qué sabor corresponden. Si existen saldos o reservas anteriores, la ficha los muestra separados del total de variantes y ofrece consultar el registro original mediante `exact=1`. Los documentos confirmados anteriores conservan sus referencias y pueden completar sus movimientos relacionados o devoluciones con el producto original. Los borradores comerciales y movimientos manuales no confirmados deben corregirse para seleccionar una variante antes de continuar; pueden cancelarse para liberar reservas. No se reescribe ningún documento histórico. Las alertas de falta de existencias del Dashboard se calculan sobre productos concretos y omiten las plantillas.

El listado **Productos** muestra los principales y un enlace a sus variantes. El listado **Variantes del producto** permite buscar por nombre y filtrar por principal. Editar una variante abre la misma ficha de producto, con sus indicadores y el vínculo al principal. La relación con el principal se conserva al editar; no se admiten variantes de variantes ni dos variantes del mismo nombre dentro de un principal. No se ofrece eliminación de registros con historial.

## Instalación

La base local ya está actualizada. Para actualizar otra instalación, desplegar en mantenimiento y ejecutar con PHP CLI:

```powershell
& C:\xampp\php\php.exe php/install-product-variants.php
```

El instalador hace un respaldo completo en `php/backups/before-product-variants-*.sql` antes de modificar el esquema. Usa `C:/xampp/mysql/bin/mysqldump.exe`; ajustar esa ruta para otra distribución. Si el respaldo falla, no ejecuta la migración. Añade `inventory_products.parent_product_id`, una clave foránea al mismo catálogo y un índice único por empresa, principal y nombre. Todos los productos existentes quedan como principales; no modifica precios ni existencias y no inserta ejemplos. Actualiza las restricciones multiempresa mediante el mecanismo actual del proyecto.

Los permisos son los de **Productos**: consultar, crear, editar, ver costos y configurar comisión. La ficha compartida respeta los mismos permisos: quienes no pueden ver costos o configurar comisión conservan los valores del principal al crear la variante. Los productos de otra empresa no pueden usarse como principales. El campo **Producto principal (ID)** también se incorpora a importación/exportación; actualizar un producto sin enviar ese campo conserva su relación actual.

El esquema puede retirarse con `--rollback-empty` únicamente si no hay ninguna variante en ninguna empresa, y debe acompañarse de la reversión del código que lo utiliza. Con variantes reales, conservar la relación y utilizar el respaldo si hace falta recuperar la instalación.

## Verificación

- `php test/variant-templates.php`: totales de familia, reservas, ventas/devoluciones, bloqueo de plantillas en operaciones nuevas, documentos anteriores, informes/exportaciones y navegador en escritorio y móvil.
- `php test/product-variants.php`: migración repetible, creación por nombre, valores iniciales, duplicados, relaciones, aislamiento de empresas, permisos, inventario independiente y venta/factura/cobro mediante Restaurante.
- `php test/run-authenticated.php inventory-products`: regresión de productos existentes en una base temporal.
- `php test/transfer.php`: importación/exportación y operaciones atómicas.
- Con un navegador temporal CDP en el puerto 9340: `php test/product-variants.php <carpeta-de-capturas>` verifica las vistas en escritorio y móvil.

Las pruebas usan bases y usuarios temporales. No crean tortas ni existencias de ejemplo en los datos del negocio.

La corrección de plantillas utiliza las relaciones de variantes existentes y no requiere otra migración ni modifica las existencias del negocio. La creación por nombres completos se conserva; no se incorpora generación de combinaciones por atributos en esta entrega.

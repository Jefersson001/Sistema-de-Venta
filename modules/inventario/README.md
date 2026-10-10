# Inventario

Configuración → Productos → Categorías abre el listado de categorías. «Nueva categoría» o «Editar» abre un formulario independiente con nombre y categoría superior opcional. Guardar vuelve al listado. Los datos se almacenan en MySQL y el catálogo empieza vacío.

Las categorías pueden organizarse en subcategorías. El sistema impide relaciones circulares y nombres duplicados dentro de la misma categoría superior. El listado admite búsqueda por nombre o ruta y paginación de 20 registros.

La migración ya se ejecutó en la base local. Para instalar esta sección en otro entorno, ejecuta desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe php/install-inventory.php
```

Para verificar creación, edición y jerarquía:

```powershell
& C:\xampp\php\php.exe test/inventory-categories.php
```

La prueba utiliza registros identificados y los elimina al terminar. Requiere Apache y MySQL en ejecución.

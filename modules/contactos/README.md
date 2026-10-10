# Contactos

El módulo incluye listado con búsqueda, filtros por tipo y estado, paginación de 20 registros, formulario de creación/edición y archivo/restauración.

Configuración abre un menú de catálogos. Cada catálogo abre primero su listado; «Nuevo» y «Editar» abren una vista de formulario independiente. Guardar o volver al listado regresa a la lista del catálogo.

El campo Documento combina un selector de letra con el número. Configuración → Tipos de documento permite crear letras y editar sus descripciones. Las letras existentes se conservan para no cambiar la identificación de contactos relacionados. Los tipos iniciales son V (Venezolano), E (Extranjero), J (Jurídico), P (Pasaporte), G (Gubernamental) y C (Comuna o consejo comunal). El documento es opcional; si se registra, se debe elegir su tipo.

Los tipos iniciales son Administrador, Empleado, Cliente y Vendedor. Se pueden crear y editar desde Configuración. Clasifican contactos; no son roles de acceso. Cada contacto tiene un tipo y se distingue entre persona y empresa.

El formulario incluye las pestañas Ventas y compras, Contabilidad, Inventario y Notas internas. Ventas y compras permite asignar un contacto Vendedor a un Cliente. El campo se oculta para otros tipos y su asignación se elimina si el cliente cambia de tipo. Las notas se conservan en su pestaña; Contabilidad e Inventario quedan preparados para sus próximos campos. Las pestañas admiten flechas, Inicio y Fin del teclado.

Solo se ofrecen vendedores activos para nuevas asignaciones. Un vendedor archivado que ya está asignado se conserva y aparece identificado. Un vendedor con clientes asignados no puede cambiar a otro tipo hasta reasignar sus clientes. La clasificación interna de los tipos iniciales se conserva aunque se cambie su nombre visible.

En Configuración → Localización se administran países, estados y ciudades. Los catálogos comienzan vacíos. Cada estado pertenece a un país y cada ciudad a un estado. Se permite cambiar sus nombres, pero no trasladar registros existentes a otra localización superior para conservar las relaciones. La localización del contacto es opcional.

## Instalación

La instalación ya se ejecutó en la base local. Para instalar el módulo en otro entorno, configura `php/config/database.php` y ejecuta desde la raíz del proyecto:

```powershell
& C:\xampp\php\php.exe php/install-contacts.php
```

La migración crea las tablas si no existen y agrega los tipos iniciales que falten. No se ejecuta en cada visita. No repitas la instalación para actualizar catálogos: utiliza Configuración.

Para agregar únicamente los tipos de documento a una instalación anterior:

```powershell
& C:\xampp\php\php.exe php/install-contacts.php --documents-only
```

Esta actualización conserva los documentos existentes y añade su tipo como campo opcional. Al editar un contacto con documento, selecciona la letra correspondiente. No se asignan letras automáticamente a los datos anteriores.

Para actualizar una instalación existente con la asignación de vendedores:

```powershell
& C:\xampp\php\php.exe php/install-contacts.php --salesperson-only
```

Referencias de las letras iniciales: [manual técnico del SENIAT (copia)](https://ritaferrer73.wordpress.com/wp-content/uploads/2014/09/seniat-manual-tc3a9cnico-sistema-islr-retenciones-islrv3-0_2014.pdf), [registro comunal con RIF C alojado por UNESCO](https://whc.unesco.org/document/224543).

## Verificación

```powershell
& C:\xampp\php\php.exe test/contacts.php
& C:\xampp\php\php.exe test/contacts-http.php
node test/contact-notebook.js
```

La primera prueba usa una transacción y revierte los registros creados al terminar. La segunda necesita Apache en ejecución; verifica el recorrido por HTTP y elimina sus registros de prueba al finalizar. Acepta una URL base como argumento si el proyecto está instalado en otra ruta. Los formularios utilizan tokens de sesión y consultas preparadas.

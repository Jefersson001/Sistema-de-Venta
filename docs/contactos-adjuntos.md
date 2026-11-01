# Fotos y documentos de contactos

La ficha de Contactos incluye Fotos y documentos después de Inventario. Cada línea nueva contiene un archivo y una descripción opcional. Agregar archivo y Quitar línea modifican el formulario; Guardar contacto conserva las líneas. Los archivos existentes ofrecen Abrir, Descargar y Quitar al guardar. Desmarcar Quitar al guardar conserva el archivo.

Se admiten imágenes JPG, PNG, WEBP y GIF, documentos PDF, Word (DOC/DOCX), Excel (XLS/XLSX) y texto TXT. El servidor comprueba extensión, contenido, tamaños y errores de recepción. Cada archivo admite 10 MB; cada guardado admite hasta 20 archivos nuevos y 20 MB en total. Los archivos guardados anteriormente permanecen relacionados con el contacto. Si el formulario contiene errores, el navegador exige volver a seleccionar los archivos nuevos.

La funcionalidad también está disponible en la ventana Crear cliente de Ventas. Guardar el cliente conserva los adjuntos antes de notificar a la orden.

## Instalación

Con la base multiempresa instalada:

```powershell
C:\xampp\php\php.exe php/install-contact-attachments.php
```

El instalador crea `contact_attachments`, registra su aislamiento por empresa y sus triggers de auditoría. Puede repetirse y no modifica los contactos existentes. No se realizan migraciones al abrir una ficha.

Los contenidos están en `php/uploads/contacts/` con claves aleatorias; los nombres originales solo son metadatos. Apache bloquea el acceso directo. `modules/contactos/archivo.php` verifica sesión, permiso de lectura, contacto, empresa y relación del archivo. Las descargas envían cabeceras para impedir caché y detección automática del tipo de contenido. Los tipos que no son imágenes o PDF se descargan.

Los cambios del contacto y los metadatos de sus archivos se confirman en una transacción. Si falla el guardado se revierten los datos y se eliminan los archivos nuevos; los archivos retirados se eliminan del almacenamiento después de confirmar. Respaldar tanto la base de datos como `php/uploads/contacts/`. No incluir contenidos de esa carpeta en Git. El borrado completo de contactos o empresas puede dejar contenidos privados sin referencia; la limpieza de estos contenidos no se ejecuta automáticamente.

## Pruebas

```powershell
C:\xampp\php\php.exe test/contact-attachments.php
C:\xampp\php\php.exe test/sales-customer-wizard.php
```

Las pruebas utilizan una base, sesiones y almacenamiento temporal propios, sin adjuntar archivos a contactos reales.

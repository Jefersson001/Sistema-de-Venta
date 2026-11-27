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

`NEXO_CONTACT_UPLOAD_DIR` permite utilizar una carpeta privada fuera de la raíz web. En Dokploy, el arranque prepara sus permisos para el usuario de PHP-FPM; configurar un volumen para conservarla al desplegar. Ver [Archivos adjuntos y persistencia](actualizaciones-dokploy.md#archivos-adjuntos-y-persistencia). Un fallo de creación o escritura devuelve un error controlado y revierte el guardado.

Los cambios del contacto y los metadatos de sus archivos se confirman en una transacción. Si falla el guardado se revierten los datos y se eliminan los archivos nuevos; los archivos retirados se eliminan del almacenamiento después de confirmar. Respaldar tanto la base de datos como `php/uploads/contacts/`. No incluir contenidos de esa carpeta en Git. El borrado completo de contactos o empresas puede dejar contenidos privados sin referencia; la limpieza de estos contenidos no se ejecuta automáticamente.

## Error 413 al guardar desde el teléfono

El aviso `413 Request Entity Too Large` de Nginx indica que el servidor rechazó el tamaño de la solicitud antes de ejecutar PHP. No es un error de los datos del cliente. Una foto tomada con el teléfono puede superar el límite predeterminado de Nginx de 1 MB aunque respete el límite del formulario.

El arranque en Dokploy ejecuta `php/prepare-upload-request-limits.php` sobre la configuración final de Nginx y el pool `[www]` de PHP-FPM, antes de iniciar ambos procesos. Aplica un máximo de 32 MB por solicitud, 10 MB por archivo y 20 archivos. Los validadores de la aplicación conservan el máximo de 20 MB de archivos nuevos por guardado; los 32 MB dan margen al formulario multipart. La configuración es repetible y conserva el enrutamiento, la identidad del proceso y los demás parámetros. También se aplica con `AUTO_MIGRATE=0` y con una configuración Nginx propia.

Subir el código y ejecutar **Deploy** en Dokploy. No basta con cambiar variables en un contenedor que aún ejecuta el código anterior. Volver a probar la creación de cliente adjuntando una imagen mayor de 1 MB y menor de 10 MB. En los registros de arranque debe aparecer `Cargas: Nginx/PHP 32 MB por solicitud` y la comprobación correcta de Nginx.

Si después del despliegue persiste el 413, revisar el proxy que esté delante del contenedor: el límite de cada capa debe aceptar al menos 32 MB. Una configuración externa no puede corregirse desde el formulario ni modificando únicamente PHP. Esta revisión no confirma cambios en el VPS sin ejecutar allí el nuevo despliegue.

Referencias: [Nginx client_max_body_size](https://nginx.org/en/docs/http/ngx_http_core_module.html#client_max_body_size), [límites de carga de PHP](https://www.php.net/manual/en/ini.core.php#ini.post-max-size).

## Pruebas

```powershell
C:\xampp\php\php.exe test/contact-attachments.php
C:\xampp\php\php.exe test/sales-customer-wizard.php
C:\xampp\php\php.exe test/upload-request-limits.php
node test/deploy-startup.js
```

Las pruebas utilizan una base, sesiones y almacenamiento temporal propios, sin adjuntar archivos a contactos reales.

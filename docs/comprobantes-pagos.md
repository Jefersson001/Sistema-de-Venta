# Comprobantes de pago

Los formularios de pagos de clientes y proveedores, Registro de pago desde facturas, Tesorería, pagos de comisiones y cobro del restaurante permiten adjuntar fotos o PDF. El bloque Comprobante de pago es opcional y se presenta junto a la referencia. La referencia escrita y el archivo se conservan por separado.

Se admiten JPG, JPEG, PNG, WEBP, GIF y PDF, hasta 10 MB por archivo, 20 archivos y 20 MB de archivos nuevos por solicitud. El servidor verifica extensión y contenido. En restaurante cada pago de la división tiene su propio archivo. Los archivos se asocian al pago o egreso real, antes de confirmar la transacción financiera. Si falla la operación se revierten los metadatos y se retiran los archivos nuevos.

La ficha del pago o movimiento ofrece Abrir y Descargar. Las facturas también muestran los comprobantes de sus pagos relacionados; los pagos de comisiones los muestran en su propia ficha. Los archivos se conservan tras cancelaciones o reversiones. Adjuntar otro comprobante permite completar un pago ya registrado sin cambiar importes, estado ni saldos; exige permiso de edición del pago o movimiento. No se permite borrar comprobantes desde estas pantallas.

## Instalación

```powershell
C:\xampp\php\php.exe php/install-payment-proofs.php
```

La migración crea y registra la tabla `payment_proofs` con aislamiento y auditoría por empresa. No modifica movimientos existentes. Los contenidos se guardan en `php/uploads/payment-proofs/`, carpeta privada excluida de Git. Las consultas pasan por las páginas autenticadas del pago, factura o comisión y comprueban la relación con el documento solicitado. Respaldar la base de datos y la carpeta de archivos.

Si un formulario contiene errores, el navegador requiere volver a seleccionar el comprobante. Reenviar la clave de creación de un pago conserva el pago original; adjuntar nuevamente un archivo de contenido idéntico al mismo movimiento no crea otra copia.

## Validación

```powershell
C:\xampp\php\php.exe test/payment-proofs.php
C:\xampp\php\php.exe test/restaurant-pos.php
```

La prueba de comprobantes usa una base, cuentas y almacenamiento temporales; prueba cargas y descargas, pagos desde facturas, Tesorería, adjuntos posteriores a la confirmación, rechazo de archivos falsos, CSRF, acceso a documentos ajenos, reintentos y la presentación móvil. Ejecuta también las pruebas existentes de pagos, Tesorería y comisiones con una tasa histórica de prueba.

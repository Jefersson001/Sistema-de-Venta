# Tasa BCV y referencia de facturas

La moneda principal y la alterna se configuran por empresa. Con USD principal y VES alterna, la tasa significa **1 USD = X VES**. Los precios y los totales originales siguen en la moneda del documento; se muestra además el equivalente del total en la otra moneda.

El encabezado muestra la tasa vigente de la empresa principal, su fuente y su fecha valor. Si corresponde a hoy dice «Tasa del día»; si proviene de una publicación anterior dice «Tasa vigente». Se consulta el estado guardado cada minuto, sin bloquear la navegación con peticiones al BCV. El indicador distingue una consulta fallida y no presenta datos antiguos como recién publicados.

`php/update-bcv.php` consulta exclusivamente `https://www.bcv.org.ve/`, con validación HTTPS. Lee USD y la **Fecha Valor** de la publicación. No toma la fecha de descarga como vigencia. Guarda la publicación, incluso futura, en el historial de cada empresa que tenga USD y VES; respeta las monedas principal/alterna invertidas. Actualiza la tasa vigente de configuración y no modifica documentos. Si existe una tasa para ese mismo par y fecha, la publicación oficial la actualiza; reintentos no duplican registros. Las ediciones manuales se identifican como Manual hasta que una nueva sincronización oficial para esa misma fecha las sustituya.

Si falla la consulta o cambia el formato del sitio, conserva la última publicación válida y registra el error. No utiliza un proveedor alternativo silenciosamente ni deshabilita TLS. La primera instalación agrega únicamente columnas de procedencia a las tasas y de fecha/fuente a las facturas. Los documentos anteriores mantienen sus importes y tasa; donde no existía procedencia se muestra «Tasa guardada».

## Fecha de factura

Una factura nueva consulta la tasa histórica de su fecha, por su propia empresa, independientemente de la tasa de la orden. Las fechas sin publicación propia utilizan la última tasa anterior disponible, como ocurre en fines de semana. Nunca usan una tasa futura.

Cambiar la fecha de un borrador consulta una vista previa y guardar/registrar vuelve a resolver la tasa en el servidor. La factura almacena `exchange_rate`, `exchange_rate_date` y `exchange_rate_source`. Registrar exige una tasa disponible. Una factura registrada conserva el valor guardado; las actualizaciones posteriores del BCV no la recalculan. Las notas de crédito conservan la valoración de la factura original. La importación de fechas de facturas usa el mismo cálculo.

La referencia en bolívares del documento no fija por sí misma la tasa de un pago posterior. Los pagos conservan su propia moneda y tasa, según el funcionamiento existente.

## Instalación y tarea

1. Respaldar la base y ejecutar `C:\xampp\php\php.exe php/install-bcv.php` (migración repetible).
2. Ejecutar `C:\xampp\php\php.exe php/update-bcv.php --force` para la primera consulta.
3. Ejecutar `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/install-bcv-task.ps1`.

La tarea `Nexo-BCV-project` usa `php-win.exe`, no abre ventanas y repite cada 15 minutos. La instalación normal usa el usuario actual: requiere sesión de Windows iniciada y MariaDB disponible, aunque el navegador esté cerrado. Para ejecutar también sin sesión, un administrador puede instalarla con `-AsSystem`. Los archivos del sistema deben quedar protegidos contra modificaciones de usuarios sin confianza si se ejecuta como servicio.

El instalador exporta los certificados raíz de confianza de Windows a `php/runtime/bcv-ca.pem` para PHP/OpenSSL. No cambia la configuración TLS global. También se puede indicar un archivo CA de confianza con `NEXO_BCV_CA_FILE`.

El estado de sincronización y el bloqueo están en `php/runtime/`, protegido por `.htaccess`, excluidos de Git y separados por base de datos. El trabajo evita ejecuciones simultáneas y limita los reintentos a 15 minutos. En otro servidor hay que configurar su tarea/cron y su confianza HTTPS. No se dispone de descarga retroactiva de históricos: las fechas anteriores necesitan tasas registradas en el catálogo.

## Validación

- `test/bcv-rates.php`: formato oficial, Fecha Valor, futuras, inversas, empresas, monedas invertidas, reintentos y fallos.
- `test/role-workflows.php`: factura independiente de la orden, fecha histórica, referencia, falta de tasa, permisos y congelación tras registrar, además del circuito habitual de ventas/compras/pagos.
- `test/run-authenticated.php currency-catalog dashboard`: catálogo, historial y vistas existentes.
- `test/bcv-live-check.php`: comprobación opcional de conexión real al BCV, sin escribir tasas.

La referencia consultada de Odoo 19 es su implementación oficial: `addons/account/models/account_move.py`, métodos `_get_invoice_currency_rate_date` y `_compute_invoice_currency_rate`. La fecha de factura es la referencia para el cálculo; la moneda de empresa y la del documento son conceptos distintos.

# Dos decimales

Cantidades, existencias, precios, costos, importes, porcentajes, comisiones y tasas de cambio se guardan y presentan con dos decimales. Los identificadores, fechas y contadores conservan su tipo entero.

Los formularios y la importación rechazan entradas con más de dos decimales. La publicación externa del BCV puede traer más dígitos: se redondea a dos antes de guardarla. El historial sigue separado por empresa y fecha. Las operaciones matemáticas pueden conservar dígitos intermedios para evitar truncar antes del redondeo final.

El redondeo es al más cercano, con las mitades hacia afuera de cero: `1.005 → 1.01`, `-1.005 → -1.01`. Las impresiones usan cadenas decimales exactas, sin convertir los valores a `float`.

Una tasa inversa se presenta y usa con dos decimales. Esto reduce su precisión; una inversa que redondee a cero se rechaza y requiere una tasa directa representable con dos decimales.

## Actualización de una instalación existente

Ejecutar desde la raíz:

```powershell
C:\xampp\php\php.exe php/install-two-decimals.php
```

El instalador crea primero un respaldo SQL completo en `php/backups/before-two-decimals-*.sql`, carpeta privada excluida de Git. Después actualiza las columnas `DECIMAL` conservando su capacidad entera, nulabilidad y valores predeterminados. Las tasas y otras fracciones antiguas se redondean; los importes ya guardados con dos decimales no se recalculan. La migración no se ejecuta automáticamente al abrir una página y puede repetirse sin volver a modificar columnas ya migradas. MariaDB confirma cada `ALTER TABLE` de forma independiente; conservar el respaldo hasta verificar la instalación.

Se comprueba antes de modificar el esquema que ninguna cantidad, demanda o tasa distinta de cero quede en cero por el redondeo. En la instalación actual las cantidades existentes no necesitaron redondeo: se modificó la representación de 23 columnas y se redondearon ocho valores de tasas.

## Verificación

```powershell
C:\xampp\php\php.exe test/two-decimals.php
node test/two-decimals-ui.js
node test/currencies.js
node test/sales-ui.js
node test/product-commissions-ui.js
C:\xampp\php\php.exe test/bcv-rates.php
C:\xampp\php\php.exe test/role-workflows.php
C:\xampp\php\php.exe test/restaurant-pos.php
C:\xampp\php\php.exe test/transfer.php
C:\xampp\php\php.exe test/run-authenticated.php currency-catalog sales-quotations inventory-operations sales-commissions commerce-partials
```

La prueba de migración usa una base temporal propia; comprueba redondeos positivos y negativos, conservación de importes, valores predeterminados, valores nulos y repetición sin cambios.

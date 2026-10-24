# Correcciones de productos y cobro de restaurante

## Comportamiento

- Los comprobantes del restaurante conservan nombre y documento del cliente, y los datos de identificación de la empresa al facturar. Editar esas fichas después no modifica la identidad impresa.
- Al guardar un producto o variante se verifica que sus datos y precios de proveedor sigan iguales a los que se cargaron al abrir la ficha. Si cambiaron, se responde con un conflicto (HTTP 409), se conservan los campos escritos y se requiere recargar para revisar la versión actual. Las existencias se gestionan por separado.
- El reparto de pagos se envía en `payments_json`, un único campo. Así, 20 personas con 100 artículos no superan el límite de campos de PHP. El servidor sigue validando cantidades, importes, permisos e idempotencia; también acepta el formato anterior.
- Las fichas de productos avisan al salir con cambios pendientes. Cambiar el principal de una variante nueva pide confirmación cuando hay otros campos modificados; cancelar conserva la ficha.
- Los precios de proveedor escritos se conservan al corregir un error de validación.

## Instalación

En esta instalación local ya se ejecutó la actualización. Para actualizar otra instalación XAMPP, copiar el código y ejecutar desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe php/install-review-fixes.php
```

El instalador crea primero un respaldo completo en `php/backups/before-review-fixes-*.sql`, protegido del acceso web e ignorado por Git. Si falla el respaldo, no modifica el esquema. Después agrega `restaurant_account_carts.receipt_snapshot` y completa los comprobantes antiguos que no tengan una copia de su identidad. Puede ejecutarse nuevamente.

En comprobantes anteriores a esta actualización se conserva la identidad disponible al migrar (`source: legacy_upgrade`); no se puede recuperar automáticamente una identidad que ya había sido editada. Los nuevos guardan `source: issued`.

Para revertir, conservar un respaldo del estado actual y restaurar el respaldo previo junto con la versión anterior del código. La columna no debe eliminarse para una simple reversión de código: contiene información histórica y su presencia es compatible con la versión anterior.

## Verificación

Las pruebas PHP crean bases temporales independientes del negocio:

```powershell
node test/review-fixes-ui.js
C:\xampp\php\php.exe test/product-variants.php
C:\xampp\php\php.exe test/restaurant-pos.php
C:\xampp\php\php.exe test/run-authenticated.php inventory-products commerce-partials
```

Incluyen conflicto entre dos fichas, conservación de proveedores tras validación, identidad del comprobante tras editar cliente y empresa, reintento de cobro con JSON, y serialización de 20 pagos con 100 artículos por persona. El servidor conserva las pruebas existentes de inventario, empresas y permisos.

# Ajustes — fase 1

Inicio → Ajustes abre las secciones General, Contactos, Ventas, Compras, Inventario y Contabilidad. Los accesos utilizan los catálogos existentes; las monedas se leen de la configuración financiera actual.

Empresa tiene listado y formulario separados. Existe una única ficha para el negocio, inicialmente sin datos. El nombre comercial es obligatorio; razón social, documento/RIF, dirección, teléfono, correo, sitio web y logo son opcionales. No se generan contactos, facturas ni movimientos de dinero al guardar Empresa.

El logo admite PNG, JPEG o WebP de hasta 2 MB y 4096 × 4096 píxeles. Se valida en el servidor y se guarda como datos de imagen en la base de datos; `logo.php` lo entrega con su tipo de imagen y sin caché. Una carga nueva reemplaza el logo; Quitar el logo guardado lo elimina al guardar. Seleccionar imagen solo previsualiza; Descartar no guarda.

Guardar utiliza CSRF y la versión de la ficha. Si dos ventanas editan al mismo tiempo, la segunda debe recargar para revisar los cambios. El instalador puede ejecutarse varias veces sin sobrescribir los datos ni el logo.

Instalación:

```powershell
& C:\xampp\php\php.exe php/install-settings.php
```

También se incluye en `php/install-commerce.php`. La instalación de esta fase se ha aplicado en el entorno local.

Pruebas: `test/business-settings.php`. El guardado, logos, conflictos e instalación repetible se comprueban en una base de datos de prueba aislada que se elimina al terminar. Las comprobaciones HTTP sobre el proyecto se limitan a lecturas e intentos inválidos que no alteran Empresa.

Validación manual:

1. Abrir Inicio → Ajustes y recorrer las seis secciones.
2. Abrir Empresa, entrar en su formulario y guardar el nombre y datos del negocio.
3. Volver al listado y reabrir la ficha para comprobar que se conservaron los datos.
4. Cargar un logo, guardar y comprobarlo en Empresa y General; cambiarlo y probar quitarlo.
5. Editar Empresa en dos ventanas: guardar la primera y comprobar el aviso de conflicto al guardar la segunda.
6. Abrir los accesos de configuración de cada módulo y revisar la vista móvil.

Esta fase prepara Ajustes y Empresa. Login, Usuarios, Grupos y restricciones de acceso corresponden a las fases siguientes, según [el plan](../../docs/plan-ajustes.md). Los datos de Empresa se muestran en Ajustes; su utilización en documentos futuros se integrará cuando se revise la presentación de esos documentos, sin modificar registros históricos.

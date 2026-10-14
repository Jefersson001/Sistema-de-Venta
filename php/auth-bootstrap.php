<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
if(PHP_SAPI!=='cli') {
    $route=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');
    $json=in_array($route,[authBasePath().'/modules/dashboard/resumen.php',authBasePath().'/modules/dashboard/ingresos-egresos.php',authBasePath().'/modules/ventas/producto.php'],true);
    authRequireStageAdministrator($json);
    // Un token de sesión compartido por formularios de negocio y el menú de usuario.
    foreach(['contacts_csrf','inventory_csrf','sales_csrf','purchase_csrf','accounting_csrf','commission_csrf','settings_csrf','taxes_csrf'] as $key) {
        $_SESSION[$key]=$_SESSION['auth_csrf'];
    }
}

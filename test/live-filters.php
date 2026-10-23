<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
function filterCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
echo "RUN filtros automáticos en servidor aislado\n";
$s=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'live-filters-');
try{
    $db=$s['db'];$setup=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(12));$login='filters.'.bin2hex(random_bytes(4));
    createFirstAdministrator($db,['name'=>'Filtros automáticos','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    [, $html]=authTestRequest($s,'/login.php',$cookie);filterCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'Falló el inicio de sesión.');
    $routes=['contactos/index.php','inventario/index.php','inventario/operaciones.php?type=delivery','inventario/operaciones.php?type=receipt','inventario/operaciones.php?type=internal',
        'inventario/configuracion.php?section=categories','inventario/configuracion.php?section=warehouses','inventario/configuracion.php?section=locations',
        'inventario/informes.php?view=stock','inventario/informes.php?view=movements','inventario/informes.php?view=sold&display=list','inventario/informes.php?view=sold&display=pivot','inventario/informes.php?view=purchased&display=graph',
        'ventas/index.php','compras/index.php','ventas/configuracion.php?section=taxes','compras/configuracion.php?section=taxes',
        'contabilidad/index.php?type=customer','contabilidad/index.php?type=supplier','contabilidad/pagos.php?type=customer','contabilidad/pagos.php?type=supplier',
        'contabilidad/tesoreria.php','contabilidad/configuracion.php?section=currencies','contabilidad/configuracion.php?section=payment_terms','contabilidad/configuracion.php?section=accounts','contabilidad/configuracion.php?section=methods','contabilidad/configuracion.php?section=concepts',
        'ajustes/usuarios.php','ajustes/grupos.php'];
    if(isset($argv[1]))$routes=array_slice($routes,(int)$argv[1],isset($argv[2])?(int)$argv[2]:10);
    foreach($routes as $route){
        echo 'RUN '.$route."\n";
        [$code,$html]=authTestRequest($s,'/modules/'.$route,$cookie);filterCheck($code===200,'No abre '.$route);
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($old);$xpath=new DOMXPath($dom);
        filterCheck($xpath->query('//main[@data-live-module]//form[@data-live-filters]')->length===1,'Falta el filtro automático común en '.$route);
        filterCheck($xpath->query('//form[@data-live-filters]//button[not(@type) or @type="submit"]')->length===0,'Sigue existiendo un botón de consulta en '.$route);
        filterCheck($xpath->query('//form[@data-live-filters]//*[@data-auto-submit]')->length===0,'Hay controladores duplicados en '.$route);
        filterCheck($xpath->query('//script[contains(@src,"/js/live-report.js")]')->length===1,'Falta o se repite el controlador común en '.$route);
        $separator=str_contains($route,'?')?'&':'?';[$code,$filtered]=authTestRequest($s,'/modules/'.$route.$separator.'q=zz-inexistente-filtros',$cookie);filterCheck($code===200&&str_contains($filtered,'value="zz-inexistente-filtros"'),'No conserva el texto de búsqueda en '.$route);
    }
    [, $html]=authTestRequest($s,'/modules/contactos/index.php?new=1',$cookie);filterCheck(!str_contains($html,'data-live-module')&&str_contains($html,'Guardar'),'Un formulario de guardado se convirtió en filtro automático.');
    [, $html]=authTestRequest($s,'/modules/contabilidad/ingresos-egresos.php',$cookie);filterCheck(!str_contains($html,'data-live-module')&&str_contains($html,'data-cash-flow-filter'),'Se duplicó la automatización de los informes de período.');
    echo 'PASS: '.count($routes)." listas y variantes con filtros automáticos, sin botones ni controladores duplicados; formularios y períodos conservados.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);throw $e;}
finally{if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

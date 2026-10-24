<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/bcv-sync.php';

// Chrome must use a temporary profile with --remote-debugging-port=9337.
// NEXO_CDP_PORT selects another port. Credentials exist only in this isolated DB.
$server = startAuthTestServer();
$cookie = tempnam(sys_get_temp_dir(), 'nexo-modules-cookie-');
$screenshots = $argv[1] ?? sys_get_temp_dir().'/nexo-modules-review-'.bin2hex(random_bytes(6));
$fixtures = sys_get_temp_dir().'/nexo-modules-fixtures-'.bin2hex(random_bytes(6));
mkdir($fixtures,0700);
try {
    $db = $server['db'];
    $token = prepareFirstAdministrator($db);
    $password = bin2hex(random_bytes(16)); $login = 'visual.'.bin2hex(random_bytes(5));
    createFirstAdministrator($db, ['name'=>'Revisión visual','login'=>$login,'password'=>$password,'password_confirmation'=>$password], hash('sha256',$token));
    bcvApplyPublication($db, ['rate'=>'100.00000000','effective_date'=>'2000-01-01','source'=>'bcv']);
    $type = (int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $db->prepare('INSERT INTO contacts(name,type_id,address) VALUES(?,?,?)')->execute(['Distribuidora Central',$type,'Av. Principal, Caracas']);
    $response=authTestRequest($server,'/login.php',$cookie);
    $response=authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($response[1]),'login'=>$login,'password'=>$password]);
    if($response[0]!==303)throw new RuntimeException('Falló el acceso de prueba.');
    // Capture actual rendered views through the local HTTP server. Inlining the
    // shared UI assets makes layout/validation tests independent of network access.
    $routes=['home'=>'/index.php','sale'=>'/modules/ventas/index.php?new=1',
        'contact'=>'/modules/contactos/index.php?new=1','contacts'=>'/modules/contactos/index.php',
        'purchase'=>'/modules/compras/index.php?new=1','payment'=>'/modules/contabilidad/pagos.php?type=customer&new=1',
        'treasury'=>'/modules/contabilidad/tesoreria.php?new=1&kind=transfer',
        'emptyproducts'=>'/modules/inventario/index.php?q=sin-resultados-'.bin2hex(random_bytes(12))];
    $css=preg_replace('/^@import[^\r\n]+[\r\n]*/m', '', file_get_contents(dirname(__DIR__).'/css/app.css'));
    $css.=file_get_contents(dirname(__DIR__).'/css/modules.css');
    $js=file_get_contents(dirname(__DIR__).'/js/app.js')."\n".file_get_contents(dirname(__DIR__).'/js/modules.js')."\n".file_get_contents(dirname(__DIR__).'/js/navigation.js');
    foreach($routes as $name=>$route){
        echo 'RENDER '.$name.PHP_EOL;
        $response=authTestRequest($server,$route,$cookie);
        if($response[0]!==200)throw new RuntimeException('No abre '.$route);
        $html=preg_replace('~<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>~i','',$response[1]);
        $html=preg_replace('~<link\b[^>]*rel="stylesheet"[^>]*>~i','',$html);
        $html=str_replace('</head>','<style>'.$css.'</style></head>',$html);
        $html=str_replace('</body>','<script>'.$js.'</script></body>',$html);
        file_put_contents($fixtures.'/'.$name.'.html',$html);
    }
    $process = proc_open(['node',__DIR__.'/modules-ui.js',$fixtures,$screenshots], [0=>['pipe','r'],1=>STDOUT,2=>STDERR], $pipes, dirname(__DIR__), null, ['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar la revisión en navegador.');
    fclose($pipes[0]);
    if (proc_close($process) !== 0) throw new RuntimeException('Falló la revisión de módulos en navegador.');
    echo 'Capturas: '.$screenshots.PHP_EOL;
} finally {
    if (is_file($cookie)) unlink($cookie);
    foreach(array_keys($routes??[]) as $name)if(is_file($fixtures.'/'.$name.'.html'))unlink($fixtures.'/'.$name.'.html');
    if(is_dir($fixtures))rmdir($fixtures);
    stopAuthTestServer($server);
}

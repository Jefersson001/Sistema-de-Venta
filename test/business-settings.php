<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/settings-schema.php';
require_once dirname(__DIR__).'/php/settings-repository.php';
require_once dirname(__DIR__).'/php/settings-sections.php';

function settingsCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function settingsReject(callable $action): void {
    try { $action(); } catch (InvalidArgumentException|PDOException) { return; }
    throw new RuntimeException('Se aceptó una modificación inválida de Empresa.');
}
function settingsRequest(string $path, string $cookie, ?array $post=null, string $method='GET'): array {
    $curl=testHttpCurl('http://localhost/project/'.$path, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    elseif($method!=='GET')curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    settingsCheck(is_string($body)&&!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error HTTP en '.$path);
    return [$status,$body];
}

$db=createDatabaseConnection(); $testDatabase='nexo_settings_test_'.bin2hex(random_bytes(8));
settingsCheck((bool)preg_match('/^nexo_settings_test_[a-f0-9]{16}$/D',$testDatabase),'Nombre de prueba incorrecto.');
$db->exec('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4');
try {
    $db->exec('USE `'.$testDatabase.'`'); installBusinessSettings($db); installBusinessSettings($db);
    $company=loadBusinessCompany($db);
    settingsCheck($company['name']===''&&(int)$company['version']===1&&!$company['has_logo'],'Se inventaron datos de Empresa.');
    settingsCheck((int)$db->query('SELECT COUNT(*) FROM business_company')->fetchColumn()===1,'Reinstalar duplica Empresa.');
    settingsReject(fn()=>$db->exec('INSERT INTO business_company(id) VALUES(2)'));
    $data=['name'=>'Negocio de prueba <script>','legal_name'=>'Razón social de prueba','tax_id'=>'J-12345678-9',
        'address'=>'Dirección de prueba','phone'=>'0424-0000000','email'=>'empresa@example.com','website'=>'https://example.com'];
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
    $logo=validateCompanyLogo($png);
    settingsCheck($logo['mime']==='image/png','No se valida el tipo real del logo.');
    saveBusinessCompany($db,$data,1,$logo);
    $saved=loadBusinessCompany($db);
    settingsCheck($saved['name']===$data['name']&&$saved['has_logo']&&(int)$saved['version']===2,'No se guardaron datos y logo.');
    foreach([['name'=>''],['name'=>[]],['email'=>'correo inválido'],['website'=>'javascript:alert(1)'],['website'=>'ftp://example.com'],['name'=>str_repeat('x',161)]] as $invalid) {
        settingsReject(fn()=>saveBusinessCompany($db,array_replace($data,$invalid),2));
    }
    settingsReject(fn()=>saveBusinessCompany($db,$data,0));
    settingsReject(fn()=>saveBusinessCompany($db,array_replace($data,['name'=>'Ventana antigua']),1));
    settingsCheck(loadBusinessCompany($db)===$saved,'Una validación o un conflicto alteró la ficha.');
    settingsReject(fn()=>validateCompanyLogo('<?php echo 1; ?>'));
    settingsReject(fn()=>validateCompanyLogo('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    settingsReject(fn()=>validateCompanyLogo(str_repeat('a',2*1024*1024+1)));
    $largeDimensions=substr_replace($png,pack('N',4097),16,4);
    settingsReject(fn()=>validateCompanyLogo($largeDimensions));
    settingsReject(fn()=>saveBusinessCompany($db,$data,2,['data'=>'archivo inválido']));
    settingsCheck(loadBusinessCompany($db)===$saved,'Un logo inválido cambió los datos.');
    settingsCheck(companyLogoUpload(['logo'=>['error'=>UPLOAD_ERR_NO_FILE]])===null,'Una carga vacía elimina el logo.');
    settingsReject(fn()=>companyLogoUpload(['logo'=>['error'=>UPLOAD_ERR_INI_SIZE]]));
    settingsReject(fn()=>companyLogoUpload(['logo'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__]]));
    saveBusinessCompany($db,array_replace($data,['phone'=>'0412-0000000']),2);
    settingsCheck((bool)loadBusinessCompany($db)['has_logo'],'Editar datos eliminó el logo.');
    saveBusinessCompany($db,$data,3,null,true);
    settingsCheck(!loadBusinessCompany($db)['has_logo'],'No se quitó el logo.');
    saveBusinessCompany($db,$data,4,$logo,true);
    settingsCheck((bool)loadBusinessCompany($db)['has_logo'],'Una nueva imagen no reemplaza la anterior.');
    $before=loadBusinessCompany($db); installBusinessSettings($db);
    settingsCheck(loadBusinessCompany($db)===$before&&$db->query('SELECT logo_data FROM business_company WHERE id=1')->fetchColumn()===$png,'Reinstalar sobrescribió datos o logo.');
} finally {
    $db->exec('USE information_schema'); $db->exec('DROP DATABASE `'.$testDatabase.'`');
}

$live=createDatabaseConnection(); $before=loadBusinessCompany($live); $cookie=tempnam(sys_get_temp_dir(),'settings-');
try {
    [$status,$home]=settingsRequest('index.php',$cookie);
    settingsCheck($status===200&&str_contains($home,'modules/ajustes/index.php'),'Ajustes no aparece en Inicio.');
    foreach(businessSettingsSections() as $key=>$section) {
        [$status,$html]=settingsRequest('modules/ajustes/index.php?section='.$key,$cookie);
        settingsCheck($status===200&&str_contains($html,'Ajustes generales'),'No se abre la sección '.$key);
        foreach($section['links'] as [$name,$description,$href]) {
            settingsCheck(str_contains($html,htmlspecialchars($href,ENT_QUOTES,'UTF-8')),'Falta el acceso '.$name);
            $target=str_starts_with($href,'../')?'modules/'.substr($href,3):'modules/ajustes/'.$href;
            [$linkStatus]=settingsRequest($target,$cookie);
            settingsCheck($linkStatus===200,'El acceso '.$name.' no abre su destino.');
        }
    }
    [$legacyStatus]=settingsRequest('modules/ajustes/index.php?section=Contabilidad',$cookie);
    settingsCheck($legacyStatus===200,'Se perdió el enlace anterior de Contabilidad.');
    settingsCheck(isset(businessSettingsSections()['restaurante'],businessSettingsSections()['accesos']),'Falta la configuración de Restaurante o de accesos.');
    [$status,$html]=settingsRequest('modules/ajustes/empresa.php',$cookie);
    settingsCheck($status===200&&str_contains($html,'<table')&&!str_contains($html,'data-company-form'),'No se separan listado y formulario de Empresa.');
    [$status,$html]=settingsRequest('modules/ajustes/empresa.php?id=1',$cookie);
    settingsCheck($status===200&&str_contains($html,'data-company-form')&&str_contains($html,'Guardar empresa'),'No se abre el formulario de Empresa.');
    settingsCheck(!str_contains($html,'Usuarios autorizados')&&!str_contains($html,'company_contacts[')&&!str_contains($html,'Guardar accesos'),'La ficha de Empresa todavía muestra relaciones de usuarios o contactos.');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$token); settingsCheck(!empty($token[1]),'No hay protección del formulario.');
    [$status]=settingsRequest('modules/ajustes/empresa.php?id=1',$cookie,['action'=>'save_company','csrf'=>'wrong','version'=>(string)$before['version'],'name'=>'No guardar']);
    settingsCheck($status===403,'Se acepta un guardado sin CSRF.');
    [$status,$html]=settingsRequest('modules/ajustes/empresa.php?id=1',$cookie,['action'=>'save_company','csrf'=>$token[1],'version'=>(string)$before['version'],'name'=>'No guardar','website'=>'javascript:alert(1)']);
    settingsCheck($status===200&&str_contains($html,'notice-error'),'No se rechazan datos inválidos.');
    foreach(['modules/ajustes/empresa.php?id[]=1','modules/ajustes/empresa.php?id=2','modules/ajustes/index.php?section[]=general','modules/ajustes/index.php?section=invalid'] as $path) {
        [$status]=settingsRequest($path,$cookie); settingsCheck($status===404,'Se acepta la ruta inválida '.$path);
    }
    [$status]=settingsRequest('modules/ajustes/index.php',$cookie,[]); settingsCheck($status===405,'Ajustes generales admite escrituras.');
    [$status]=settingsRequest('modules/ajustes/logo.php',$cookie,[]); settingsCheck($status===405,'Logo admite escrituras.');
    [$status]=settingsRequest('modules/ajustes/empresa.php?id=1',$cookie,null,'DELETE'); settingsCheck($status===405,'Empresa admite un método inválido.');
    settingsCheck(loadBusinessCompany($live)===$before,'Las pruebas HTTP modificaron la empresa del proyecto.');
    echo "PASS: Ajustes por módulo, accesos reales, listado/formulario, guardado aislado, logos, CSRF, conflictos y reinstalación conservan Empresa.\n";
} finally { unlink($cookie); }

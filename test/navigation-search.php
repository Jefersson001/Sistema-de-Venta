<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/navigation-search.php';
function navigationCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function navigationData(string $html):array{
    navigationCheck(preg_match('~<script[^>]*data-navigation-search-data[^>]*>(.*?)</script>~s',$html,$m)===1,'Search data missing.');
    return json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
}
$s=startAuthTestServer();$cookies=[];
try{
    $db=$s['db'];$password=bin2hex(random_bytes(12));$login='navigation.'.bin2hex(random_bytes(4));$setup=prepareFirstAdministrator($db);
    createFirstAdministrator($db,['name'=>'Navigation QA','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $cookie=tempnam(sys_get_temp_dir(),'navigation-admin-');$cookies[]=$cookie;[, $html]=authTestRequest($s,'/login.php',$cookie);authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password]);
    [$code,$html]=authTestRequest($s,'/index.php',$cookie);$entries=navigationData($html);navigationCheck($code===200&&count($entries)===count(navigationSearchCatalog()),'Administrator search catalog incomplete.');
    $supplier=array_values(array_filter($entries,static fn($e)=>$e['path']==='Contabilidad / Proveedores / Pagos'));navigationCheck(count($supplier)===1&&str_ends_with($supplier[0]['url'],'/modules/contabilidad/pagos.php?type=supplier'),'Supplier payment breadcrumb incorrect.');
    navigationCheck(count(array_unique(array_column($entries,'url')))===count($entries),'Duplicate destinations.');
    foreach($entries as $entry){
        navigationCheck(str_starts_with($entry['url'],'/project/modules/')&&!str_contains($entry['url'],'new=')&&!str_contains($entry['url'],'action='),'Search contains a write action or external URL.');
        [$code,$body]=authTestRequest($s,substr($entry['url'],strlen('/project')),$cookie);navigationCheck($code===200,'Destination unavailable: '.$entry['path'].' ('.$code.')');
        $document=new DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML($body);libxml_clear_errors();libxml_use_internal_errors($previous);
        $xpath=new DOMXPath($document);$crumbs=$xpath->query('//nav[contains(concat(" ",normalize-space(@class)," ")," module-breadcrumb ")]//li');
        $labels=[];foreach($crumbs as $crumb)$labels[]=trim($crumb->textContent);
        navigationCheck(implode(' / ',array_slice($labels,1))===$entry['path'],'Module breadcrumb disagrees with search: '.$entry['path']);
    }
    echo 'PASS: '.count($entries)." real destinations and hierarchical breadcrumbs.\n";
    foreach(['supplier'=>['contabilidad.supplier_payments.read'],'contacts'=>['contactos.contacts.read'],'none'=>[]] as $role=>$permissions){
        $db->prepare('INSERT INTO access_groups(name,description) VALUES(?,?)')->execute(['Navigation '.$role.' QA','Search permissions QA']);$group=(int)$db->lastInsertId();
        foreach($permissions as $permission)$db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,'all')")->execute([$group,$permission]);
        $roleLogin='navigation.'.$role.'.'.bin2hex(random_bytes(4));$db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Navigation '.$role,$roleLogin,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$user,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$user]);
        $roleCookie=tempnam(sys_get_temp_dir(),'navigation-role-');$cookies[]=$roleCookie;[, $html]=authTestRequest($s,'/login.php',$roleCookie);authTestRequest($s,'/login.php',$roleCookie,['csrf'=>authTestToken($html),'login'=>$roleLogin,'password'=>$password]);
        [$code,$html]=authTestRequest($s,'/index.php',$roleCookie);$limited=navigationData($html);navigationCheck($code===200,'Limited home failed.');
        if($role==='supplier'){
            navigationCheck(count($limited)===1&&$limited[0]['path']==='Contabilidad / Proveedores / Pagos','Search leaked another payment type or configuration.');
            navigationCheck(authTestRequest($s,'/modules/contabilidad/pagos.php?type=customer',$roleCookie)[0]===403,'Customer payment route not protected.');
            $supplierLogin=$roleLogin;
        }elseif($role==='contacts')navigationCheck(count($limited)===2&&count(array_unique(array_column($limited,'module')))===1&&$limited[0]['module']==='contactos','Search exposed unauthorized sections.');
        else navigationCheck($limited===[],'User without permissions received search entries.');
    }
    echo "PASS: supplier-only, contact-only and no-permission search catalogs; direct route authorization.\n";
    $env=getenv();$env['NEXO_TEST_BASE_URL']=$s['url'];$env['NEXO_TEST_LOGIN']=$login;$env['NEXO_TEST_PASSWORD']=$password;$env['NEXO_NAV_SUPPLIER_LOGIN']=$supplierLogin;
    $process=proc_open(['node',__DIR__.'/navigation-search-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);navigationCheck(proc_close($process)===0,'Navigation search browser review failed.');
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

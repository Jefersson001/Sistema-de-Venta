<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/company-repository.php';
function userDeleteCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function userDeletePath(?int $id=null,array $ids=[]): string {
    return '/acciones.php?'.http_build_query(['source'=>'/project/modules/ajustes/usuarios.php'.($id?'?id='.$id:''),'action'=>'delete']+($ids?['ids'=>$ids]:[]));
}
function userDeleteConfirm(array $s,string $cookie,string $path): array {
    [$status,$body]=authTestRequest($s,$path,$cookie);
    userDeleteCheck($status===200&&str_contains($body,'Sí, eliminar'),'No se abrió la confirmación: '.strip_tags($body));
    return ['csrf'=>authTestToken($body),'company_scope'=>authTestToken($body,'company_scope'),'request_key'=>authTestToken($body,'request_key')];
}
echo "RUN eliminación de usuarios en servidor aislado\n";
$s=startAuthTestServer();$cookies=[];
try {
    $db=$s['db'];$setup=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(12));$login='delete.admin.'.bin2hex(random_bytes(4));
    $actor=createFirstAdministrator($db,['name'=>'Administrador de borrado','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $second=createBusinessCompany($db,['name'=>'Empresa de prueba de accesos'],$actor);
    $db->exec('SET @nexo_company_id=1,@nexo_actor_id='.(int)$actor);
    $db->exec("INSERT INTO access_groups(name) VALUES('Edición sin administración')");$group=(int)$db->lastInsertId();
    foreach(['ajustes.users.read','ajustes.users.edit'] as $code)$db->prepare('INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)')->execute([$group,$code,'all']);
    $newUser=static function(string $name)use($db,$password,$group,$second):array {
        $login='delete.user.'.bin2hex(random_bytes(5));
        $db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute([$name,$login,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$id,$group]);
        foreach([1,$second] as $company)$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,?)')->execute([$id,$company]);
        return [$id,$login];
    };
    $signIn=static function(string $login)use($s,$password,&$cookies):string {
        $cookie=tempnam(sys_get_temp_dir(),'user-delete-');$cookies[]=$cookie;
        [, $html]=authTestRequest($s,'/login.php',$cookie);
        userDeleteCheck(authTestRequest($s,'/login.php',$cookie,['csrf'=>authTestToken($html),'login'=>$login,'password'=>$password])[0]===303,'No inició sesión el usuario de prueba.');return $cookie;
    };
    $exists=static function(int $id)use($db):bool{$q=$db->prepare('SELECT COUNT(*) FROM auth_users WHERE id=?');$q->execute([$id]);return (int)$q->fetchColumn()===1;};
    $cookie=$signIn($login);[$target,$targetLogin]=$newUser('Usuario para eliminar');$targetCookie=$signIn($targetLogin);
    $type=(int)$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn();
    $db->exec('SET @nexo_actor_id='.$target);$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute(['Contacto que se conserva',$type]);$contact=(int)$db->lastInsertId();$db->exec('SET @nexo_actor_id='.$actor);
    $db->prepare('UPDATE auth_users SET contact_id=? WHERE id=?')->execute([$contact,$target]);
    foreach(['/modules/ajustes/usuarios.php','/modules/ajustes/usuarios.php?id='.$target] as $path){
        [$status,$html]=authTestRequest($s,$path,$cookie);
        userDeleteCheck($status===200&&str_contains($html,'record-delete-link'),'No está habilitado Eliminar en '.$path);
        if(!str_contains($path,'?')){
            userDeleteCheck(str_contains($html,'data-record-select-all'),'No se puede seleccionar usuarios en el listado.');
            $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();$xpath=new DOMXPath($dom);
            userDeleteCheck($xpath->query('//input[@data-record-select and @value="'.$actor.'"]')->length===0,'Seleccionar todos incluiría la cuenta propia.');
        }
    }
    $path=userDeletePath($target);$post=userDeleteConfirm($s,$cookie,$path);
    userDeleteCheck($exists($target),'Una consulta GET eliminó el usuario.');
    userDeleteCheck(authTestRequest($s,$path,$cookie,array_replace($post,['csrf'=>'invalid']))[0]===409&&$exists($target),'Se aceptó un CSRF inválido.');
    userDeleteCheck(authTestRequest($s,$path,$cookie,array_replace($post,['company_scope'=>'invalid']))[0]===409&&$exists($target),'Se aceptó una empresa obsoleta.');
    userDeleteCheck(authTestRequest($s,$path,$targetCookie)[0]===403,'Un editor sin ser Administrador puede eliminar usuarios.');
    [, $html]=authTestRequest($s,'/modules/ajustes/usuarios.php',$targetCookie);userDeleteCheck(!str_contains($html,'record-delete-link'),'Se ofrece Eliminar a un editor sin administración.');
    [$status,$html]=authTestRequest($s,$path,$cookie,$post);
    userDeleteCheck($status===200&&str_contains($html,'Registros eliminados.')&&!$exists($target),'No se eliminó el usuario: '.strip_tags($html));
    foreach(['auth_user_groups','company_user_access'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE user_id=?');$q->execute([$target]);userDeleteCheck((int)$q->fetchColumn()===0,'Quedaron asociaciones en '.$table);}
    $q=$db->prepare('SELECT name,created_by FROM contacts WHERE id=?');$q->execute([$contact]);$saved=$q->fetch();
    userDeleteCheck($saved&&$saved['name']==='Contacto que se conserva'&&$saved['created_by']===null,'Se eliminó o alteró el contacto del usuario.');
    userDeleteCheck(authTestRequest($s,$path,$cookie,$post)[0]===200,'Reenviar la confirmación no es idempotente.');
    $q=$db->prepare('SELECT COUNT(*) FROM business_record_actions WHERE request_key=?');$q->execute([$post['request_key']]);userDeleteCheck((int)$q->fetchColumn()===1,'Se duplicó la acción de borrado.');
    userDeleteCheck(authTestRequest($s,'/modules/ajustes/usuarios.php',$targetCookie)[0]===303,'La sesión del usuario eliminado sigue activa.');
    $q=$db->prepare('SELECT details FROM business_record_actions WHERE request_key=?');$q->execute([$post['request_key']]);$audit=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    userDeleteCheck($audit[0]['login']===$targetLogin&&!isset($audit[0]['password_hash']),'No se conservó una identificación adecuada en el registro de la eliminación.');
    [$otherAdmin]=$newUser('Otro Administrador');$adminGroup=(int)$db->query("SELECT id FROM access_groups WHERE system_key='administrator'")->fetchColumn();
    $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$otherAdmin,$adminGroup]);
    $adminPath=userDeletePath($otherAdmin);$adminPost=userDeleteConfirm($s,$cookie,$adminPath);
    userDeleteCheck(authTestRequest($s,$adminPath,$cookie,$adminPost)[0]===200&&!$exists($otherAdmin)&&$exists($actor),'No se puede eliminar otro Administrador conservando la cuenta administradora activa.');
    userDeleteCheck(authTestRequest($s,userDeletePath($actor),$cookie)[0]===422&&$exists($actor),'Se permitió eliminar la cuenta propia o el último Administrador.');
    echo "RUN conflictos y rollback de grupos, empresas e historial\n";
    [$stale]=$newUser('Usuario cambiado');$stalePath=userDeletePath($stale);$stalePost=userDeleteConfirm($s,$cookie,$stalePath);
    $db->prepare('UPDATE auth_users SET name=?,version=version+1 WHERE id=?')->execute(['Nombre actualizado',$stale]);
    userDeleteCheck(authTestRequest($s,$stalePath,$cookie,$stalePost)[0]===409&&$exists($stale),'Se eliminó un usuario modificado tras la confirmación.');
    [$changedGroups]=$newUser('Grupos cambiados');$changedPath=userDeletePath($changedGroups);$changedPost=userDeleteConfirm($s,$cookie,$changedPath);
    $db->exec("INSERT INTO access_groups(name) VALUES('Nuevo grupo de confirmación')");$newGroup=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$changedGroups,$newGroup]);
    userDeleteCheck(authTestRequest($s,$changedPath,$cookie,$changedPost)[0]===409&&$exists($changedGroups),'No se detectó un cambio de asociaciones.');
    [$unused]=$newUser('Borrado previo que debe revertirse');[$waiter]=$newUser('Mesero histórico');
    $db->exec('SET @nexo_company_id='.(int)$second);$db->prepare("INSERT INTO restaurant_accounts(name,waiter_id,status) VALUES(?,?,'closed')")->execute(['Cuenta histórica',$waiter]);$history=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id=1');
    $batch=userDeletePath(null,[$unused,$waiter]);$batchPost=userDeleteConfirm($s,$cookie,$batch);
    [$status,$html]=authTestRequest($s,$batch,$cookie,$batchPost);
    userDeleteCheck($status===422&&str_contains($html,'actividad histórica')&&$exists($unused)&&$exists($waiter),'El fallo de historial dejó usuarios parcialmente eliminados.');
    foreach([$unused,$waiter] as $id){$q=$db->prepare('SELECT COUNT(*) FROM auth_user_groups WHERE user_id=?');$q->execute([$id]);userDeleteCheck((int)$q->fetchColumn()===1,'Se perdieron grupos durante el rollback.');$q=$db->prepare('SELECT COUNT(*) FROM company_user_access WHERE user_id=?');$q->execute([$id]);userDeleteCheck((int)$q->fetchColumn()===2,'Se perdieron accesos durante el rollback.');}
    userDeleteCheck((int)$db->query('SELECT COUNT(*) FROM restaurant_accounts WHERE id='.$history)->fetchColumn()===1,'Se eliminó actividad histórica de otra empresa.');
    $batch=userDeletePath(null,[$unused,$stale]);$batchPost=userDeleteConfirm($s,$cookie,$batch);
    userDeleteCheck(authTestRequest($s,$batch,$cookie,$batchPost)[0]===200&&!$exists($unused)&&!$exists($stale),'No funciona la eliminación múltiple de usuarios sin historial obligatorio.');
    echo "PASS: menú y selección, confirmación, grupos y empresas, documentos conservados, sesión revocada, permisos, cuenta propia, último administrador, conflictos, reenvío y rollback completo.\n";
} finally {foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);stopAuthTestServer($s);}

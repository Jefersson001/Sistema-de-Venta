<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';
require_once dirname(__DIR__).'/php/commercial-portal-auth.php';
require_once dirname(__DIR__).'/php/commercial-admin-auth.php';
function portalCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$server=null;$cookies=[];
try{
    $server=startAuthTestServer();$db=$server['db'];
    $baseMigration=require dirname(__DIR__).'/php/migrations/versioned/20261127173632_solicitudes_demo_sitio_comercial.php';$baseMigration($db);
    // A legacy anonymous request must survive, but must never be claimed by email.
    $legacy=['full_name'=>'Legacy','business_name'=>'Legacy business','email'=>'prospect@example.test','phone'=>'04121234567','city'=>'','requirements'=>'Legacy private request','sector'=>'retail','company_count'=>'1','user_count'=>'1-3','plan_key'=>'essential','contact_method'=>'email'];
    $legacyId=commercialSaveDemo($db,$legacy,bin2hex(random_bytes(32)),'legacy-ip');
    $migration=require dirname(__DIR__).'/php/migrations/versioned/20261127182658_portal_clientes_demo.php';$migration($db);$migration($db);
    $adminMigration=require dirname(__DIR__).'/php/migrations/versioned/20261127222824_administracion_comercial_independiente.php';$beforeAdminMigration=$db->query('SELECT * FROM commercial_demo_requests ORDER BY id')->fetchAll();$adminMigration($db);$adminMigration($db);
    foreach($beforeAdminMigration as $oldRow){$q=$db->prepare('SELECT * FROM commercial_demo_requests WHERE id=?');$q->execute([$oldRow['id']]);$nowRow=$q->fetch();foreach($oldRow as $key=>$value)portalCheck($nowRow[$key]===$value,'Admin migration changed existing data.');}
    // Recreate only new chat tables in this isolated fixture to test an upgrade with old history.
    $db->exec('DROP TABLE IF EXISTS commercial_chat_reads,commercial_chat_messages');
    $db->prepare('INSERT INTO commercial_demo_events(request_id,status,message) VALUES(?,?,?)')->execute([$legacyId,'new','Legacy shared history']);
    $chatMigration=require dirname(__DIR__).'/php/migrations/versioned/20261128162513_conversaciones_portal_comercial.php';$chatMigration($db);$chatMigration($db);
    portalCheck((int)$db->query("SELECT COUNT(*) FROM commercial_chat_messages WHERE body LIKE '%Legacy shared history%'")->fetchColumn()===1,'Chat migration did not preserve old shared history exactly once.');
    portalCheck(!$db->query("SELECT table_name FROM company_schema_tables WHERE table_name IN ('commercial_portal_accounts','commercial_demo_requests','commercial_demo_events')")->fetchColumn(),'Portal leaked into company ownership.');
    $token=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(12));$login='portal.admin.'.bin2hex(random_bytes(4));$admin=createFirstAdministrator($db,['name'=>'Portal Admin','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));$db->exec('SET @nexo_company_id=1,@nexo_actor_id='.$admin);
    foreach(['guest','one','two','admin','erpadmin','setupother','expired','readonly','adminother'] as $key)$cookies[$key]=tempnam(sys_get_temp_dir(),'portal-qa-');
    $erpCounts=[];foreach(['auth_users','contacts','business_company','company_user_access'] as $table)$erpCounts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    [$code,$home]=authTestRequest($server,'/index.php',$cookies['guest']);portalCheck($code===200&&str_contains($home,'portal-demo.php'),'Public site missing portal invitation.');
    foreach(['ventas','inventario','venta_directa','compras','compra_directa','contabilidad','contactos','restaurante','dashboard','ajustes'] as $module)portalCheck(str_contains($home,'id="module-'.$module.'"'),'Missing module '.$module);
    portalCheck(authTestRequest($server,'/conoce-konta.php',$cookies['guest'],['action'=>'request'])[0]===303,'Old anonymous endpoint still accepts requests.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['guest'])[0]===303,'Guest reached admin inbox.');
    $signup=['action'=>'register','full_name'=>'Prospect QA','business_name'=>'<script>bad()</script> Business','email'=>'prospect@example.test','phone'=>'+58 412 1234567','password'=>$password,'password_confirmation'=>$password,'consent'=>'1','website'=>''];
    [, $form]=authTestRequest($server,'/portal-demo.php?view=registro',$cookies['one']);$signup['csrf']=authTestToken($form);
    foreach([['csrf'=>'bad'],['password_confirmation'=>'wrong'],['password'=>'short','password_confirmation'=>'short'],['consent'=>''],['email'=>'bad'],['phone'=>'letters'],['website'=>'spam'],['full_name'=>['array']]] as $change){$db->exec('DELETE FROM commercial_demo_rate_limits');portalCheck(in_array(authTestRequest($server,'/portal-demo.php?view=registro',$cookies['one'],array_replace($signup,$change))[0],[403,422],true),'Invalid registration accepted.');}
    // Invalid attempts deliberately consume the signup rate bucket.
    $db->exec('DELETE FROM commercial_demo_rate_limits');
    portalCheck(authTestRequest($server,'/portal-demo.php?view=registro',$cookies['one'],$signup)[0]===303,'Registration failed.');
    $account=$db->query("SELECT * FROM commercial_portal_accounts WHERE email='prospect@example.test'")->fetch();portalCheck($account&&password_verify($password,$account['password_hash'])&&$account['password_hash']!==$password,'Password not hashed.');
    [$code,$dashboard]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck($code===200&&str_contains($dashboard,'Mis solicitudes')&&!str_contains($dashboard,'Legacy private request'),'Portal dashboard wrong or claimed legacy request.');
    portalCheck(!str_contains($dashboard,'id="mi-perfil"')&&str_contains($dashboard,'workspace-user-menu'),'Account is not separated from the dashboard.');
    [$code,$accountPage]=authTestRequest($server,'/portal-demo.php?section=cuenta',$cookies['one']);portalCheck($code===200&&str_contains($accountPage,'id="mi-perfil"')&&str_contains($accountPage,'prospect@example.test')&&!str_contains($accountPage,'class="portal-request'),'Separate account view is incorrect.');
    portalCheck(authTestRequest($server,'/modules/ventas/index.php',$cookies['one'])[0]===303&&authTestRequest($server,'/solicitudes-demo.php',$cookies['one'])[0]===303,'Portal account grants ERP access.');
    portalCheck(!str_contains($dashboard,'name="request_key"'),'Dashboard still embeds the create form.');
    [$createCode,$createForm]=authTestRequest($server,'/portal-demo.php?section=solicitar',$cookies['one']);portalCheck($createCode===200&&str_contains($createForm,'request-create-form'),'Separate create view missing.');
    $post=['csrf'=>authTestToken($dashboard),'action'=>'request','request_key'=>authTestToken($createForm,'request_key'),'sector'=>'retail','city'=>'Maracay','company_count'=>'2','user_count'=>'4-6','plan_key'=>'growth','contact_method'=>'email','requirements'=>'Private prospect ONE needs inventory','consent'=>'1','website'=>'','portal_account_id'=>'99999','email'=>'forged@example.test'];
    foreach([['csrf'=>'bad'],['consent'=>''],['sector'=>['bad']],['plan_key'=>'bad'],['website'=>'spam']] as $change)portalCheck(in_array(authTestRequest($server,'/portal-demo.php',$cookies['one'],array_replace($post,$change))[0],[403,422],true),'Invalid request accepted.');
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],$post)[0]===303,'Demo request failed.');
    $q=$db->prepare('SELECT * FROM commercial_demo_requests WHERE request_key=?');$q->execute([$post['request_key']]);$lead=$q->fetch();portalCheck((int)$lead['portal_account_id']===(int)$account['id']&&$lead['email']===$account['email'],'Posted owner or contact data overrode authenticated account.');
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],$post)[0]===303,'Request retry failed.');portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],array_replace($post,['requirements'=>'changed']))[0]===409,'Changed retry accepted.');
    portalCheck((int)$db->query('SELECT COUNT(*) FROM commercial_demo_events WHERE request_id='.(int)$lead['id'])->fetchColumn()===1,'Retry duplicated history.');
    [, $form]=authTestRequest($server,'/portal-demo.php?view=registro',$cookies['two']);$other=array_replace($signup,['csrf'=>authTestToken($form),'email'=>'other@example.test','business_name'=>'Other business']);portalCheck(authTestRequest($server,'/portal-demo.php?view=registro',$cookies['two'],$other)[0]===303,'Second registration failed.');
    [, $otherPage]=authTestRequest($server,'/portal-demo.php?id='.$lead['id'],$cookies['two']);portalCheck(!str_contains($otherPage,'Private prospect ONE')&&!str_contains($otherPage,'Legacy private request'),'Cross-account data leak.');
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['two'],array_replace($post,['csrf'=>authTestToken($otherPage)]))[0]===403,'Cross-session submission key accepted.');
    foreach($erpCounts as $table=>$count)portalCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'Portal registration modified ERP '.$table);
    [, $erpForm]=authTestRequest($server,'/login.php',$cookies['erpadmin']);portalCheck(authTestRequest($server,'/login.php',$cookies['erpadmin'],['csrf'=>authTestToken($erpForm),'login'=>$login,'password'=>$password])[0]===303,'ERP login failed.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['erpadmin'])[0]===303,'ERP administrator inherited commercial access.');
    [, $settings]=authTestRequest($server,'/modules/ajustes/index.php',$cookies['erpadmin']);portalCheck(!str_contains($settings,'/solicitudes-demo.php'),'Commercial management remains in ERP Settings.');
    $commercialEmail='commercial-admin@example.test';$commercialPassword=bin2hex(random_bytes(12));
    [, $accessForm]=authTestRequest($server,'/admin-comercial.php',$cookies['admin']);
    $activate=['csrf'=>authTestToken($accessForm),'action'=>'activate','name'=>'Commercial QA','email'=>$commercialEmail,'password'=>$commercialPassword,'password_confirmation'=>$commercialPassword];
    portalCheck(authTestRequest($server,'/admin-comercial.php',$cookies['admin'],$activate)[0]===403,'Admin registration is public.');
    $setupToken=commercialAdminIssueSetup($db);
    portalCheck(authTestRequest($server,'/admin-comercial.php?setup='.$setupToken,$cookies['admin'])[0]===303,'Setup link failed.');
    portalCheck(authTestRequest($server,'/admin-comercial.php?setup='.$setupToken,$cookies['setupother'])[0]===303,'Setup race could not be prepared.');
    [, $setupForm]=authTestRequest($server,'/admin-comercial.php?view=activar',$cookies['admin']);$activate['csrf']=authTestToken($setupForm);
    portalCheck(authTestRequest($server,'/admin-comercial.php?view=activar',$cookies['admin'],array_replace($activate,['csrf'=>'bad']))[0]===403,'Setup missing CSRF.');
    portalCheck(authTestRequest($server,'/admin-comercial.php?view=activar',$cookies['admin'],$activate)[0]===303,'Commercial activation failed.');
    $commercialAdmin=(int)$db->query("SELECT id FROM commercial_admin_accounts WHERE email='commercial-admin@example.test'")->fetchColumn();
    portalCheck((bool)$commercialAdmin,'Commercial admin not persisted.');
    try{commercialAdminIssueSetup($db);throw new RuntimeException('Setup can be reopened after activation.');}catch(InvalidArgumentException $expected){}
    try{commercialAdminLogin($db,['email'=>$signup['email'],'password'=>$password],'client-admin-test');throw new RuntimeException('Client credentials authenticate as admin.');}catch(InvalidArgumentException $expected){portalCheck($expected->getCode()===401,'Wrong client/admin isolation response.');}

    [, $otherSetup]=authTestRequest($server,'/admin-comercial.php',$cookies['setupother']);portalCheck(authTestRequest($server,'/admin-comercial.php',$cookies['setupother'],array_replace($activate,['csrf'=>authTestToken($otherSetup),'email'=>'attacker@example.test']))[0]===403,'Setup link could create a second administrator.');
    portalCheck(authTestRequest($server,'/modules/ventas/index.php',$cookies['admin'])[0]===303,'Commercial administrator inherited ERP access.');
    foreach($erpCounts as $table=>$count)portalCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'Commercial activation modified ERP '.$table);
    [$code,$inbox]=authTestRequest($server,'/solicitudes-demo.php',$cookies['admin']);portalCheck($code===200&&str_contains($inbox,'&lt;script&gt;')&&str_contains($inbox,'admin-request-row')&&!str_contains($inbox,'name="public_message"'),'Admin tree missing or contains edit forms.');
    [$detailCode,$adminDetail]=authTestRequest($server,'/solicitudes-demo.php?request='.$lead['id'],$cookies['admin']);portalCheck($detailCode===200&&str_contains($adminDetail,'data-demo-chat')&&str_contains($adminDetail,'data-request-tab="notas"'),'Admin form missing tabs or editor.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php?request=999999999',$cookies['admin'])[0]===404,'Missing admin record was not rejected.');

    $update=['csrf'=>authTestToken($inbox),'id'=>(string)$lead['id'],'version'=>(string)$lead['version'],'status'=>'scheduled','internal_notes'=>'SECRET-ADMIN-ONLY','public_message'=>'Demo coordinada: conversaremos sobre tus productos.'];
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],array_replace($update,['csrf'=>'bad']))[0]===403,'Admin update missing CSRF.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$update)[0]===303,'Follow-up failed.');$audit=$db->query('SELECT updated_by,updated_by_commercial_admin FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetch();portalCheck($audit['updated_by']===null&&(int)$audit['updated_by_commercial_admin']===$commercialAdmin,'Follow-up audit mixed ERP and commercial identities.');portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$update)[0]===409,'Stale update accepted.');
    [, $page]=authTestRequest($server,'/portal-demo.php?request='.$lead['id'].'&tab=seguimiento',$cookies['one']);portalCheck(str_contains($page,'data-demo-chat')&&!str_contains($page,'SECRET-ADMIN-ONLY'),'Public history missing or private notes leaked.');
    [$otherCode, $pageTwo]=authTestRequest($server,'/portal-demo.php?request='.$lead['id'],$cookies['two']);portalCheck($otherCode===404,'Another account opened the request detail.');portalCheck(!str_contains($pageTwo,$update['public_message']),'Public reply leaked to other account.');
    $privateOnly=$update;$privateOnly['version']=(string)((int)$lead['version']+1);$privateOnly['internal_notes']='SECOND-PRIVATE-NOTE';
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$privateOnly)[0]===303,'Private note update failed.');
    portalCheck((int)$db->query('SELECT COUNT(*) FROM commercial_demo_events WHERE request_id='.(int)$lead['id'])->fetchColumn()===2,'Private note added public history.');
    // Approval only publishes the login link; provisioning stays manual.
    require __DIR__.'/commercial-chat-checks.php';
    [, $beforeApproval]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(!str_contains($beforeApproval,'data-system-access'),'Login link is visible before approval.');
    $countsBeforeApproval=[];foreach(['auth_users','business_company','company_user_access'] as $table)$countsBeforeApproval[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $approve=$update;$approve['action']='approve';$approve['version']=(string)$db->query('SELECT version FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn();
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],array_replace($approve,['csrf'=>'bad']))[0]===403,'Approval missing CSRF.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['one'],$approve)[0]===303,'Client reached commercial approval endpoint.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$approve)[0]===303,'One-click approval failed.');
    portalCheck($db->query('SELECT status FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn()==='approved','Approve button did not set approved status.');
    [, $approvedHome]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(!str_contains($approvedHome,'data-system-access'),'Login button must appear only inside an approved request.');
    [, $approvedDetail]=authTestRequest($server,'/portal-demo.php?request='.$lead['id'],$cookies['one']);portalCheck(str_contains($approvedDetail,'data-system-access'),'Approved detail has no login button.');
    [, $otherHome]=authTestRequest($server,'/portal-demo.php',$cookies['two']);portalCheck(!str_contains($otherHome,'data-system-access'),'Approval enabled another client.');
    foreach($countsBeforeApproval as $table=>$count)portalCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'Approval unexpectedly provisioned ERP data.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$approve)[0]===409,'Stale approval was accepted.');
    $reject=$approve;$reject['action']='reject';$reject['version']=(string)$db->query('SELECT version FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn();
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'],$reject)[0]===303,'One-click rejection failed.');
    [, $rejectedHome]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(!str_contains($rejectedHome,'data-system-access'),'Rejected request still shows login button.');
    // Only rejection permits a new request; closing is not equivalent to rejection.
    foreach(['new','contacted','scheduled','approved','closed'] as $blockingStatus){
        $version=(int)$db->query('SELECT version FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn();
        commercialUpdateDemo($db,['id'=>$lead['id'],'version'=>$version,'status'=>$blockingStatus,'internal_notes'=>'SECRET-ADMIN-ONLY','public_message'=>'Status test'],(int)$commercialAdmin);
        try{commercialSaveDemo($db,$legacy,bin2hex(random_bytes(32)),'another-ip',(int)$account['id']);throw new RuntimeException('Second demo accepted in '.$blockingStatus);}catch(InvalidArgumentException $e){portalCheck($e->getCode()===409,'Wrong duplicate request response.');}
        [, $blocked]=authTestRequest($server,'/portal-demo.php?nueva=1',$cookies['one']);portalCheck(!str_contains($blocked,'name="request_key"')&&!str_contains($blocked,'data-request-open'),'Request form visible while blocked.');
    }
    $version=(int)$db->query('SELECT version FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn();
    commercialUpdateDemo($db,['id'=>$lead['id'],'version'=>$version,'status'=>'rejected','internal_notes'=>'SECRET-ADMIN-ONLY','public_message'=>'Puedes volver a solicitar la demo.'],(int)$commercialAdmin);
    [, $retryForm]=authTestRequest($server,'/portal-demo.php?section=solicitar',$cookies['one']);portalCheck(str_contains($retryForm,'name="request_key"'),'Rejection did not unlock form or appear in history.');
    $retryPost=array_replace($post,['csrf'=>authTestToken($retryForm),'request_key'=>authTestToken($retryForm,'request_key')]);
    [, $secondTab]=authTestRequest($server,'/portal-demo.php?section=solicitar',$cookies['one']);$secondPost=array_replace($retryPost,['request_key'=>authTestToken($secondTab,'request_key')]);
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],$retryPost)[0]===303,'New request after rejection failed.');
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],$secondPost)[0]===409,'Second tab bypassed single-demo rule.');
    $version=(int)$db->query('SELECT version FROM commercial_demo_requests WHERE id='.(int)$lead['id'])->fetchColumn();
    try{commercialUpdateDemo($db,['id'=>$lead['id'],'version'=>$version,'status'=>'new','internal_notes'=>'','public_message'=>''],(int)$commercialAdmin);throw new RuntimeException('Reopened old request alongside a newer active request.');}catch(InvalidArgumentException $e){portalCheck($e->getCode()===409,'Reopening conflict not reported.');}
    // Concurrent requests from separate connections/IPs serialize on the owner account.
    $ownerTwo=(int)$db->query("SELECT id FROM commercial_portal_accounts WHERE email='other@example.test'")->fetchColumn();
    $worker='putenv("NEXO_DATABASE_NAME=".$argv[1]);require $argv[2];$db=createDatabaseConnection(true);$data=json_decode($argv[4],true);try{commercialSaveDemo($db,$data,bin2hex(random_bytes(32)),bin2hex(random_bytes(8)),(int)$argv[3]);echo "created";}catch(InvalidArgumentException $e){echo "blocked:".$e->getCode();}';
    $workers=[];for($n=0;$n<2;$n++){$process=proc_open([PHP_BINARY,'-r',$worker,$server['database'],dirname(__DIR__).'/php/commercial-repository.php',(string)$ownerTwo,json_encode($legacy)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);$workers[]=[$process,$pipes];}
    $outcomes=[];foreach($workers as [$process,$pipes]){$outcomes[]=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);portalCheck(proc_close($process)===0&&$stderr==='','Concurrent worker failed: '.$stderr);}sort($outcomes);portalCheck($outcomes===['blocked:409','created'],'Concurrent requests bypassed owner lock.');
    $readonlyLogin='portal.read.'.bin2hex(random_bytes(4));$db->prepare('INSERT INTO auth_users(name,login,password_hash,must_change_password) VALUES(?,?,?,0)')->execute(['Read only',$readonlyLogin,password_hash($password,PASSWORD_DEFAULT)]);$readonly=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO access_groups(name) VALUES('Portal QA read')")->execute();$group=(int)$db->lastInsertId();$db->prepare("INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,'contactos.contacts.read','all')")->execute([$group]);$db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$readonly,$group]);$db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,1)')->execute([$readonly]);
    [, $readForm]=authTestRequest($server,'/login.php',$cookies['readonly']);portalCheck(authTestRequest($server,'/login.php',$cookies['readonly'],['csrf'=>authTestToken($readForm),'login'=>$readonlyLogin,'password'=>$password])[0]===303,'Non-admin sign-in failed.');portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['readonly'])[0]===303&&authTestRequest($server,'/solicitudes-demo.php',$cookies['readonly'],$update)[0]===303,'Non-admin accessed platform follow-up.');
    // A second login and password rotation invalidate all previous sessions.
    [, $form]=authTestRequest($server,'/portal-demo.php',$cookies['expired']);portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['expired'],['csrf'=>authTestToken($form),'action'=>'login','email'=>'PROSPECT@example.test','password'=>$password])[0]===303,'Case-insensitive portal login failed.');
    $newPassword=bin2hex(random_bytes(12));portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],['csrf'=>authTestToken($page),'action'=>'password','current_password'=>$password,'password'=>$newPassword,'password_confirmation'=>$newPassword])[0]===303,'Password change failed.');
    [, $expired]=authTestRequest($server,'/portal-demo.php',$cookies['expired']);portalCheck(!str_contains($expired,'Private prospect ONE'),'Password rotation left old session active.');
    [, $page]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],['csrf'=>authTestToken($page),'action'=>'logout'])[0]===303,'Logout failed.');
    [, $form]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],['csrf'=>authTestToken($form),'action'=>'login','email'=>$signup['email'],'password'=>$password])[0]===401,'Old password still works.');
    portalCheck(authTestRequest($server,'/portal-demo.php',$cookies['one'],['csrf'=>authTestToken($form),'action'=>'login','email'=>$signup['email'],'password'=>$newPassword])[0]===303,'New password rejected.');
    $db->prepare('UPDATE commercial_portal_accounts SET active=0 WHERE id=?')->execute([$account['id']]);[, $disabled]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(!str_contains($disabled,'Private prospect ONE'),'Disabled account session remained active.');
    $snapshot=$db->query('SELECT * FROM commercial_demo_requests ORDER BY id')->fetchAll();$migration($db);$migration($db);portalCheck($snapshot===$db->query('SELECT * FROM commercial_demo_requests ORDER BY id')->fetchAll(),'Migration repeat changed requests.');
    portalCheck($db->query('SELECT portal_account_id FROM commercial_demo_requests WHERE id='.$legacyId)->fetchColumn()===null,'Legacy anonymous request assigned to new account.');
    $db->exec('DELETE FROM commercial_demo_rate_limits');for($n=0;$n<10;$n++)try{commercialPortalLogin($db,['email'=>'missing@example.test','password'=>'wrong'],'rate-ip');}catch(InvalidArgumentException $e){portalCheck($e->getCode()===401,'Unexpected login throttle.');}
    try{commercialPortalLogin($db,['email'=>'missing@example.test','password'=>'wrong'],'rate-ip');throw new RuntimeException('Login rate limit missing.');}catch(InvalidArgumentException $e){portalCheck($e->getCode()===429,'Login limit missing.');}
    if(in_array('--browser',$argv,true)){
        $db->exec('DELETE FROM commercial_demo_rate_limits');$env=getenv();$env['NEXO_TEST_BASE_URL']=$server['url'];$env['NEXO_TEST_LOGIN']=$commercialEmail;$env['NEXO_TEST_PASSWORD']=$commercialPassword;
        $process=proc_open(['node',__DIR__.'/commercial-site-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);portalCheck(proc_close($process)===0,'Browser review failed.');
    }

    [, $commercialAccount]=authTestRequest($server,'/admin-comercial.php?view=cuenta',$cookies['admin']);$adminNewPassword=bin2hex(random_bytes(12));
    $changeAdmin=['csrf'=>authTestToken($commercialAccount),'action'=>'password','current_password'=>$commercialPassword,'password'=>$adminNewPassword,'password_confirmation'=>$adminNewPassword];
    portalCheck(authTestRequest($server,'/admin-comercial.php?view=cuenta',$cookies['admin'],array_replace($changeAdmin,['csrf'=>'bad']))[0]===403,'Admin password change missing CSRF.');
    portalCheck(authTestRequest($server,'/admin-comercial.php?view=cuenta',$cookies['admin'],$changeAdmin)[0]===303,'Admin password change failed.');
    [, $newAdminLogin]=authTestRequest($server,'/admin-comercial.php',$cookies['adminother']);
    portalCheck(authTestRequest($server,'/admin-comercial.php',$cookies['adminother'],['csrf'=>authTestToken($newAdminLogin),'action'=>'login','email'=>$commercialEmail,'password'=>$commercialPassword])[0]===401,'Old commercial password still works.');
    portalCheck(authTestRequest($server,'/admin-comercial.php',$cookies['adminother'],['csrf'=>authTestToken($newAdminLogin),'action'=>'login','email'=>$commercialEmail,'password'=>$adminNewPassword])[0]===303,'New commercial password rejected.');
    [, $logoutForm]=authTestRequest($server,'/solicitudes-demo.php',$cookies['admin']);portalCheck(authTestRequest($server,'/admin-comercial.php',$cookies['admin'],['csrf'=>authTestToken($logoutForm),'action'=>'logout'])[0]===303,'Commercial logout failed.');
    portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['admin'])[0]===303,'Commercial session survived logout.');
    $db->prepare('UPDATE commercial_admin_accounts SET active=0 WHERE id=?')->execute([$commercialAdmin]);portalCheck(authTestRequest($server,'/solicitudes-demo.php',$cookies['adminother'])[0]===303,'Disabled commercial admin retained access.');
    $adminMigration($db);$adminMigration($db);portalCheck((int)$db->query('SELECT COUNT(*) FROM commercial_admin_accounts')->fetchColumn()===1,'Repeated admin migration changed accounts.');
    echo "PASS: portal registration/login, isolated accounts, private ERP, owner-scoped requests, retry protection, public history/private notes, password rotation, logout, rate limiting and repeat migration.\n";
}finally{foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);if($server)stopAuthTestServer($server);}

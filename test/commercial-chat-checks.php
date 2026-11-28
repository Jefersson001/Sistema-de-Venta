<?php
// Included in the isolated portal integration fixture, before its sessions expire.
require_once dirname(__DIR__).'/php/commercial-chat.php';
$clientChat='/conversacion-demo.php?side=client&request='.$lead['id'];
$adminChat='/conversacion-demo.php?side=admin&request='.$lead['id'];
portalCheck(authTestRequest($server,$clientChat,$cookies['guest'])[0]===401,'Guest reached chat.');
portalCheck(authTestRequest($server,$adminChat,$cookies['erpadmin'])[0]===401,'ERP admin reached commercial chat.');
portalCheck(authTestRequest($server,$clientChat,$cookies['two'])[0]===404,'Other client read chat.');
portalCheck(authTestRequest($server,$adminChat,$cookies['one'])[0]===401,'Client impersonated commercial admin.');
[, $chatPage]=authTestRequest($server,'/portal-demo.php?request='.$lead['id'],$cookies['one']);
$chatPost=['csrf'=>authTestToken($chatPage),'action'=>'send','message'=>'CLIENT QUESTION <script>bad()</script>','client_key'=>bin2hex(random_bytes(16))];
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],array_replace($chatPost,['csrf'=>'bad']))[0]===403,'Chat CSRF missing.');
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],array_replace($chatPost,['message'=>str_repeat('x',2001)]))[0]===422,'Oversized message accepted.');
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],array_replace($chatPost,['message'=>'  ']))[0]===422,'Empty message accepted.');
portalCheck(authTestRequest($server,$clientChat,$cookies['two'],$chatPost)[0]===404,'Other client sent a message.');
[$code,$sent]=authTestRequest($server,$clientChat,$cookies['one'],$chatPost);portalCheck($code===200,'Client could not send.');$sentId=json_decode($sent,true)['id'];
[, $retry]=authTestRequest($server,$clientChat,$cookies['one'],$chatPost);portalCheck(json_decode($retry,true)['id']===$sentId,'Message retry duplicated.');
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],array_replace($chatPost,['message'=>'changed']))[0]===409,'Changed retry accepted.');
portalCheck((int)(commercialChatUnread($db,'admin',$commercialAdmin,[(int)$lead['id']])[$lead['id']]??0)===1,'Admin unread missing.');
[, $adminChatPage]=authTestRequest($server,'/solicitudes-demo.php?request='.$lead['id'],$cookies['admin']);$adminCsrf=authTestToken($adminChatPage);
portalCheck(authTestRequest($server,$adminChat,$cookies['admin'],['csrf'=>$adminCsrf,'action'=>'read','last_id'=>$sentId])[0]===200,'Read receipt failed.');
portalCheck(commercialChatUnread($db,'admin',$commercialAdmin,[(int)$lead['id']])===[],'Read did not clear unread.');
[, $unreadInbox]=authTestRequest($server,'/portal-demo.php',$cookies['one']);
[$code,$reply]=authTestRequest($server,$adminChat,$cookies['admin'],['csrf'=>$adminCsrf,'action'=>'send','message'=>'ADMIN REPLY','client_key'=>bin2hex(random_bytes(16))]);portalCheck($code===200,'Admin reply failed.');$replyId=json_decode($reply,true)['id'];
portalCheck((int)(commercialChatUnread($db,'client',(int)$account['id'],[(int)$lead['id']])[$lead['id']]??0)===1,'Client unread missing.');
[$notificationCode,$notificationJson]=authTestRequest($server,'/conversacion-demo.php?side=client&notifications=1',$cookies['one']);$notifications=json_decode($notificationJson,true);
portalCheck($notificationCode===200&&$notifications['total']>=1&&in_array((int)$lead['id'],array_column($notifications['items'],'request_id'),true),'Client notifications missing.');
portalCheck(str_contains($notificationJson,'ADMIN REPLY')&&!str_contains($notificationJson,'PRIVATE-NOTE'),'Notifications missing preview or leaking private notes.');
[, $otherNotifications]=authTestRequest($server,'/conversacion-demo.php?side=client&notifications=1',$cookies['two']);portalCheck(!in_array((int)$lead['id'],array_column(json_decode($otherNotifications,true)['items'],'request_id'),true),'Notifications leaked another client request.');
portalCheck(authTestRequest($server,'/conversacion-demo.php?side=admin&notifications=1',$cookies['guest'])[0]===401,'Guest read notifications.');
portalCheck(authTestRequest($server,'/conversacion-demo.php?side=admin&notifications=1',$cookies['erpadmin'])[0]===401,'ERP user read commercial notifications.');
portalCheck(authTestRequest($server,'/conversacion-demo.php?side=admin&notifications=1',$cookies['one'])[0]===401,'Client read administrative notifications.');
portalCheck((int)(commercialChatUnread($db,'client',(int)$account['id'],[(int)$lead['id']])[$lead['id']]??0)===1,'Checking notifications marked unread messages read.');
[, $unreadInbox]=authTestRequest($server,'/portal-demo.php',$cookies['one']);portalCheck(str_contains($unreadInbox,'1 sin leer'),'Unread badge missing from client list.');
[, $feedJson]=authTestRequest($server,$clientChat.'&after='.$sentId,$cookies['one']);$feed=json_decode($feedJson,true);portalCheck(count($feed['messages'])===1&&$feed['messages'][0]['body']==='ADMIN REPLY','Incremental chat feed wrong.');
portalCheck(!str_contains($feedJson,'PRIVATE-NOTE')&&!str_contains($feedJson,'SECRET-ADMIN-ONLY'),'Chat leaked internal notes.');
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],['csrf'=>$chatPost['csrf'],'action'=>'read','last_id'=>99999999])[0]===404,'Invalid read cursor accepted.');
portalCheck(authTestRequest($server,$clientChat,$cookies['one'],['csrf'=>$chatPost['csrf'],'action'=>'read','last_id'=>$replyId])[0]===200,'Client read failed.');
$beforeMessages=$db->query('SELECT * FROM commercial_chat_messages ORDER BY id')->fetchAll();$chatMigration($db);$chatMigration($db);
portalCheck($beforeMessages===$db->query('SELECT * FROM commercial_chat_messages ORDER BY id')->fetchAll(),'Repeated chat migration changed messages.');
portalCheck(!$db->query("SELECT table_name FROM company_schema_tables WHERE table_name IN ('commercial_chat_messages','commercial_chat_reads')")->fetchColumn(),'Chat registered as operational company data.');
$db->beginTransaction();
try{
    $fixture=$db->prepare("INSERT INTO commercial_chat_messages(request_id,sender_type,sender_id,sender_name,body,created_at) VALUES(?,'admin',?,'QA','Pagination fixture',?)");
    $now=(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s');
    for($n=0;$n<70;$n++)$fixture->execute([$legacyId,$commercialAdmin,$now]);
    $latest=commercialChatFeed($db,$legacyId);portalCheck(count($latest['messages'])===60&&$latest['more'],'Chat history is not paginated.');
    $older=commercialChatFeed($db,$legacyId,0,$latest['messages'][0]['id']);portalCheck(count($older['messages'])===11&&!$older['more'],'Older chat history is lost.');
}finally{$db->rollBack();}
// Saving notes after the old public editor was removed must preserve that reply.
$q=$db->prepare('SELECT * FROM commercial_demo_requests WHERE id=?');$q->execute([$lead['id']]);$current=$q->fetch();
commercialUpdateDemo($db,['id'=>$current['id'],'version'=>$current['version'],'status'=>$current['status'],'internal_notes'=>$current['internal_notes']],$commercialAdmin);
$q->execute([$lead['id']]);portalCheck($q->fetch()['public_message']===$current['public_message'],'Saving notes erased public reply.');
echo "PASS: chat identity/ownership, CSRF, validation, retries, unread/read cursors, private notes isolation and repeat migration.\n";

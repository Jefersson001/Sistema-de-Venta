<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$target=$db->query("SELECT * FROM document_share_links WHERE origin_kind='invoice' AND origin_id=".$invoice.' ORDER BY id DESC LIMIT 1')->fetch();$rowCount=(int)$db->query('SELECT COUNT(*) FROM document_share_links')->fetchColumn();
wsCheck($target['marked_sent_at']===null,'Preparation already marked as sent.');
$mark=['csrf'=>$post['csrf'],'action'=>'mark_whatsapp_sent','share_id'=>$target['id'],'interaction'=>'copy'];
wsCheck(authTestRequest($s,$url,$cookie,array_replace($mark,['csrf'=>'invalid']))[0]===403,'Sent mark accepted invalid CSRF.');
wsCheck(authTestRequest($s,$url,$cookie,array_replace($mark,['interaction'=>'received']))[0]===422,'Unknown interaction accepted.');
wsCheck(authTestRequest($s,$url,$cookie,$mark)[0]===303,'Copy did not mark sent.');$marked=$db->query('SELECT * FROM document_share_links WHERE id='.$target['id'])->fetch();wsCheck($marked['marked_sent_at']!==null&&$marked['marked_sent_action']==='copy','Copy state not stored.');
wsCheck(authTestRequest($s,$url,$cookie,array_replace($mark,['interaction'=>'open']))[0]===303,'Repeated action failed.');$again=$db->query('SELECT * FROM document_share_links WHERE id='.$target['id'])->fetch();wsCheck($again['marked_sent_at']===$marked['marked_sent_at']&&$again['marked_sent_action']==='copy'&&(int)$db->query('SELECT COUNT(*) FROM document_share_links')->fetchColumn()===$rowCount,'Repeated action changed first mark or duplicated a message.');
$unmarked=$db->query('SELECT * FROM document_share_links WHERE id='.$withFiles['id'])->fetch();wsCheck($unmarked['marked_sent_at']===null,'Unexpected initial state.');$open=array_replace($mark,['share_id'=>$unmarked['id'],'interaction'=>'open']);wsCheck(authTestRequest($s,'/modules/ventas/compartir.php?id='.$sale,$cookie,$open)[0]===422&&$db->query('SELECT marked_sent_at FROM document_share_links WHERE id='.$unmarked['id'])->fetchColumn()===null,'Wrong origin marked another record.');
wsCheck(authTestRequest($s,$url,$cookie,$open)[0]===303&&$db->query('SELECT marked_sent_action FROM document_share_links WHERE id='.$unmarked['id'])->fetchColumn()==='open','Open state not stored.');
wsCheck(str_contains(authTestRequest($s,$url,$cookie)[1],'Enviado'),'Saved sent status not rendered.');$rows=$db->query('SELECT * FROM document_share_links ORDER BY id')->fetchAll();$sentMigration($db);wsCheck($rows===$db->query('SELECT * FROM document_share_links ORDER BY id')->fetchAll(),'Migration repeat erased sent marks.');
echo "PASS: Enviado por abrir/copiar, persistencia, CSRF, origen autorizado, primera acción conservada y migración repetida sin pérdida de datos.\n";

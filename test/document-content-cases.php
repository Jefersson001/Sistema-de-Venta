<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Run inside the existing isolated SMTP or WhatsApp fixture; never use a real mail server.
$isWhatsapp=isset($configUrl);
$check=$isWhatsapp?'wsCheck':'cmCheck';
$channel=$isWhatsapp?'whatsapp':'mail';
$templateValues=[
    'message_template_order'=>'VENTA PERSONALIZADA {cliente}: {documento}. {productos}',
    'message_template_delivery'=>'ENTREGA PERSONALIZADA {cliente}: {documento}. {productos}',
    'message_template_invoice'=>'FACTURA PERSONALIZADA {cliente}: {documento}. {productos}. Total: {total}. Banco QA',
];
$settingsRoute='/modules/ajustes/'.($isWhatsapp?'whatsapp.php':'correo.php');
[, $settingsPage]=authTestRequest($s,$settingsRoute,$cookie);
foreach($templateValues as $field=>$value)$check(str_contains($settingsPage,'name="'.$field.'"'),'Missing template field: '.$field);
$currentSettings=$isWhatsapp?loadWhatsappSettings($db,1):loadCompanyMail($db,1);
$settingsInput=$isWhatsapp?$config:$settings;
$settingsInput=array_replace($settingsInput,$templateValues,['csrf'=>authTestToken($settingsPage),'action'=>$isWhatsapp?'save_whatsapp':'save_mail','version'=>(string)$currentSettings['version']]);
unset($settingsInput['message_template']);
$response=authTestRequest($s,$settingsRoute,$cookie,$settingsInput);
$check($response[0]===303,'Could not save separate templates: '.$response[0]);
$currentSettings=$isWhatsapp?loadWhatsappSettings($db,1):loadCompanyMail($db,1);
foreach($templateValues as $field=>$value)$check($currentSettings[$field]===$value,'Template not persisted independently: '.$field);
$originRoute='/modules/ventas/'.($isWhatsapp?'compartir':'enviar').'.php?id='.$sale;
foreach(['order'=>['VENTA PERSONALIZADA',$isWhatsapp?'VENTA-WHATSAPP':'VENTA-CORREO'],'delivery'=>['ENTREGA PERSONALIZADA',$isWhatsapp?'ENTREGA-WHATSAPP':'ENTREGA-CORREO'],'invoice'=>['FACTURA PERSONALIZADA',$isWhatsapp?'FACTURA-WHATSAPP':'FACTURA-CORREO']] as $messageKind=>[$text,$documentName]){
    $preview=authTestRequest($s,$originRoute.'&'.http_build_query(['preview_messages'=>'1','documents'=>['message:'.$messageKind,'ticket:'.$invoice]]),$cookie);
    $json=json_decode($preview[1],true,16,JSON_THROW_ON_ERROR);
    $check($preview[0]===200&&$json['ok']&&str_contains($json['message'],$text)&&str_contains($json['message'],$documentName),'Message did not use related native document: '.$messageKind);
    if($messageKind==='delivery')$check(!str_contains($json['message'],'Precio:')&&!str_contains($json['message'],'Tasa del documento:'),'Delivery message used invoice financial values.');
}
$preview=authTestRequest($s,$originRoute.'&'.http_build_query(['preview_messages'=>'1','documents'=>['message:order','message:delivery','message:invoice']]),$cookie);
$body=json_decode($preview[1],true,16,JSON_THROW_ON_ERROR)['message'];
$check(str_contains($body,'VENTA PERSONALIZADA')&&str_contains($body,'ENTREGA PERSONALIZADA')&&str_contains($body,'FACTURA PERSONALIZADA'),'Combined messages missing a template.');
$check(authTestRequest($s,$originRoute.'&'.http_build_query(['preview_messages'=>'1','documents'=>['ticket:99999999']]),$cookie)[0]===422,'Preview allowed an unrelated ticket.');
[, $compose]=authTestRequest($s,$url,$cookie);
$ticketPost=array_replace($post,['csrf'=>authTestToken($compose),'request_key'=>bin2hex(random_bytes(32)),'documents'=>['message:invoice','ticket:'.$invoice]]);
if($isWhatsapp){$ticketPost['confirm_share']='yes';$ticketPost['previous_share_id']=(string)whatsappPreviousPreparation($db,'invoice',$invoice,1)['id'];}
else{$ticketPost['confirm_resend']='yes';$ticketPost['previous_log_id']=(string)emailPreviousDelivery($db,'invoice',$invoice,1)['id'];}
$response=authTestRequest($s,$url,$cookie,$ticketPost);
$check($response[0]===303,'Ticket combination failed: '.mb_substr(strip_tags($response[1]),-1000));
if($isWhatsapp){
    $ticketEntry=$db->query('SELECT * FROM document_share_links ORDER BY id DESC LIMIT 1')->fetch();
    $ticketFiles=$db->query('SELECT * FROM document_share_files WHERE share_id='.$ticketEntry['id'])->fetchAll();
    $check(count($ticketFiles)===1&&$ticketFiles[0]['document_key']==='ticket:'.$invoice&&str_starts_with($ticketFiles[0]['pdf_data'],'%PDF-'),'Ticket snapshot missing.');
    $ticketPdf=$ticketFiles[0]['pdf_data'];$ticketUrls=whatsappShareUrls($ticketEntry);parse_str(parse_url($ticketUrls['public'],PHP_URL_QUERY),$ticketQuery);
    $download=authTestRequest($s,'/documentos.php?'.http_build_query(['token'=>$ticketQuery['token'],'file'=>$ticketFiles[0]['id']]),$guest);
    $check($download[0]===200&&$download[1]===$ticketPdf&&str_contains($ticketUrls['message'],'FACTURA PERSONALIZADA'),'Public ticket or message incorrect.');
    $check(authTestRequest($s,$url,$cookie,$ticketPost)[0]===303&&(int)$db->query('SELECT MAX(id) FROM document_share_links')->fetchColumn()===(int)$ticketEntry['id'],'Ticket retry duplicated link.');
}else{
    $ticketEntry=$db->query('SELECT * FROM document_email_log ORDER BY id DESC LIMIT 1')->fetch();
    $check($ticketEntry['status']==='sent'&&str_contains($ticketEntry['body'],'FACTURA PERSONALIZADA'),'Ticket mail did not use invoice template.');
    $outbox=file($dir.'/outbox');$mime=json_decode(end($outbox),true)['message'];
    $check(substr_count($mime,'Content-Type: application/pdf')===1&&str_contains($mime,'Ticket-de-factura'),'Ticket PDF not attached in SMTP.');
    $ticketPdf=emailDocumentPdf($db,emailSourceChoices($db,emailSource($db,'invoice',$invoice),'invoice')['ticket:'.$invoice]);
    $check(authTestRequest($s,$url,$cookie,$ticketPost)[0]===303&&count(file($dir.'/outbox'))===count($outbox),'Ticket retry sent duplicate email.');
}
preg_match('~/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([0-9.]+)\s+([0-9.]+)\s*\]~',$ticketPdf,$paper);
$check(count($paper)===3&&abs((float)$paper[1]-80*72/25.4)<1&&(float)$paper[2]<2000,'Ticket PDF has wrong width or unfitted height.');
$beforeSplit=$db->query('SELECT * FROM company_'.$channel.'_settings ORDER BY id')->fetchAll();$splitMigration($db);$splitMigration($db);
$check($beforeSplit===$db->query('SELECT * FROM company_'.$channel.'_settings ORDER BY id')->fetchAll(),'Migration overwrote customized templates.');
echo 'PASS: '.$channel." separate templates, combined messages, related native values, ticket PDF, preview authorization and frozen retry.\n";

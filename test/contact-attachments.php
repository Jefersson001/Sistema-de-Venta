<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/auth-server.php';
function attachmentExpect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function attachmentPost(array $server,string $path,string $cookie,array $fields): array {
    $curl=curl_init($server['url'].$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_POSTFIELDS=>$fields,CURLOPT_TIMEOUT=>20]);
    $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($curl,CURLINFO_REDIRECT_URL);curl_close($curl);
    attachmentExpect(is_string($body)&&!preg_match('/Fatal error|Warning(?:<\/b>)?:|Notice(?:<\/b>)?:/',$body),'PHP error during upload: '.strip_tags((string)$body));
    return [$code,$body,$redirect];
}
$server=startAuthTestServer();$cookie=tempnam(sys_get_temp_dir(),'attachment-cookie-');$anonymous=tempnam(sys_get_temp_dir(),'attachment-anon-');$fixtures=[];
try {
    $db=$server['db'];$login='attachment.admin.'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(14));$token=prepareFirstAdministrator($db);
    $admin=createFirstAdministrator($db,['name'=>'Attachment admin','login'=>$login,'password'=>$password,'password_confirmation'=>$password],hash('sha256',$token));
    [, $body]=authTestRequest($server,'/login.php',$cookie);authTestRequest($server,'/login.php',$cookie,['csrf'=>authTestToken($body),'login'=>$login,'password'=>$password]);
    [$status,$body]=authTestRequest($server,'/modules/contactos/index.php?new=1',$cookie);
    attachmentExpect($status===200&&str_contains($body,'tab-attachments')&&str_contains($body,'multipart/form-data'),'Missing attachment form.');
    attachmentExpect(strpos($body,'id="tab-inventory"')<strpos($body,'id="tab-attachments"')&&strpos($body,'id="tab-attachments"')<strpos($body,'id="tab-notes"'),'Incorrect tab order.');
    $data=['csrf'=>authTestToken($body),'company_scope'=>authTestToken($body,'company_scope'),'action'=>'save_contact','entity_kind'=>'person','type_id'=>$db->query("SELECT id FROM contact_types WHERE system_key='client'")->fetchColumn(),'name'=>'Contacto con adjuntos'];
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDbcAAAAASUVORK5CYII=');
    foreach(['png'=>$png,'pdf'=>"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",'bad'=>'<?php echo "unsafe"; ?>'] as $key=>$bytes){$fixtures[$key]=tempnam(sys_get_temp_dir(),'attachment-file-');file_put_contents($fixtures[$key],$bytes);}
    $files=['contact_files[0]'=>new CURLFile($fixtures['png'],'image/png','foto.png'),'attachment_descriptions[0]'=>'Foto de identificación','contact_files[1]'=>new CURLFile($fixtures['pdf'],'application/pdf','contrato.pdf'),'attachment_descriptions[1]'=>'Contrato'];
    [$status,,$redirect]=attachmentPost($server,'/modules/contactos/index.php?new=1',$cookie,$data+$files);
    attachmentExpect($status===303,'Contact/files did not save.');parse_str(parse_url($redirect,PHP_URL_QUERY),$query);$id=(int)$query['id'];
    $rows=$db->query('SELECT * FROM contact_attachments WHERE contact_id='.$id.' ORDER BY id')->fetchAll();
    attachmentExpect(count($rows)===2&&$rows[0]['description']==='Foto de identificación','Rows did not persist.');
    foreach($rows as $row)attachmentExpect(is_file($server['sessions'].'/contact-uploads/'.$row['storage_key'].'.bin'),'Private file missing.');
    $path='/modules/contactos/archivo.php?id='.$id.'&file='.$rows[0]['id'];
    [$status,$bytes,,$headers]=authTestRequest($server,$path,$cookie);
    attachmentExpect($status===200&&$bytes===$png&&str_contains(implode('',$headers),'image/png'),'Photo delivery failed.');
    [$status,,,$headers]=authTestRequest($server,$path.'&download=1',$cookie);
    attachmentExpect($status===200&&str_contains(implode('',$headers),'attachment;'),'Download disposition missing.');
    [$status]=authTestRequest($server,$path,$anonymous);attachmentExpect($status===303,'Anonymous file access accepted.');
    [$status]=authTestRequest($server,str_replace('id='.$id.'&','id=999999999&',$path),$cookie);attachmentExpect($status===404,'Attachment served under a different contact.');
    [$status]=authTestRequest($server,'/php/uploads/contacts/'.$rows[0]['storage_key'].'.bin',$cookie);attachmentExpect($status===403,'Private storage exposed.');
    [$status,$body]=attachmentPost($server,'/modules/contactos/index.php?id='.$id,$cookie,array_replace($data,['name'=>'Cambio rechazado','contact_files[0]'=>new CURLFile($fixtures['bad'],'image/png','falso.png')]));
    attachmentExpect($status===200&&str_contains($body,'formato no admitido'),'Fake image accepted.');
    attachmentExpect($db->query('SELECT name FROM contacts WHERE id='.$id)->fetchColumn()==='Contacto con adjuntos','Failed upload changed contact.');
    [$status]=attachmentPost($server,'/modules/contactos/index.php?id='.$id,$cookie,array_replace($data,['csrf'=>'bad'])+$files);
    attachmentExpect($status===403,'Invalid CSRF accepted.');
    [$status,$body]=attachmentPost($server,'/modules/contactos/index.php?id='.$id,$cookie,$data+['remove_attachments[0]'=>'999999999']);
    attachmentExpect($status===200&&str_contains($body,'pertenece a otro contacto'),'Invalid removal accepted.');
    [$status]=attachmentPost($server,'/modules/contactos/index.php?id='.$id,$cookie,$data+['remove_attachments[0]'=>(string)$rows[0]['id']]);
    attachmentExpect($status===303&&!is_file($server['sessions'].'/contact-uploads/'.$rows[0]['storage_key'].'.bin'),'Removal did not clean private file.');
    attachmentExpect((int)$db->query('SELECT COUNT(*) FROM contact_attachments WHERE contact_id='.$id)->fetchColumn()===1,'Removal affected the wrong rows.');
    // Database trigger also prevents attaching records from another company.
    $db->exec("INSERT INTO business_company(name) VALUES('Otra empresa adjuntos')");$other=(int)$db->lastInsertId();$db->exec('SET @nexo_company_id='.$other);
    try {$db->prepare('INSERT INTO contact_attachments(contact_id,storage_key,filename,mime_type,byte_size) VALUES(?,?,?,?,?)')->execute([$id,bin2hex(random_bytes(32)),'test.txt','text/plain',1]);throw new RuntimeException('Cross-company relation accepted.');}catch(PDOException $e){attachmentExpect(str_contains($e->getMessage(),'empresas diferentes'),'Unexpected company guard error.');}finally{$db->exec('SET @nexo_company_id=1');}
    echo "PASS: multipart photos/documents, descriptions, private viewing/downloads, tab order, invalid file rollback, CSRF, removal and company isolation.\n";
} finally {
    foreach(array_merge($fixtures,[$cookie,$anonymous]) as $file)if(is_file($file))unlink($file);
    stopAuthTestServer($server);
}

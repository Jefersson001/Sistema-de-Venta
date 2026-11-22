<?php
declare(strict_types=1);
require_once __DIR__.'/document-email.php';
require_once __DIR__.'/document-share-errors.php';
function whatsappDefaults(): array {return ['enabled'=>0,'country_code'=>'58','expiry_days'=>7,'message_template'=>"Hola {cliente}. Te compartimos los documentos de {documento} de {empresa}: {enlace}",'version'=>0];}
function loadWhatsappSettings(PDO $db,int $company): array {$q=$db->prepare('SELECT enabled,country_code,expiry_days,message_template,version FROM company_whatsapp_settings WHERE company_id=?');$q->execute([$company]);return $q->fetch()?:whatsappDefaults();}
function whatsappTemplate(mixed $value): string {
    $text=mailText($value,2000,true);if(!str_contains($text,'{enlace}'))throw new InvalidArgumentException('Incluye {enlace} en el mensaje.');preg_match_all('/\{([^{}]+)\}/',$text,$matches);foreach($matches[1] as $key)if(!in_array($key,['cliente','empresa','documento','enlace'],true))throw new InvalidArgumentException('Utiliza solo {cliente}, {empresa}, {documento} y {enlace}.');return $text;
}
function whatsappPhone(mixed $value,string $country): string {
    $text=mailText($value,40);if(!preg_match('/^\+?[0-9().\s-]+$/D',$text))throw new InvalidArgumentException('Escribe un teléfono válido con su código de país.');
    $digits=preg_replace('/\D/','',$text);$explicit=str_starts_with($text,'+')||str_starts_with($digits,'00');if(str_starts_with($digits,'00'))$digits=substr($digits,2);
    if(!$explicit&&!(str_starts_with($digits,$country)&&strlen($digits)>strlen($country)+7))$digits=$country.ltrim($digits,'0');
    if(!preg_match('/^[1-9][0-9]{7,14}$/D',$digits))throw new InvalidArgumentException('El número internacional debe tener entre 8 y 15 dígitos.');return $digits;
}
function documentPublicBaseUrl(): string {
    $url=rtrim(trim(getenv('NEXO_PUBLIC_URL')?:''),'/');
    if($url===''){$host=$_SERVER['HTTP_HOST']??'';if(preg_match('/^(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?$/D',$host))return 'http://'.$host.authBasePath();throw new InvalidArgumentException('Configura NEXO_PUBLIC_URL con el dominio HTTPS público del sistema en el servidor.');}
    $parts=parse_url($url);if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||strlen($url)>500||!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('NEXO_PUBLIC_URL debe ser una dirección HTTPS completa, sin usuario, consulta ni fragmento.');return $url;
}
function saveWhatsappSettings(PDO $db,array $input): void {
    permissionRequire('ajustes.whatsapp','configure');$country=mailText($input['country_code']??'',3);if(!preg_match('/^[1-9][0-9]{0,2}$/D',$country))throw new InvalidArgumentException('Indica el código de país sin +. Por ejemplo: 58.');
    $days=filter_var($input['expiry_days']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>30]]);if(!$days)throw new InvalidArgumentException('La vigencia debe estar entre 1 y 30 días.');$template=whatsappTemplate($input['message_template']??'');$company=companyActiveId();
    $db->beginTransaction();try{$q=$db->prepare('SELECT id FROM business_company WHERE id=? FOR UPDATE');$q->execute([$company]);$current=loadWhatsappSettings($db,$company);if((int)($input['version']??-1)!==(int)$current['version'])throw new InvalidArgumentException('La configuración cambió. Recarga antes de guardar.',409);
        $db->prepare('INSERT INTO company_whatsapp_settings(company_id,enabled,country_code,expiry_days,message_template) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),country_code=VALUES(country_code),expiry_days=VALUES(expiry_days),message_template=VALUES(message_template),version=version+1')->execute([$company,(int)(($input['enabled']??'')==='1'),$country,$days,$template]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function prepareWhatsappShare(CompanyConnection $db,string $kind,int $id,string $type,array $input): int {
    $source=emailSource($db,$kind,$id,$type);$company=companyActiveId();$settings=loadWhatsappSettings($db,$company);if(!$settings['enabled'])throw new InvalidArgumentException('Habilita WhatsApp en la configuración de esta empresa.');
    $phone=whatsappPhone($input['phone']??'',$settings['country_code']);$template=whatsappTemplate($input['message_template']??$settings['message_template']);$base=documentPublicBaseUrl();
    $selected=$input['documents']??null;if(!is_array($selected)||!count($selected)||count($selected)>20)throw new InvalidArgumentException('Selecciona entre 1 y 20 documentos.');foreach($selected as $value)if(!is_string($value))throw new InvalidArgumentException('Selección de documentos no válida.');$selected=array_values(array_unique($selected));sort($selected);
    $key=$input['request_key']??null;if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Recarga la ventana antes de preparar el enlace.');
    $hash=hash('sha256',json_encode([$kind,$id,$type,$phone,$template,$selected],JSON_THROW_ON_ERROR));$q=$db->prepare('SELECT id,payload_hash FROM document_share_links WHERE company_id=? AND request_key=?');$q->execute([$company,$key]);if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se utilizó con otros datos.',409);return (int)$old['id'];}
    $choices=emailSourceChoices($db,$source,$kind);foreach($selected as $value)if(!isset($choices[$value]))throw new InvalidArgumentException('Solo puedes compartir documentos autorizados relacionados con este registro.');
    $q=$db->prepare('SELECT name FROM business_company WHERE id=?');$q->execute([$company]);$companyName=(string)$q->fetchColumn();$message=strtr($template,['{cliente}'=>$source['contact_name'],'{empresa}'=>$companyName,'{documento}'=>$source['name']]);if(mb_strlen($message)>5000)throw new InvalidArgumentException('El mensaje es demasiado largo. Reduce el texto o las variables repetidas.');
    $token=bin2hex(random_bytes(32));try{$cipher=companyMailEncrypt($token,$company);}catch(Throwable $e){throw new DocumentSharePreparationException('encryption',$e);}$expires=(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->modify('+'.(int)$settings['expiry_days'].' days')->format('Y-m-d H:i:s');
    $db->beginTransaction();try{
        // Build every PDF before inserting: a failed render never leaves a partial bundle.
        $files=[];$bytes=0;foreach($selected as $value){try{$pdf=emailDocumentPdf($db,$choices[$value]);}catch(Throwable $e){throw new DocumentSharePreparationException('pdf',$e);}$bytes+=strlen($pdf);if($bytes>12*1024*1024)throw new InvalidArgumentException('Los documentos superan 12 MB. Comparte menos documentos.');$files[]=['key'=>$value,'label'=>$choices[$value]['label'],'filename'=>mb_substr(preg_replace('/[^\pL\pN._-]+/u','-',$choices[$value]['label']),0,220).'.pdf','pdf'=>$pdf];}
        $db->prepare('INSERT INTO document_share_links(origin_kind,origin_id,invoice_id,sale_id,operation_id,request_key,payload_hash,token_hash,token_cipher,recipient_phone,message_text,base_url,company_name,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$kind,$id,$kind==='invoice'?$id:null,$kind==='order'?$id:($source['sale_id']??null),$kind==='delivery'?$id:null,$key,$hash,hash('sha256',$token),$cipher,$phone,$message,$base,$companyName,$expires]);$share=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO document_share_files(share_id,document_key,label,filename,pdf_data) VALUES(?,?,?,?,?)');foreach($files as $file)$q->execute([$share,$file['key'],$file['label'],$file['filename'],$file['pdf']]);$db->commit();return $share;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062){$q=$db->prepare('SELECT id,payload_hash FROM document_share_links WHERE company_id=? AND request_key=?');$q->execute([$company,$key]);$old=$q->fetch();if($old&&hash_equals($old['payload_hash'],$hash))return (int)$old['id'];}throw $e;}
}
function revokeWhatsappShare(CompanyConnection $db,string $kind,int $id,string $type,int $share): void {
    emailSource($db,$kind,$id,$type);$db->prepare('UPDATE document_share_links SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE id=? AND origin_kind=? AND origin_id=? AND company_id=?')->execute([$share,$kind,$id,companyActiveId()]);
}
function whatsappShareUrls(array $share): array {
    $token=companyMailDecrypt($share['token_cipher'],(int)$share['company_id']);$url=$share['base_url'].'/documentos.php?token='.$token;$message=str_replace('{enlace}',$url,$share['message_text']);return ['public'=>$url,'message'=>$message,'whatsapp'=>'https://wa.me/'.$share['recipient_phone'].'?text='.rawurlencode($message)];
}

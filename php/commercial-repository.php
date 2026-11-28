<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/commercial-content.php';
function commercialText(mixed $value,int $max,bool $required=false): string {
    if(!is_string($value)||mb_strlen($value)>$max||str_contains($value,"\0"))throw new InvalidArgumentException('Revisa la longitud y el formato de los campos.');
    $value=trim($value);if($required&&$value==='')throw new InvalidArgumentException('Completa los campos obligatorios.');return $value;
}
function commercialValidateDemo(array $input): array {
    $data=[];
    foreach(['full_name'=>160,'business_name'=>180,'email'=>254,'phone'=>40,'city'=>120,'requirements'=>2000] as $field=>$max)$data[$field]=commercialText($input[$field]??'',$max,in_array($field,['full_name','business_name','email','phone'],true));
    if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$data['email']))throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    if(!preg_match('/^\+?[0-9() .-]+$/D',$data['phone'])||!preg_match('/^[0-9]{7,15}$/D',preg_replace('/\D/','',$data['phone'])))throw new InvalidArgumentException('Escribe un teléfono válido, con su código de país si corresponde.');
    $choices=['sector'=>array_keys(commercialSectors()),'company_count'=>['1','2','3','4','5+'],'user_count'=>['1-3','4-6','7-12','13-25','26+'],'plan_key'=>array_merge(['undecided'],array_keys(commercialPlans())),'contact_method'=>['whatsapp','phone','email']];
    foreach($choices as $field=>$options){$value=$input[$field]??'';if(!is_string($value)||!in_array($value,$options,true))throw new InvalidArgumentException('Selecciona el tipo de negocio, las empresas, los usuarios, el plan y el medio de contacto.');$data[$field]=$value;}
    if(($input['consent']??null)!=='1')throw new InvalidArgumentException('Autoriza que te contactemos para coordinar la demostración.');
    return $data;
}
function commercialBlockingDemo(PDO $db,int $owner,?int $except=null): ?array {
    $sql="SELECT id,status FROM commercial_demo_requests WHERE portal_account_id=? AND status<>'rejected'";$params=[$owner];
    if($except!==null){$sql.=' AND id<>?';$params[]=$except;}
    $q=$db->prepare($sql.' ORDER BY id DESC LIMIT 1'.($db->inTransaction()?' FOR UPDATE':''));$q->execute($params);return $q->fetch()?:null;
}
function commercialSaveDemo(PDO $db,array $data,string $key,string $ip,?int $owner=null): int {
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Recarga el formulario antes de enviarlo.');
    if($owner!==null)$data['portal_account_id']=$owner;
    $hash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));$now=new DateTimeImmutable('now',new DateTimeZone('America/Caracas'));
    $db->beginTransaction();
    try{
        if($owner!==null){
            $q=$db->prepare('SELECT id FROM commercial_portal_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$owner]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Tu cuenta no está disponible. Inicia sesión de nuevo.',403);
        }
        $bucket=hash('sha256','konta-demo:'.$ip);
        $db->prepare('INSERT INTO commercial_demo_rate_limits(bucket,window_started,hits) VALUES(?,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)')->execute([$bucket,$now->format('Y-m-d H:i:s')]);
        $q=$db->prepare('SELECT window_started,hits FROM commercial_demo_rate_limits WHERE bucket=? FOR UPDATE');$q->execute([$bucket]);$limit=$q->fetch();
        $q=$db->prepare('SELECT id,payload_hash FROM commercial_demo_requests WHERE request_key=?');$q->execute([$key]);
        if($existing=$q->fetch()){if(!hash_equals($existing['payload_hash'],$hash))throw new InvalidArgumentException('Esta solicitud ya se utilizó. Recarga para enviar una nueva.',409);$db->commit();return (int)$existing['id'];}
        if($owner!==null&&commercialBlockingDemo($db,$owner))throw new InvalidArgumentException('Ya tienes una solicitud de demo. Solo podrás enviar otra si administración rechaza esa solicitud.',409);
        $expired=$limit['window_started']<=$now->modify('-1 hour')->format('Y-m-d H:i:s');$hits=$expired?0:(int)$limit['hits'];
        if($hits>=5)throw new InvalidArgumentException('Ya recibimos varias solicitudes desde esta conexión. Inténtalo más tarde.',429);
        $db->prepare('UPDATE commercial_demo_rate_limits SET hits=?,window_started=? WHERE bucket=?')->execute([$hits+1,$expired?$now->format('Y-m-d H:i:s'):$limit['window_started'],$bucket]);
        $fields=array_keys($data);$q=$db->prepare('INSERT INTO commercial_demo_requests (request_key,payload_hash,'.implode(',',$fields).',consented_at,internal_notes) VALUES ('.implode(',',array_fill(0,count($fields)+4,'?')).')');$q->execute(array_merge([$key,$hash],array_values($data),[$now->format('Y-m-d H:i:s'),'']));
        $id=(int)$db->lastInsertId();
        if($owner!==null)$db->prepare('INSERT INTO commercial_demo_events(request_id,status,message) VALUES(?,?,?)')->execute([$id,'new','Recibimos tu solicitud de demostración.']);
        $db->commit();return $id;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062){$q=$db->prepare('SELECT id,payload_hash FROM commercial_demo_requests WHERE request_key=?');$q->execute([$key]);$existing=$q->fetch();if($existing&&hash_equals($existing['payload_hash'],$hash))return (int)$existing['id'];}
        throw $e;
    }
}
function commercialUpdateDemo(PDO $db,array $input,int $actor): void {
    $actorQuery=$db->prepare('SELECT id FROM commercial_admin_accounts WHERE id=? AND active=1');$actorQuery->execute([$actor]);
    if(!$actorQuery->fetchColumn())throw new InvalidArgumentException('Inicia sesión con una cuenta de administración comercial.',403);
    $id=filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$version=filter_var($input['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$status=$input['status']??null;
    if(!$id||!$version||!is_string($status)||!isset(commercialStatuses()[$status]))throw new InvalidArgumentException('Revisa la solicitud y su estado.');
    $notes=commercialText($input['internal_notes']??'',2000);
    $message=commercialText($input['public_message']??'',2000);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT portal_account_id FROM commercial_demo_requests WHERE id=?');$q->execute([$id]);$owner=$q->fetchColumn();
        if($owner){
            $q=$db->prepare('SELECT id FROM commercial_portal_accounts WHERE id=? FOR UPDATE');$q->execute([$owner]);$q->fetchColumn();
            if($status!=='rejected'&&commercialBlockingDemo($db,(int)$owner,(int)$id))throw new InvalidArgumentException('Este cliente ya tiene otra solicitud sin rechazar. No puedes reabrir esta solicitud mientras exista la otra.',409);
        }
        $q=$db->prepare('SELECT status,public_message FROM commercial_demo_requests WHERE id=? AND version=? FOR UPDATE');$q->execute([$id,$version]);$previous=$q->fetch();
        if(!$previous)throw new InvalidArgumentException('La solicitud cambió. Recarga antes de guardar.',409);
        if($status==='approved'&&$previous['status']!=='approved'&&($message===''||$message===($previous['public_message']??'')))$message='Tu demo fue aprobada. Ya puedes ir al login del sistema con el usuario y la contraseña que te haya entregado administración.';
        $q=$db->prepare('UPDATE commercial_demo_requests SET status=?,internal_notes=?,public_message=?,updated_by=NULL,updated_by_commercial_admin=?,version=version+1 WHERE id=? AND version=?');$q->execute([$status,$notes,$message,$actor,$id,$version]);
        if($status!==$previous['status']||$message!==($previous['public_message']??''))$db->prepare('INSERT INTO commercial_demo_events(request_id,status,message) VALUES(?,?,?)')->execute([$id,$status,$message]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

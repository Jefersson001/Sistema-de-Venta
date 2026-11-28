<?php
declare(strict_types=1);
require_once __DIR__.'/commercial-admin-auth.php';

function commercialChatRequest(PDO $db,int $request,string $side,int $actor): array {
    if(!in_array($side,['client','admin'],true)||$actor<1)throw new InvalidArgumentException('Inicia sesión para conversar.',401);
    $q=$db->prepare('SELECT id,portal_account_id FROM commercial_demo_requests WHERE id=?');$q->execute([$request]);$row=$q->fetch();
    if(!$row||($side==='client'&&(int)$row['portal_account_id']!==$actor))throw new InvalidArgumentException('Solicitud no disponible.',404);
    return $row;
}
function commercialChatUnread(PDO $db,string $side,int $actor,array $ids): array {
    if(!$ids)return [];
    $ids=array_values(array_unique(array_map('intval',$ids)));$other=$side==='client'?'admin':'client';
    $q=$db->prepare('SELECT m.request_id,COUNT(*) AS quantity FROM commercial_chat_messages m LEFT JOIN commercial_chat_reads r ON r.request_id=m.request_id AND r.reader_type=? AND r.reader_id=? WHERE m.request_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND m.sender_type=? AND m.id>COALESCE(r.last_message_id,0) GROUP BY m.request_id');
    $q->execute(array_merge([$side,$actor],$ids,[$other]));return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
function commercialChatNotifications(PDO $db,string $side,int $actor): array {
    $other=$side==='client'?'admin':'client';
    $from=' FROM commercial_chat_messages m JOIN commercial_demo_requests d ON d.id=m.request_id LEFT JOIN commercial_chat_reads r ON r.request_id=m.request_id AND r.reader_type=? AND r.reader_id=? WHERE m.sender_type=? AND m.id>COALESCE(r.last_message_id,0)';
    $params=[$side,$actor,$other];
    if($side==='client'){$from.=' AND d.portal_account_id=?';$params[]=$actor;}
    $q=$db->prepare('SELECT COUNT(*)'.$from);$q->execute($params);$total=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT m.request_id,COUNT(*) AS quantity,MAX(m.id) AS latest_id'.$from.' GROUP BY m.request_id ORDER BY latest_id DESC LIMIT 12');$q->execute($params);$groups=$q->fetchAll();$items=[];
    if($groups){
        $ids=array_map(static fn($group)=>(int)$group['latest_id'],$groups);
        $q=$db->query('SELECT m.id,m.sender_name,m.body,m.created_at,d.business_name FROM commercial_chat_messages m JOIN commercial_demo_requests d ON d.id=m.request_id WHERE m.id IN ('.implode(',',$ids).')');$messages=[];
        foreach($q->fetchAll() as $message)$messages[(int)$message['id']]=$message;
        foreach($groups as $group){$message=$messages[(int)$group['latest_id']];$items[]=['request_id'=>(int)$group['request_id'],'count'=>(int)$group['quantity'],'name'=>$side==='admin'?$message['business_name']:$message['sender_name'],'preview'=>mb_substr($message['body'],0,140),'created_at'=>$message['created_at']];}
    }
    return ['total'=>$total,'items'=>$items];
}
function commercialChatFeed(PDO $db,int $request,int $after=0,int $before=0): array {
    $condition=$before>0?' AND id<?':($after>0?' AND id>?':'');
    $params=[$request];if($condition!=='')$params[]=$before>0?$before:$after;
    $ascending=$after>0&&$before===0;
    $q=$db->prepare('SELECT id,sender_type,sender_id,sender_name,body,created_at FROM commercial_chat_messages WHERE request_id=?'.$condition.' ORDER BY id '.($ascending?'ASC':'DESC').' LIMIT 61');$q->execute($params);$rows=$q->fetchAll();
    $more=count($rows)>60;if($more)array_pop($rows);if(!$ascending)$rows=array_reverse($rows);
    foreach($rows as &$row){$row['id']=(int)$row['id'];$row['sender_id']=(int)$row['sender_id'];}unset($row);
    return ['messages'=>$rows,'more'=>$more];
}
function commercialChatSend(PDO $db,int $request,string $side,array $user,array $input): int {
    $key=$input['client_key']??'';
    if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Recarga la conversación antes de enviar.');
    $body=commercialText($input['message']??'',2000,true);
    $actor=(int)$user['id'];commercialChatRequest($db,$request,$side,$actor);
    $db->beginTransaction();
    try{
        // Serialize a request so retries and read cursors cannot race new messages.
        $q=$db->prepare('SELECT id FROM commercial_demo_requests WHERE id=? FOR UPDATE');$q->execute([$request]);
        $q=$db->prepare('SELECT id,body FROM commercial_chat_messages WHERE request_id=? AND sender_type=? AND sender_id=? AND client_key=?');$q->execute([$request,$side,$actor,$key]);
        if($old=$q->fetch()){
            if(!hash_equals($old['body'],$body))throw new InvalidArgumentException('Ese mensaje ya se utilizó con otro contenido.',409);
            $db->commit();return (int)$old['id'];
        }
        $q=$db->prepare('SELECT COUNT(*) FROM commercial_chat_messages WHERE sender_type=? AND sender_id=? AND created_at>=?');$q->execute([$side,$actor,(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->modify('-1 minute')->format('Y-m-d H:i:s')]);
        if((int)$q->fetchColumn()>=30)throw new InvalidArgumentException('Espera un momento antes de enviar más mensajes.',429);
        $q=$db->prepare('INSERT INTO commercial_chat_messages(request_id,sender_type,sender_id,sender_name,body,client_key,created_at) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$request,$side,$actor,$user[$side==='client'?'full_name':'name'],$body,$key,(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s')]);
        $id=(int)$db->lastInsertId();$db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function renderCommercialChat(): void {
    authStartSession();authPrivacyHeaders();header('Content-Type: application/json; charset=utf-8');
    try{
        $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))throw new InvalidArgumentException('Método no admitido.',405);
        $side=$_GET['side']??'';
        if(!is_string($side)||!in_array($side,['client','admin'],true))throw new InvalidArgumentException('Canal no válido.',400);
        $user=$side==='client'?commercialPortalUser():commercialAdminCurrent();if(!$user)throw new InvalidArgumentException('Tu sesión terminó. Inicia sesión de nuevo.',401);
        if($method==='GET'&&($_GET['notifications']??'')==='1'){
            echo json_encode(commercialChatNotifications(createDatabaseConnection(true),$side,(int)$user['id']),JSON_THROW_ON_ERROR);return;
        }
        $request=filter_var($_GET['request']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$request)throw new InvalidArgumentException('Solicitud no disponible.',404);
        $db=createDatabaseConnection(true);commercialChatRequest($db,$request,$side,(int)$user['id']);
        if($method==='POST'){
            if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new InvalidArgumentException('El mensaje es demasiado extenso.',413);
            if(!authCheckCsrf($_POST['csrf']??null))throw new InvalidArgumentException('Recarga la página antes de enviar.',403);
            if(($_POST['action']??'')==='send')$id=commercialChatSend($db,$request,$side,$user,$_POST);
            elseif(($_POST['action']??'')==='read'){
                $last=filter_var($_POST['last_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$last)throw new InvalidArgumentException('Mensaje no válido.',422);
                $q=$db->prepare('SELECT id FROM commercial_chat_messages WHERE id=? AND request_id=?');$q->execute([$last,$request]);if(!$q->fetchColumn())throw new InvalidArgumentException('Mensaje no disponible.',404);
                $q=$db->prepare('INSERT INTO commercial_chat_reads(request_id,reader_type,reader_id,last_message_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_message_id=GREATEST(last_message_id,VALUES(last_message_id))');$q->execute([$request,$side,$user['id'],$last]);
            }else throw new InvalidArgumentException('Acción no admitida.',422);
            echo json_encode(['ok'=>true,'id'=>$id??null],JSON_THROW_ON_ERROR);return;
        }
        $after=filter_var($_GET['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);$before=filter_var($_GET['before']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($after===false||$before===false)throw new InvalidArgumentException('Paginación no válida.',422);
        echo json_encode(commercialChatFeed($db,$request,$after,$before),JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[400,401,403,404,405,409,413,422,429],true)?$e->getCode():422);echo json_encode(['error'=>$e->getMessage()]);}
    catch(Throwable $e){error_log('Conversación comercial: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'No pudimos cargar la conversación. Inténtalo nuevamente.']);}
}

<?php
declare(strict_types=1);
function isDocumentMessageSelection(string $key): bool {
    return $key==='message'||in_array($key,['message:order','message:delivery','message:invoice'],true);
}
function documentChoicesWithTickets(array $choices): array {
    foreach($choices as $key=>$choice)if(str_starts_with($key,'invoice:')){
        $choices['ticket:'.$choice['id']]=array_replace($choice,['label'=>'Ticket de factura · '.(explode(' · ',$choice['label'],2)[1]??$choice['label']),'format'=>'ticket']);
    }
    return $choices;
}
function documentMessageOptions(array $choices): array {
    $options=[];
    foreach(documentMessageKinds() as $kind=>$label){
        $prefix=match($kind){'order'=>'order:','delivery'=>'operation:',default=>'invoice:'};
        $available=(bool)array_filter(array_keys($choices),static fn($key)=>str_starts_with($key,$prefix));
        $options['message:'.$kind]=['label'=>$label,'available'=>$available];
    }
    return $options;
}
function validateDocumentContentSelection(mixed $selected,array $choices): array {
    if(!is_array($selected)||!count($selected)||count($selected)>24)throw new InvalidArgumentException('Selecciona un mensaje o al menos un documento.');
    $messages=documentMessageOptions($choices);
    foreach($selected as $key){
        if(!is_string($key)||($key!=='message'&&!isset($choices[$key])&&empty($messages[$key]['available'])))throw new InvalidArgumentException('Solo puedes seleccionar mensajes y documentos autorizados relacionados con este registro.');
    }
    $selected=array_values(array_unique($selected));sort($selected);return $selected;
}
/** Prefer explicitly selected attachments; otherwise use the origin or first related document. */
function documentMessageTargets(array $choices,string $kind,array $selected,string $default): array {
    $prefix=match($kind){'order'=>'order:','delivery'=>'operation:',default=>'invoice:'};$all=[];$picked=[];
    foreach($choices as $key=>$choice){
        if(!str_starts_with($key,$prefix))continue;$all[$key]=$choice;
        if(in_array($key,$selected,true)||($kind==='invoice'&&in_array('ticket:'.$choice['id'],$selected,true)))$picked[$key]=$choice;
    }
    if($picked)return $picked;
    if(isset($all[$default]))return [$default=>$all[$default]];
    $key=array_key_first($all);return $key===null?[]:[$key=>$all[$key]];
}
function documentSelectedMessages(PDO $db,array $record,string $origin,array $settings,array $choices,array $selected,array $extraVariables=[]): string {
    $parts=[];$settings=companyDocumentMessageTemplates($settings);
    foreach(documentMessageKinds() as $kind=>$label){
        if(!in_array('message:'.$kind,$selected,true)&&!($origin===$kind&&in_array('message',$selected,true)))continue;
        $targets=documentMessageTargets($choices,$kind,$selected,$record['email_default']);
        if(!$targets)throw new InvalidArgumentException($label.': no hay un documento relacionado autorizado.');
        foreach($targets as $key=>$choice){
            $model=transferRouteModel($choice['source']);if(!$model)throw new InvalidArgumentException('Documento no disponible.');
            $data=businessDocumentData($db,$model,(int)$choice['id']);
            $context=$data['row']+['contact_name'=>$record['contact_name'],'email_default'=>$key];
            $templateSettings=$settings;$templateSettings['message_template']=$settings['message_template_'.$kind];
            $parts[]=emailRenderedMessage($db,$context,$kind,$templateSettings,[$key=>$choice],$extraVariables,$data);
        }
    }
    $body=implode("\n\n",$parts);if(strlen($body)>60000)throw new InvalidArgumentException('Los mensajes seleccionados son demasiado extensos. Reduce el contenido.');return $body;
}
function documentMessagePreviewResponse(PDO $db,array $record,string $kind,array $settings,array $choices,bool $whatsapp=false): bool {
    if($_SERVER['REQUEST_METHOD']!=='GET'||($_GET['preview_messages']??'')!=='1')return false;
    header('Content-Type: application/json; charset=UTF-8');
    try{
        $selected=validateDocumentContentSelection($_GET['documents']??[],$choices);
        $includeMessage=(bool)array_filter($selected,'isDocumentMessageSelection');
        $hasFiles=(bool)array_filter($selected,static fn($key)=>!isDocumentMessageSelection($key));
        $message=$whatsapp?whatsappRenderedMessage($db,$record,$kind,$settings,$choices,$includeMessage,$hasFiles,$selected):documentSelectedMessages($db,$record,$kind,$settings,$choices,$selected);
        echo json_encode(['ok'=>true,'message'=>$message],JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
    catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'message'=>'No se pudo generar la vista previa. Revisa las plantillas de esta empresa.'],JSON_THROW_ON_ERROR);}
    return true;
}

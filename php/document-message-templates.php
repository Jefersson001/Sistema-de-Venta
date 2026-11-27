<?php
declare(strict_types=1);
function documentMessageKinds(): array {return ['order'=>'Mensaje de venta','delivery'=>'Mensaje de entrega','invoice'=>'Mensaje de factura'];}
function companyDocumentMessageTemplates(array $settings): array {
    foreach(documentMessageKinds() as $kind=>$label)$settings['message_template_'.$kind]??=$settings['message_template']??companyMailDefaultMessage();
    return $settings;
}
function validateDocumentMessageTemplates(array $input,array $current,bool $whatsapp=false): array {
    $legacy=array_key_exists('message_template',$input)&&!array_intersect(array_keys($input),array_map(static fn($kind)=>'message_template_'.$kind,array_keys(documentMessageKinds())));
    $result=[];
    foreach(documentMessageKinds() as $kind=>$label){
        $field='message_template_'.$kind;$value=$legacy?$input['message_template']:($input[$field]??$current[$field]);
        try{$result[$field]=$whatsapp?whatsappTemplate($value):companyMailMessageTemplate($value);}
        catch(InvalidArgumentException $e){throw new InvalidArgumentException($label.': '.$e->getMessage());}
    }
    return $result;
}

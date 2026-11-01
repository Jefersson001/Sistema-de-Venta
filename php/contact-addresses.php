<?php
declare(strict_types=1);

function contactAddressText(array $record,array $catalogs=[]): string
{
    $parts=[trim((string)($record['address']??''))];
    foreach(['city_id'=>'cities','municipality_id'=>'municipalities','state_id'=>'states'] as $field=>$catalog){
        $value=$record[$catalog.'_name']??(contactCatalogRecord($catalogs[$catalog]??[],contactId($record[$field]??null))['name']??'');
        if($value!=='')$parts[]=$value;
    }
    if(!empty($record['postal_code']))$parts[]=$record['postal_code'];
    $country=$record['countries_name']??(contactCatalogRecord($catalogs['countries']??[],contactId($record['country_id']??null))['name']??'');if($country!=='')$parts[]=$country;
    return implode(', ',array_filter($parts,static fn($v)=>$v!==''));
}
function loadContactAddresses(PDO $db,?int $contact=null): array
{
    $sql='SELECT a.*,c.name AS countries_name,s.name AS states_name,m.name AS municipalities_name,t.name AS cities_name FROM contact_addresses a LEFT JOIN contact_countries c ON c.id=a.country_id LEFT JOIN contact_states s ON s.id=a.state_id LEFT JOIN contact_municipalities m ON m.id=a.municipality_id LEFT JOIN contact_cities t ON t.id=a.city_id';
    $q=$db->prepare($sql.($contact?' WHERE a.contact_id=?':'').' ORDER BY a.is_primary DESC,a.id');$q->execute($contact?[$contact]:[]);$rows=$q->fetchAll();
    foreach($rows as &$row)$row['formatted']=contactAddressText($row);unset($row);return $rows;
}
function contactAddressesRevision(array $rows): string
{
    return hash('sha256',json_encode(array_map(static fn($r)=>[(int)$r['id'],(int)$r['version']],$rows)));
}
function orderAddressCatalog(PDO $db,array $contacts): array
{
    $allowed=array_flip(array_map('intval',array_column($contacts,'id')));
    return array_values(array_filter(loadContactAddresses($db),static fn($r)=>isset($allowed[(int)$r['contact_id']])));
}
function validateContactGeography(array $input,array $catalogs,array &$errors): array
{
    $data=[];
    foreach(['country_id'=>'countries','state_id'=>'states','municipality_id'=>'municipalities','city_id'=>'cities'] as $field=>$catalog){
        $data[$field]=contactId($input[$field]??null);
        if(($input[$field]??'')!==''&&($input[$field]??null)!==null&&!contactCatalogRecord($catalogs[$catalog]??[],$data[$field]))$errors[]='La localización seleccionada no es válida.';
    }
    $state=contactCatalogRecord($catalogs['states']??[],$data['state_id']);$municipality=contactCatalogRecord($catalogs['municipalities']??[],$data['municipality_id']);$city=contactCatalogRecord($catalogs['cities']??[],$data['city_id']);
    if($state&&(int)$state['country_id']!==$data['country_id'])$errors[]='El estado debe pertenecer al país seleccionado.';
    if($municipality&&(int)$municipality['state_id']!==$data['state_id'])$errors[]='El municipio debe pertenecer al estado seleccionado.';
    if($city&&(int)$city['state_id']!==$data['state_id'])$errors[]='La ciudad debe pertenecer al estado seleccionado.';
    if($city&&!empty($city['municipality_id'])&&(int)$city['municipality_id']!==$data['municipality_id'])$errors[]='La ciudad debe pertenecer al municipio seleccionado.';
    return $data;
}
function syncContactPrimaryAddress(PDO $db,int $contact,array $data): void
{
    $fields=['address','postal_code','country_id','state_id','municipality_id','city_id','latitude','longitude'];$values=[];foreach($fields as $field)$values[$field]=$data[$field]??null;$values['address']??='';
    $q=$db->prepare('SELECT id FROM contact_addresses WHERE contact_id=? AND is_primary=1 FOR UPDATE');$q->execute([$contact]);$id=$q->fetchColumn();
    if($id)$db->prepare('UPDATE contact_addresses SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).',version=version+1 WHERE id=?')->execute([...array_values($values),$id]);
    else $db->prepare('INSERT INTO contact_addresses(contact_id,label,'.implode(',',$fields).',is_primary,default_invoice,default_delivery) VALUES(?,?'.str_repeat(',?',count($fields)).',1,1,1)')->execute([$contact,'Principal',...array_values($values)]);
}
function validateContactAddressLines(array $input,array $catalogs,array $existing): array
{
    if(($input['addresses_present']??'')!=='1')return [null,[]];
    $errors=[];$raw=$input['addresses']??[];$rows=[];$known=array_column($existing,null,'id');
    if(!is_array($raw)||count($raw)>100)return [null,['El formulario admite hasta 100 direcciones adicionales.']];
    if(!is_string($input['addresses_revision']??null)||!hash_equals(contactAddressesRevision($existing),$input['addresses_revision']))$errors[]='Las direcciones cambiaron en otra ventana. Recarga antes de guardar.';
    foreach($raw as $index=>$item){
        if(!is_array($item)||!ctype_digit((string)$index)){$errors[]='Las líneas de direcciones no son válidas.';continue;}
        $id=contactId($item['id']??null);
        if(($item['id']??'')!==''&&(!$id||!isset($known[$id])||$known[$id]['is_primary'])){$errors[]='La dirección no pertenece a este contacto.';continue;}
        $label=contactText($item,'label',80,$errors,'Nombre de dirección');$address=contactText($item,'address',255,$errors,'Dirección');
        if(!$id&&$label===''&&$address==='')continue;
        if($label===''||$address==='')$errors[]='Cada dirección adicional necesita un nombre y una dirección.';
        $usage=$item['usage_type']??'both';if(!in_array($usage,['both','invoice','delivery'],true)){$errors[]='Selecciona el uso de la dirección.';$usage='both';}
        $geo=validateContactGeography($item,$catalogs,$errors);
        [$coordinates,$coordinateErrors]=validateCoordinatePair($item,'latitude','longitude',$known[$id]??[]);$errors=array_merge($errors,$coordinateErrors);
        $rows[(string)$index]=['id'=>$id,'label'=>$label,'address'=>$address,'postal_code'=>contactText($item,'postal_code',20,$errors,'Código postal'),'usage_type'=>$usage,'active'=>($item['active']??'0')==='1'?1:0]+$geo+$coordinates;
    }
    $defaults=[];
    foreach(['invoice','delivery'] as $kind){
        $choice=$input['default_'.$kind.'_address']??'primary';if(!is_string($choice))$choice='invalid';
        if($choice!=='primary'){
            $key=str_starts_with($choice,'row_')?substr($choice,4):'';$row=$rows[$key]??null;
            if(!$row||!$row['active']||!in_array($row['usage_type'],['both',$kind],true))$errors[]='La dirección predeterminada debe estar activa y admitir su uso.';
        }
        $defaults[$kind]=$choice;
    }
    return [['rows'=>$rows,'defaults'=>$defaults],array_values(array_unique($errors))];
}
function saveContactAddressLines(PDO $db,int $contact,?array $changes): void
{
    if($changes===null)return;
    $q=$db->prepare('SELECT id FROM contact_addresses WHERE contact_id=? AND is_primary=1');$q->execute([$contact]);$map=['primary'=>(int)$q->fetchColumn()];
    foreach($changes['rows'] as $index=>$row){
        $id=$row['id'];unset($row['id']);$fields=array_keys($row);
        if($id)$db->prepare('UPDATE contact_addresses SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).',version=version+1 WHERE id=? AND contact_id=? AND is_primary=0')->execute([...array_values($row),$id,$contact]);
        else{$db->prepare('INSERT INTO contact_addresses(contact_id,'.implode(',',$fields).') VALUES(?'.str_repeat(',?',count($fields)).')')->execute([$contact,...array_values($row)]);$id=(int)$db->lastInsertId();}
        $map['row_'.$index]=$id;
    }
    $db->prepare('UPDATE contact_addresses SET default_invoice=0,default_delivery=0 WHERE contact_id=?')->execute([$contact]);
    foreach($changes['defaults'] as $kind=>$choice)$db->prepare('UPDATE contact_addresses SET default_'.$kind.'=1 WHERE id=? AND contact_id=?')->execute([$map[$choice],$contact]);
}

/** Resolve IDs using the selected partner, while preserving historical text. */
function resolveOrderAddresses(array $input,array $catalogs,?array $existing,?int $contact): array
{
    $rows=array_values(array_filter($catalogs['addresses']??[],static fn($r)=>(int)$r['contact_id']===$contact));$result=[];$errors=[];
    $same=$existing&&(int)($existing['customer_id']??0)===$contact;
    $customer=salesRecord($catalogs['customers'],$contact);$fallback=$customer?salesAddress($customer):'';
    foreach(['invoice','delivery'] as $kind){
        $field=$kind.'_address_id';$text=$kind.'_address';$oldId=contactId($existing[$field]??null);
        if(array_key_exists($field,$input)){
            $id=contactId($input[$field]);if($input[$field]!==''&&$input[$field]!==null&&!$id)$errors[]='Selecciona una dirección válida.';
            if(!$id&&!$same){$available=array_values(array_filter($rows,static fn($r)=>$r['active']&&in_array($r['usage_type'],['both',$kind],true)));$preferred=array_values(array_filter($available,static fn($r)=>$r['default_'.$kind]));$id=contactId(($preferred[0]??$available[0]??[])['id']??null);}
        }elseif($same)$id=$oldId;
        else{
            $available=array_values(array_filter($rows,static fn($r)=>$r['active']&&in_array($r['usage_type'],['both',$kind],true)));
            $preferred=array_values(array_filter($available,static fn($r)=>$r['default_'.$kind]));$id=contactId(($preferred[0]??$available[0]??[])['id']??null);
        }
        $preserve=$same&&$id===$oldId&&($input['refresh_'.$kind.'_address']??'')!=='1';
        $address=contactCatalogRecord($rows,$id);
        if($id&&(!$address||(!$preserve&&(!$address['active']||!in_array($address['usage_type'],['both',$kind],true)))))$errors[]='La dirección seleccionada no corresponde al contacto o no está disponible para ese uso.';
        $result[$field]=$id;$result[$text]=$preserve?($existing[$text]??''):($address['formatted']??$fallback);
    }
    return [$result,array_values(array_unique($errors))];
}

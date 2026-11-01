<?php
declare(strict_types=1);

/** Coordinates are not money: preserve six decimal places and signed values. */
function validateCoordinatePair(array $input,string $latitude='latitude',string $longitude='longitude',array $existing=[]): array
{
    if(!array_key_exists($latitude,$input)&&!array_key_exists($longitude,$input))return [[$latitude=>$existing[$latitude]??null,$longitude=>$existing[$longitude]??null],[]];
    $values=[];$errors=[];
    foreach([$latitude=>90,$longitude=>180] as $field=>$limit){
        $raw=$input[$field]??'';
        if($raw===''||$raw===null){$values[$field]=null;continue;}
        if(!is_string($raw)||!preg_match('/^-?[0-9]{1,3}(?:[.,][0-9]{1,6})?$/D',trim($raw))){$errors[]='Las coordenadas deben ser números con hasta seis decimales.';$values[$field]=null;continue;}
        $raw=str_replace(',','.',trim($raw));
        if(bccomp($raw,(string)-$limit,6)<0||bccomp($raw,(string)$limit,6)>0){$errors[]=$field===$latitude?'La latitud debe estar entre -90 y 90.':'La longitud debe estar entre -180 y 180.';$values[$field]=null;continue;}
        $values[$field]=bcadd($raw,'0',6);
    }
    if(($values[$latitude]===null)!==($values[$longitude]===null))$errors[]='Completa latitud y longitud, o deja ambas vacías.';
    return [$values,array_values(array_unique($errors))];
}
function geolocationMapUrl(mixed $latitude,mixed $longitude): ?string
{
    if($latitude===null||$latitude===''||$longitude===null||$longitude==='')return null;
    [$pair,$errors]=validateCoordinatePair(['latitude'=>(string)$latitude,'longitude'=>(string)$longitude]);if($errors)return null;
    return 'https://www.openstreetmap.org/?mlat='.rawurlencode($pair['latitude']).'&mlon='.rawurlencode($pair['longitude']).'#map=17/'.$pair['latitude'].'/'.$pair['longitude'];
}

function resolveSaleLocations(array $input,array $catalogs,?array $existing,array $addresses,?int $customer): array
{
    $errors=[];$same=$existing&&(int)$existing['customer_id']===$customer;
    $sameAddress=$same&&(int)($existing['delivery_address_id']??0)===(int)($addresses['delivery_address_id']??0)&&($input['refresh_delivery_address']??'')!=='1';
    $customInput=array_key_exists('customer_latitude',$input)||array_key_exists('customer_longitude',$input);
    $mode=$input['customer_location_source']??($customInput?'manual':($sameAddress?($existing['customer_location_source']??'address'):'address'));
    if(!in_array($mode,['address','manual','device'],true)){$errors[]='El origen de la ubicación del cliente no es válido.';$mode='address';}
    if($mode==='address'){
        $address=null;foreach($catalogs['addresses']??[] as $row)if((int)$row['id']===(int)($addresses['delivery_address_id']??0)&&(int)$row['contact_id']===$customer){$address=$row;break;}
        $client=['customer_latitude'=>$sameAddress?($existing['customer_latitude']??null):($address['latitude']??null),'customer_longitude'=>$sameAddress?($existing['customer_longitude']??null):($address['longitude']??null)];
    }else{[$client,$clientErrors]=validateCoordinatePair($input,'customer_latitude','customer_longitude',$sameAddress?($existing??[]):[]);$errors=array_merge($errors,$clientErrors);}
    [$sale,$saleErrors]=validateCoordinatePair($input,'sale_latitude','sale_longitude',$existing??[]);$errors=array_merge($errors,$saleErrors);
    $saleMode=$input['sale_location_source']??($existing['sale_location_source']??'manual');
    if(!in_array($saleMode,['manual','device'],true)){$errors[]='El origen de la ubicación de la venta no es válido.';$saleMode='manual';}
    $hasSale=$sale['sale_latitude']!==null&&$sale['sale_longitude']!==null;
    $changed=$hasSale&&($sale['sale_latitude']!==($existing['sale_latitude']??null)||$sale['sale_longitude']!==($existing['sale_longitude']??null)||$saleMode!==($existing['sale_location_source']??null));
    $recorded=$hasSale?($changed?(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s'):($existing['sale_location_recorded_at']??null)):null;
    $actor=$hasSale?($changed?(permissionRuntime()?(int)permissionUser()['id']:null):($existing['sale_location_recorded_by']??null)):null;
    return [$client+$sale+['customer_location_source'=>$mode,'sale_location_source'=>$saleMode,'sale_location_recorded_at'=>$recorded,'sale_location_recorded_by'=>$actor],$errors];
}

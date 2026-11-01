<?php
declare(strict_types=1);

function inventoryProductDetailFields(): array
{
    return ['barcode'=>'Código de barras','color'=>'Color','weight_kg'=>'Peso (kg)',
        'width_cm'=>'Ancho (cm)','height_cm'=>'Alto (cm)','depth_cm'=>'Profundidad (cm)'];
}

/** Omitted fields are preserved on updates; an explicit blank clears a field. */
function validateInventoryProductDetails(array $input): array
{
    $data=[];$errors=[];
    foreach(inventoryProductDetailFields() as $field=>$label){
        if(!array_key_exists($field,$input))continue;
        $raw=$input[$field];
        if($raw===null||$raw===''){$data[$field]=null;continue;}
        if(!is_string($raw)){$errors[]=$label.': escribe un valor válido.';continue;}
        $value=trim($raw);
        if($value===''){$data[$field]=null;continue;}
        if($field==='barcode'){
            if(!preg_match('/^[!-~]{1,100}$/D',$value)){$errors[]='El código de barras admite hasta 100 caracteres ASCII, sin espacios.';continue;}
        }elseif($field==='color'){
            if(mb_strlen($value,'UTF-8')>80||preg_match('/[\x00-\x1F\x7F]/u',$value)){$errors[]='El color admite hasta 80 caracteres, sin saltos de línea.';continue;}
        }else{
            $value=str_replace(',','.',$value);
            if(!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D',$value)){$errors[]=$label.': indica un valor no negativo, con hasta dos decimales y ocho dígitos enteros.';continue;}
            $value=bcadd($value,'0',2);
        }
        $data[$field]=$value;
    }
    return [$data,$errors];
}

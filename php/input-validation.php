<?php
declare(strict_types=1);

/** Formats concern data entry, not legal verification of an identity document. */
function documentInputFormat(?string $code): string
{
    return match(strtoupper($code??'')){
        '', 'V','E'=>'digits',
        'J','G','C'=>'tax-number',
        default=>'alphanumeric',
    };
}
function documentFormatError(string $number,?string $code): ?string
{
    if($number==='')return null;
    $format=documentInputFormat($code);
    $pattern=match($format){
        'digits'=>'/^[0-9]+$/D',
        'tax-number'=>'/^[0-9]+(?:-[0-9])?$/D',
        default=>'/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D',
    };
    if(preg_match($pattern,$number))return null;
    return match($format){
        'digits'=>'El número de documento solo admite dígitos; su letra se selecciona por separado.',
        'tax-number'=>'El documento admite dígitos y un guion opcional antes del dígito final; la letra se selecciona por separado.',
        default=>'El documento admite letras, dígitos y guiones entre grupos, sin espacios ni otros símbolos.',
    };
}
function phoneFormatError(string $phone,string $label='Teléfono'): ?string
{
    if($phone==='')return null;
    if(!preg_match('/^\+?[0-9 ()-]+$/D',$phone))return $label.': usa números, un + inicial opcional, espacios, guiones o paréntesis; no letras.';
    $balance=0;
    foreach(str_split($phone) as $character){
        if($character==='(')++$balance;
        if($character===')')--$balance;
        if($balance<0||$balance>1)return $label.': revisa los paréntesis del número.';
    }
    if($balance!==0)return $label.': cierra los paréntesis del número.';
    $digits=preg_replace('/[^0-9]/','',$phone);
    if(strlen($digits)<7||strlen($digits)>15)return $label.': indica entre 7 y 15 dígitos, incluyendo el código de país si lo usas.';
    return null;
}
function companyDocumentFormatError(string $number): ?string
{
    if($number===''||preg_match('/^(?:[A-Za-z]-?)?[0-9]+(?:-[0-9])?$/D',$number))return null;
    return 'Documento / RIF: indica los dígitos, con una letra inicial y un guion antes del dígito final si corresponden.';
}

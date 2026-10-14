<?php
declare(strict_types=1);
function productCommissionUnit(string $price,string $type,string $value): ?string
{
    if ($type==='none') return null;
    if ($type==='fixed') return bcadd($value,'0',2);
    if ($type==='percent') return bcdiv(bcadd(bcdiv(bcmul($price,$value,8),'100',8),'0.005',8),'1',2);
    throw new InvalidArgumentException('Selecciona un tipo de comisión válido.');
}

function salesCommissionAmount(string $quantity, string $price, ?string $reference,?string $unitCommission=null): string
{
    if ($unitCommission===null) {
        if ($reference === null || bccomp($price, $reference, 2) <= 0) return '0.00';
        $unitCommission=bcsub($price,$reference,2);
    }
    return bcadd(bcdiv(bcadd(bcmul($unitCommission,$quantity,5),'0.005',5),'1',2),'0',2);
}

function salesCommissionPortion(string $quantity,string $price,?string $reference,string $priorQuantity,?string $unitCommission=null): string
{
    if (bccomp($priorQuantity,'0',3)<0) $priorQuantity='0.000';
    return bcsub(salesCommissionAmount(bcadd($priorQuantity,$quantity,3),$price,$reference,$unitCommission),salesCommissionAmount($priorQuantity,$price,$reference,$unitCommission),2);
}

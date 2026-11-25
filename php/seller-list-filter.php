<?php
declare(strict_types=1);

/** Choices come from accessible documents, including sellers that are now inactive. */
function sellerListFilter(PDO $db,string $resource,string $table,string $alias,string $sellerColumn,string $condition='1=1'): array
{
    $raw=$_GET['salesperson_id']??'';
    $selected=$raw===''?'':($raw==='none'?'none':(is_string($raw)&&salesId($raw)?(string)salesId($raw):'invalid'));
    $q=$db->query('SELECT s.id,s.name FROM contacts s WHERE EXISTS(SELECT 1 FROM '.$table.' '.$alias.' WHERE '.$sellerColumn.'=s.id AND ('.$condition.') AND '.permissionWhere($resource,$alias).') ORDER BY s.name,s.id');
    return ['selected'=>$selected,'options'=>$q->fetchAll()];
}

function sellerListCondition(array $filter,string $column,array &$params): string
{
    if($filter['selected']==='')return '';
    if($filter['selected']==='none')return ' AND '.$column.' IS NULL';
    if($filter['selected']==='invalid')return ' AND 1=0';
    $params['filter_salesperson']=(int)$filter['selected'];return ' AND '.$column.'=:filter_salesperson';
}

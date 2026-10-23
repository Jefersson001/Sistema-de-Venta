<?php
declare(strict_types=1);
require_once __DIR__.'/payments-repository.php';

/** Current balances: only confirmed allocations reduce the debt. Pending payments remain visible. */
function outstandingInvoiceRows(PDO $db,string $type,string $condition,array $filters=[],array $flags=[]): array
{
    if(!in_array($type,['customer','supplier'],true))throw new InvalidArgumentException('Tipo de cuenta inválido.');
    $where=["i.invoice_type=?","i.status='posted'","i.document_type='invoice'","i.payment_state!='reversed'",'('.$condition.')'];$args=[$type];
    if(($filters['q']??'')!==''){$where[]='(c.name LIKE ? OR i.name LIKE ?)';$term='%'.$filters['q'].'%';array_push($args,$term,$term);}
    if($id=accessId($filters['contact_id']??null)){$where[]='i.contact_id=?';$args[]=$id;}
    $extra='';foreach($flags as $name=>$expression){if(!in_array($name,['show_business','show_task'],true))throw new LogicException('Indicador inválido.');$extra.=',('.$expression.') AS '.$name;}
    $q=$db->prepare("SELECT i.*,
        c.name AS contact_name,b.name AS company_name,
        COALESCE((SELECT SUM(p.amount) FROM accounting_invoice_payments p WHERE p.invoice_id=i.id AND p.status='settled'),0) AS paid,
        COALESCE((SELECT SUM(p.amount) FROM accounting_invoice_payments p WHERE p.invoice_id=i.id AND p.status='pending'),0) AS pending,
        COALESCE((SELECT SUM(n.total_amount) FROM accounting_invoices n WHERE n.reversal_of_id=i.id AND n.status='posted' AND n.document_type='credit'),0) AS credited
        $extra FROM accounting_invoices i JOIN contacts c ON c.id=i.contact_id JOIN business_company b ON b.id=i.company_id WHERE ".implode(' AND ',$where).' ORDER BY i.due_date IS NULL,i.due_date,i.id');
    $q->execute($args);$rows=[];$today=commerceToday();
    foreach($q->fetchAll() as $row){$row['balance']=bcsub(bcsub($row['total_amount'],$row['credited'],2),$row['paid'],2);if(bccomp($row['balance'],'0',2)<=0)continue;
        $row['overdue']=$row['due_date']!==null&&$row['due_date']<$today;
        if(($filters['due']??'all')==='overdue'&&!$row['overdue'])continue;
        $rows[]=$row;
    }
    return $rows;
}

function outstandingSummarize(array $rows): array
{
    $currencies=[];$groups=[];
    foreach($rows as $row){
        $code=$row['currency_code'];$key=$row['company_id'].':'.$row['contact_id'].':'.$code;
        $currencies[$code]??=['code'=>$code,'balance'=>'0.00','overdue'=>'0.00','pending'=>'0.00','count'=>0];
        $groups[$key]??=['company_id'=>(int)$row['company_id'],'company_name'=>$row['company_name'],'contact_id'=>(int)$row['contact_id'],'contact_name'=>$row['contact_name'],'code'=>$code,'balance'=>'0.00','overdue'=>'0.00','pending'=>'0.00','count'=>0,'invoices'=>[]];
        foreach(['balance','pending'] as $amount){$currencies[$code][$amount]=bcadd($currencies[$code][$amount],$row[$amount],2);$groups[$key][$amount]=bcadd($groups[$key][$amount],$row[$amount],2);}
        if($row['overdue']){$currencies[$code]['overdue']=bcadd($currencies[$code]['overdue'],$row['balance'],2);$groups[$key]['overdue']=bcadd($groups[$key]['overdue'],$row['balance'],2);}
        $currencies[$code]['count']++;$groups[$key]['count']++;$groups[$key]['invoices'][]=$row;
    }
    ksort($currencies);$groups=array_values($groups);
    usort($groups,static fn($a,$b)=>((int)(bccomp($b['overdue'],'0',2)>0)<=>(int)(bccomp($a['overdue'],'0',2)>0))?:strnatcasecmp($a['contact_name'],$b['contact_name'])?:($a['company_id']<=>$b['company_id'])?:strcmp($a['code'],$b['code']));
    return ['currencies'=>array_values($currencies),'groups'=>$groups,'count'=>count($rows),'group_count'=>count($groups)];
}

function outstandingFilters(array $input): array
{
    $q=$input['q']??'';$due=$input['due']??'all';$contact=$input['contact_id']??null;
    if(!is_string($q)||!is_string($due)||!in_array($due,['all','overdue'],true)||($contact!==null&&!accessId($contact)))throw new InvalidArgumentException('Revisa los filtros del informe.');
    return ['q'=>mb_substr(trim($q),0,160),'due'=>$due,'contact_id'=>accessId($contact)];
}

function loadOutstandingReport(PDO $db,array $filters=[],bool $dashboard=false): array
{
    $sections=['customer'=>null,'supplier'=>null];
    foreach($sections as $type=>$_){$resource=permissionInvoiceResource($type);
        $condition=$dashboard?permissionDashboardWhere('dashboard.business',$resource,'i'):(permissionHas($resource)?permissionWhere($resource,'i'):'1=0');
        if($condition==='1=0')continue;
        $sections[$type]=outstandingSummarize(outstandingInvoiceRows($db,$type,$condition,$filters));
    }
    return $sections;
}

function outstandingDashboardSection(array $rows): array
{
    $section=outstandingSummarize($rows);unset($section['groups']);return $section;
}

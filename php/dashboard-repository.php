<?php
declare(strict_types=1);
require_once __DIR__.'/payments-repository.php';
require_once __DIR__.'/operation-status-repository.php';
function dashboardPendingOrderCondition(string $kind,string $alias='o'): string
{
    $sale=$kind==='sale';$table=$sale?'sales_quotation_lines':'purchase_order_lines';$fk=$sale?'quotation_id':'order_id';$column=$sale?'sale_line_id':'purchase_line_id';
    $moved="COALESCE((SELECT SUM(CASE WHEN m.return_of_id IS NULL THEN ml.quantity ELSE -ml.quantity END) FROM inventory_operation_lines ml JOIN inventory_operations m ON m.id=ml.operation_id WHERE ml.$column=l.id AND m.status='done'),0)";
    $invoiced="COALESCE((SELECT SUM(CASE WHEN i.document_type='invoice' THEN il.quantity ELSE -il.quantity END) FROM accounting_invoice_lines il JOIN accounting_invoices i ON i.id=il.invoice_id WHERE il.$column=l.id AND ((i.document_type='invoice' AND i.status!='cancel') OR (i.document_type='credit' AND i.status='posted'))),0)";
    return "EXISTS(SELECT 1 FROM $table l WHERE l.$fk=$alias.id AND ((l.product_type='goods' AND l.quantity>$moved) OR (CASE WHEN l.invoice_policy='ordered' OR l.product_type='service' THEN l.quantity ELSE $moved END)>$invoiced))";
}
function loadDashboard(PDO $db): array
{
    $settings=accountingCurrencies($db);$d=['available'=>true,'settings'=>$settings,'sales'=>0,'purchases'=>0,'receivables'=>[],'payables'=>[],'overdue'=>0,'unallocated'=>[],'alerts'=>[],'alert_count'=>0,'tasks'=>[]];
    $d['sales']=(int)$db->query("SELECT COUNT(*) FROM sales_quotations o WHERE o.status='sale' AND ".dashboardPendingOrderCondition('sale'))->fetchColumn();
    $d['purchases']=(int)$db->query("SELECT COUNT(*) FROM purchase_orders o WHERE o.status='purchase' AND ".dashboardPendingOrderCondition('purchase'))->fetchColumn();
    $q=$db->query("SELECT i.id,i.name,i.invoice_type,i.currency_code,i.total_amount,i.due_date,c.name AS contact_name,
        COALESCE((SELECT SUM(p.amount) FROM accounting_invoice_payments p WHERE p.invoice_id=i.id AND p.status='settled'),0) AS paid,
        COALESCE((SELECT SUM(n.total_amount) FROM accounting_invoices n WHERE n.reversal_of_id=i.id AND n.status='posted'),0) AS credited
        FROM accounting_invoices i JOIN contacts c ON c.id=i.contact_id WHERE i.status='posted' AND i.document_type='invoice' AND i.payment_state!='reversed' ORDER BY i.due_date IS NULL,i.due_date,i.id");
    $today=commerceToday();
    foreach($q->fetchAll() as $i){$balance=bcsub(bcsub($i['total_amount'],$i['credited'],2),$i['paid'],2);if(bccomp($balance,'0',2)<=0)continue;$key=$i['invoice_type']==='customer'?'receivables':'payables';$code=$i['currency_code'];$d[$key][$code]=bcadd($d[$key][$code]??'0.00',$balance,2);
        if($i['due_date']&&$i['due_date']<$today){$d['overdue']++;if(count($d['tasks'])<6)$d['tasks'][]=['label'=>$i['name'].' · '.$i['contact_name'],'detail'=>'Vencida · '.$balance.' '.$code,'href'=>'modules/contabilidad/index.php?type='.$i['invoice_type'].'&id='.$i['id']];}
    }
    $q=$db->query("SELECT p.*,p.amount-COALESCE((SELECT SUM(l.payment_amount) FROM accounting_invoice_payments l WHERE l.payment_id=p.id AND l.status!='cancel'),0) AS available FROM accounting_payments p WHERE p.status IN('pending','settled')");
    foreach($q->fetchAll() as $p)if(bccomp($p['available'],'0',2)>0){$code=$p['currency_code'];$d['unallocated'][$code]=bcadd($d['unallocated'][$code]??'0.00',$p['available'],2);}
    $q=$db->query("SELECT p.id,p.name,COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s JOIN inventory_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.location_type='internal'),0)-COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.product_id=p.id),0) AS available FROM inventory_products p WHERE p.product_type='goods' AND (p.sale_enabled=1 OR p.purchase_enabled=1) HAVING available<=0 ORDER BY p.name,p.id");
    $alerts=$q->fetchAll();$d['alert_count']=count($alerts);$d['alerts']=array_slice($alerts,0,5);
    $q=$db->query("SELECT id,name,operation_type,scheduled_date,status FROM inventory_operations WHERE status IN('draft','waiting','ready') ORDER BY scheduled_date,id LIMIT 6");
    foreach($q->fetchAll() as $o)if(count($d['tasks'])<10)$d['tasks'][]=['label'=>$o['name'],'detail'=>operationStatuses()[$o['status']].' · '.$o['scheduled_date'],'href'=>'modules/inventario/operaciones.php?type='.$o['operation_type'].'&id='.$o['id']];
    $codes=accountingCurrencyCodes($settings);foreach(['receivables','payables','unallocated'] as $key)uksort($d[$key],static fn($a,$b)=>(array_search($a,$codes,true)===false?2:array_search($a,$codes,true))<=>(array_search($b,$codes,true)===false?2:array_search($b,$codes,true)));
    return $d;
}
function dashboardMoney(array $amounts): string
{
    if(!$amounts)return '0.00';$parts=[];foreach($amounts as $currency=>$amount)$parts[]=$amount.' '.$currency;return implode(' · ',$parts);
}

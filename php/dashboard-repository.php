<?php
declare(strict_types=1);
require_once __DIR__.'/payments-repository.php';
require_once __DIR__.'/operation-status-repository.php';
require_once __DIR__.'/outstanding-repository.php';
function dashboardPendingOrderCondition(string $kind,string $alias='o'): string
{
    $sale=$kind==='sale';$table=$sale?'sales_quotation_lines':'purchase_order_lines';$fk=$sale?'quotation_id':'order_id';$column=$sale?'sale_line_id':'purchase_line_id';
    $moved="COALESCE((SELECT SUM(CASE WHEN m.return_of_id IS NULL THEN ml.quantity ELSE -ml.quantity END) FROM inventory_operation_lines ml JOIN inventory_operations m ON m.id=ml.operation_id WHERE ml.$column=l.id AND m.status='done'),0)";
    $invoiced="COALESCE((SELECT SUM(CASE WHEN i.document_type='invoice' THEN il.quantity ELSE -il.quantity END) FROM accounting_invoice_lines il JOIN accounting_invoices i ON i.id=il.invoice_id WHERE il.$column=l.id AND ((i.document_type='invoice' AND i.status!='cancel') OR (i.document_type='credit' AND i.status='posted'))),0)";
    return "EXISTS(SELECT 1 FROM $table l WHERE l.$fk=$alias.id AND ((l.product_type='goods' AND l.quantity>$moved) OR (CASE WHEN l.invoice_policy='ordered' OR l.product_type='service' THEN l.quantity ELSE $moved END)>$invoiced))";
}
function loadDashboard(PDO $db): array
{
    $settings=accountingCurrencies($db);$d=['available'=>true,'settings'=>$settings,'sales'=>0,'purchases'=>0,'receivables'=>[],'payables'=>[],'overdue'=>0,'unallocated'=>[],'alerts'=>[],'alert_count'=>0,'tasks'=>[],'allowed_metrics'=>[],'balances'=>['customer'=>null,'supplier'=>null]];
    foreach(['sales'=>['ventas.orders','sales_quotations','sale'],'purchases'=>['compras.orders','purchase_orders','purchase']] as $key=>[$resource,$table,$status]) {
        $condition=permissionDashboardWhere('dashboard.business',$resource,'o');if($condition==='1=0')continue;
        $d[$key]=(int)$db->query("SELECT COUNT(*) FROM $table o WHERE o.status='$status' AND ($condition) AND ".dashboardPendingOrderCondition($key==='sales'?'sale':'purchase'))->fetchColumn();
        $d['allowed_metrics'][]=$key;
    }
    $today=commerceToday();
    foreach(['customer'=>'receivables','supplier'=>'payables'] as $type=>$key) {
        $resource=permissionInvoiceResource($type);$business=permissionDashboardWhere('dashboard.business',$resource,'i');$tasks=permissionDashboardWhere('dashboard.tasks',$resource,'i');
        if($business==='1=0'&&$tasks==='1=0')continue;if($business!=='1=0')$d['allowed_metrics'][]=$key;
        $rows=outstandingInvoiceRows($db,$type,"($business) OR ($tasks)",[],['show_business'=>$business,'show_task'=>$tasks]);
        if($business!=='1=0')$d['balances'][$type]=outstandingDashboardSection(array_values(array_filter($rows,static fn($row)=>(bool)$row['show_business'])));
        foreach($rows as $i) {
            $balance=bcsub(bcsub($i['total_amount'],$i['credited'],2),$i['paid'],2);if(bccomp($balance,'0',2)<=0)continue;$code=$i['currency_code'];
            if($i['show_business'])$d[$key][$code]=bcadd($d[$key][$code]??'0.00',$balance,2);
            if($i['due_date']&&$i['due_date']<$today){if($i['show_business'])$d['overdue']++;if($i['show_task']&&count($d['tasks'])<6)$d['tasks'][]=['label'=>$i['name'].' · '.$i['contact_name'],'detail'=>'Vencida · '.$balance.' '.$code,'href'=>'modules/contabilidad/index.php?type='.$type.'&id='.$i['id']];}
        }
    }
    if(in_array('receivables',$d['allowed_metrics'],true)||in_array('payables',$d['allowed_metrics'],true))$d['allowed_metrics'][]='overdue';
    foreach(['customer','supplier'] as $type) {
        if(!permissionHas('dashboard.business')||permissionScope('dashboard.business')==='own'||!permissionHas(permissionPaymentResource($type)))continue;
        $d['allowed_metrics'][]='unallocated';
        $q=$db->query("SELECT p.currency_code,p.amount-COALESCE((SELECT SUM(l.payment_amount) FROM accounting_invoice_payments l WHERE l.payment_id=p.id AND l.status!='cancel'),0) AS available FROM accounting_payments p WHERE p.partner_type='$type' AND p.status IN('pending','settled')");
        foreach($q->fetchAll() as $p)if(bccomp($p['available'],'0',2)>0){$code=$p['currency_code'];$d['unallocated'][$code]=bcadd($d['unallocated'][$code]??'0.00',$p['available'],2);}
    }
    if(permissionHas('dashboard.inventory')&&permissionHas('inventario.stock')&&permissionHas('inventario.products')) {
        $q=$db->query("SELECT p.id,p.name,COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s JOIN inventory_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.location_type='internal'),0)-COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.product_id=p.id),0) AS available FROM inventory_products p WHERE p.product_type='goods' AND (p.sale_enabled=1 OR p.purchase_enabled=1) HAVING available<=0 ORDER BY p.name,p.id");
        $alerts=$q->fetchAll();$d['alert_count']=count($alerts);$d['alerts']=array_slice($alerts,0,5);
    }
    foreach(['delivery','receipt','internal'] as $type) {
        $condition=permissionDashboardWhere('dashboard.tasks',permissionOperationResource($type),'o');if($condition==='1=0')continue;
        $q=$db->query("SELECT o.id,o.name,o.operation_type,o.scheduled_date,o.status FROM inventory_operations o WHERE o.operation_type='$type' AND ($condition) AND o.status IN('draft','waiting','ready') ORDER BY o.scheduled_date,o.id LIMIT 6");
        foreach($q->fetchAll() as $o)if(count($d['tasks'])<10)$d['tasks'][]=['label'=>$o['name'],'detail'=>operationStatuses()[$o['status']].' · '.$o['scheduled_date'],'href'=>'modules/inventario/operaciones.php?type='.$type.'&id='.$o['id']];
    }
    $codes=accountingCurrencyCodes($settings);
    foreach(['receivables','payables','unallocated'] as $key)uksort($d[$key],static fn($a,$b)=>(array_search($a,$codes,true)===false?2:array_search($a,$codes,true))<=>(array_search($b,$codes,true)===false?2:array_search($b,$codes,true)));
    $d['allowed_metrics']=array_values(array_unique($d['allowed_metrics']));return $d;
}
function permissionCashFlowSummary(PDO $db,array $range): array
{
    if(permissionHas('dashboard.cash_flow')&&permissionHas('contabilidad.cash_flow')){
        $summary=loadCashFlowSummary($db,$range);$summary['accounts']=loadCashFlowAccounts($db,$range);return $summary;
    }
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Caracas'));
    return ['range'=>$range,'currencies'=>[],'accounts'=>[],'count'=>0,'updated_at'=>$now->format(DATE_ATOM),'updated_time'=>$now->format('H:i:s')];
}
function permissionDashboardCommissions(PDO $db,array $range): array
{
    if(!permissionHas('dashboard.commissions')||!permissionHas('ventas.commissions'))return [];
    $seller=permissionScope('dashboard.commissions')==='own'?permissionContact():null;if($seller===0)return [];
    $groups=summarizeSalesCommissions(loadSalesCommissions($db,$range,$seller));
    foreach($groups as &$group)$group['can_pay']=accessPermissionAllows(permissionMap(),'ventas.commissions.pay',(int)$group['seller_id'],permissionContact())&&permissionHas('ventas.commission_payments')&&permissionHas('contabilidad.expense','create')&&permissionHas('contabilidad.expense','confirm');unset($group);return $groups;
}
function dashboardMoney(array $amounts): string
{
    if(!$amounts)return '0.00';$parts=[];foreach($amounts as $currency=>$amount)$parts[]=$amount.' '.$currency;return implode(' · ',$parts);
}

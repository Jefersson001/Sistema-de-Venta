<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$historyPath=$path.'?view=history';
$historyLink='href="index.php?id='.$cash.'"';
$readHistory=static function(string $status)use($server,$cookies,$historyPath):string{
    [$code,$html]=authTestRequest($server,$historyPath.'&'.http_build_query(['status'=>$status]),$cookies['seller']);
    dsExpect($code===200,'No carga el filtro de ventas: '.$status);return $html;
};
$q=$db->prepare('SELECT status,payment_state,sent_at,due_date FROM accounting_invoices WHERE id=?');$q->execute([$cashRow['invoice_id']]);$originalHistoryInvoice=$q->fetch();
try{
    $updateHistoryInvoice=$db->prepare('UPDATE accounting_invoices SET status=?,payment_state=?,sent_at=? WHERE id=?');
    foreach(['draft','posted','cancel'] as $invoiceState){
        $updateHistoryInvoice->execute([$invoiceState,'not_paid',null,$cashRow['invoice_id']]);
        dsExpect(str_contains($readHistory($invoiceState),$historyLink),'El estado de factura no incluye su venta.');
        dsExpect(!str_contains($readHistory($invoiceState==='posted'?'draft':'posted'),$historyLink),'El filtro mezcla estados de factura.');
        if($invoiceState==='cancel')dsExpect(!str_contains($readHistory('not_paid'),$historyLink),'Sin pagar incluye una factura cancelada.');
    }
    foreach(array_keys(invoicePaymentStates()) as $paymentState){
        $updateHistoryInvoice->execute(['posted',$paymentState,null,$cashRow['invoice_id']]);
        dsExpect(str_contains($readHistory($paymentState),$historyLink),'El estado de pago no incluye su venta: '.$paymentState);
        dsExpect(!str_contains($readHistory($paymentState==='paid'?'partial':'paid'),$historyLink),'El filtro mezcla estados de pago.');
    }
    $updateHistoryInvoice->execute(['posted','not_paid',commerceToday().' 12:00:00',$cashRow['invoice_id']]);
    dsExpect(str_contains($readHistory('sent'),$historyLink),'No incluye una factura enviada.');
    $updateHistoryInvoice->execute(['posted','not_paid',null,$cashRow['invoice_id']]);
    dsExpect(!str_contains($readHistory('sent'),$historyLink),'Enviado incluye una factura sin enviar.');
    $updateDue=$db->prepare('UPDATE accounting_invoices SET due_date=? WHERE id=?');
    $today=new DateTimeImmutable(commerceToday());
    foreach(['not_paid','partial','in_payment'] as $state){
        $updateHistoryInvoice->execute(['posted',$state,null,$cashRow['invoice_id']]);
        $updateDue->execute([$today->modify('-1 day')->format('Y-m-d'),$cashRow['invoice_id']]);
        dsExpect(str_contains($readHistory('overdue'),$historyLink),'Vencidas excluye una deuda vencida.');
    }
    foreach([['posted','paid',-1],['cancel','not_paid',-1],['draft','not_paid',-1],['posted','not_paid',0],['posted','partial',1]] as [$status,$state,$days]){
        $updateHistoryInvoice->execute([$status,$state,null,$cashRow['invoice_id']]);
        $updateDue->execute([$today->modify($days.' days')->format('Y-m-d'),$cashRow['invoice_id']]);
        dsExpect(!str_contains($readHistory('overdue'),$historyLink),'Vencidas incluye una factura pagada, cancelada, borrador o aún no vencida.');
    }
    $updateHistoryInvoice->execute(['posted','not_paid',null,$cashRow['invoice_id']]);
    $filtered=$readHistory('paid');dsExpect(str_contains($filtered,'No hay ventas con el estado seleccionado.')&&str_contains($filtered,'value="paid" selected'),'No conserva el filtro vacío.');
    dsExpect(str_contains($readHistory('all'),$historyLink)&&str_contains($readHistory("' OR 1=1 --"),$historyLink),'Todos o valor inválido no funcionan.');
    [$code,$arrayStatus]=authTestRequest($server,$historyPath.'&status[]=paid',$cookies['seller']);dsExpect($code===200&&str_contains($arrayStatus,$historyLink),'Un filtro malformado provoca un error.');
}finally{$updateHistoryInvoice->execute([$originalHistoryInvoice['status'],$originalHistoryInvoice['payment_state'],$originalHistoryInvoice['sent_at'],$cashRow['invoice_id']]);$db->prepare('UPDATE accounting_invoices SET due_date=? WHERE id=?')->execute([$originalHistoryInvoice['due_date'],$cashRow['invoice_id']]);}
echo "PASS: filtros de factura y cobro, enviado, exclusión de canceladas, selección conservada, estado vacío y valores inválidos seguros.\n";

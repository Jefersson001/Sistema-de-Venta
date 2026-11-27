<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Uses the isolated direct-sales fixture, including real native stock and accounting documents.
$warningRow=$db->query('SELECT * FROM direct_sale_checkouts WHERE id='.$warningSale)->fetch();
$warningLine=(int)$db->query('SELECT id FROM accounting_invoice_lines WHERE invoice_id='.$warningRow['invoice_id'].' LIMIT 1')->fetchColumn();
[$code,$totalForm]=authTestRequest($server,$returns.'?sale_id='.$warningSale.'&mode=total',$cookies['seller']);
dsExpect($code===200&&str_contains($totalForm,'value="total" selected')&&str_contains($totalForm,'data-return-remaining="1.00"'),'No abre la devolución total con cantidades pendientes.');
$small=['action'=>'direct_return','return_mode'=>'partial','sale_id'=>(string)$warningSale,'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Parcial con redondeo','quantities'=>[$warningLine=>'0.05']];
$smallId=dsId($post('seller',$returns,$small));dsId($post('manager',$returns.'?id='.$smallId,['action'=>'direct_return_approve']));
$stockQuery=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=?');$stockQuery->execute([$product,$locations['seller']]);$beforeStock=(string)$stockQuery->fetchColumn();
$full=['action'=>'direct_return','return_mode'=>'total','sale_id'=>(string)$warningSale,'request_key'=>bin2hex(random_bytes(32)),'reason'=>'La venta ya no se realizará','quantities'=>[$warningLine=>'999']];
$fullId=dsId($post('seller',$returns,$full));$requested=$db->query('SELECT * FROM direct_sale_actions WHERE id='.$fullId)->fetch();
$requestedPayload=json_decode($requested['payload_json'],true,8,JSON_THROW_ON_ERROR);
dsExpect($requestedPayload['mode']==='total'&&$requestedPayload['lines'][$warningLine]==='0.95','La devolución total confía en cantidades del navegador o ignora devoluciones anteriores.');
$stockQuery->execute([$product,$locations['seller']]);dsExpect($stockQuery->fetchColumn()===$beforeStock,'Solicitar devuelve existencias antes de recibir.');
dsExpect($post('seller',$returns.'?id='.$fullId,['action'=>'direct_return_approve'])[0]===403,'El vendedor confirma su propia devolución total.');
dsId($post('manager',$returns.'?id='.$fullId,['action'=>'direct_return_approve']));
$resolved=$db->query('SELECT * FROM direct_sale_actions WHERE id='.$fullId)->fetch();$invoice=$db->query('SELECT * FROM accounting_invoices WHERE id='.$warningRow['invoice_id'])->fetch();
dsExpect($resolved['status']==='done'&&$invoice['status']==='posted'&&$invoice['payment_state']==='reversed','La factura no queda revertida con historial conservado.');
$q=$db->prepare("SELECT SUM(total_amount) FROM accounting_invoices WHERE reversal_of_id=? AND status='posted'");$q->execute([$invoice['id']]);dsExpect(bccomp((string)$q->fetchColumn(),$invoice['total_amount'],2)===0,'Las devoluciones totales dejan una diferencia por redondeo.');
$stockQuery->execute([$product,$locations['seller']]);dsExpect(bccomp((string)$stockQuery->fetchColumn(),bcadd($beforeStock,'0.95',2),2)===0,'La recepción total no repone lo pendiente.');
$movement=$db->query('SELECT * FROM inventory_operations WHERE id='.$resolved['operation_id'])->fetch();dsExpect($movement['status']==='done'&&(int)$movement['return_of_id']===(int)$warningRow['operation_id']&&(int)$movement['destination_location_id']===$locations['seller'],'La devolución no recibe en la ubicación de la entrega original.');
$count=(int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn();
dsExpect(dsId($post('seller',$returns,$full))===$fullId,'Reintentar la solicitud total tras recibir no recupera el mismo registro.');
dsId($post('manager',$returns.'?id='.$fullId,['action'=>'direct_return_approve']));dsExpect((int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn()===$count,'Reconfirmar duplica la devolución.');
$full['request_key']=bin2hex(random_bytes(32));dsExpect($post('seller',$returns,$full)[0]===422,'Permite devolver de nuevo una venta totalmente devuelta.');
[$code,$result]=authTestRequest($server,$returns.'?id='.$fullId,$cookies['manager']);dsExpect($code===200&&str_contains($result,'Factura revertida: saldo de venta anulado.'),'No muestra el resultado de la factura en la devolución.');
[$code,$receipt]=authTestRequest($server,$path.'?id='.$warningSale,$cookies['seller']);dsExpect($code===200&&str_contains($receipt,'VENTA REVERTIDA')&&!str_contains($receipt,'>Devolver toda la venta</a>'),'La venta revertida sigue apareciendo como confirmada o devolvible.');
// A confirmed receipt must not silently refund money, and pending payments block the whole operation.
$newSale=array_replace($credit,['request_key'=>bin2hex(random_bytes(32))]);$paidSale=dsId($post('seller',$path,$newSale));
$paidRow=$db->query('SELECT * FROM direct_sale_checkouts WHERE id='.$paidSale)->fetch();$paidInvoice=$db->query('SELECT * FROM accounting_invoices WHERE id='.$paidRow['invoice_id'])->fetch();
$payInput=['action'=>'direct_payment','version'=>(string)$paidInvoice['version'],'request_key'=>bin2hex(random_bytes(32)),'amount'=>'1.00','payment_date'=>commerceToday(),'currency_code'=>'USD','account_id'=>(string)$account,'method_id'=>(string)$method];
dsId($post('seller',$path.'?id='.$paidSale,$payInput));$q=$db->prepare('SELECT payment_id FROM direct_sale_actions WHERE request_key=?');$q->execute([$payInput['request_key']]);$paidPayment=(int)$q->fetchColumn();
$paidReturn=['action'=>'direct_return','return_mode'=>'total','sale_id'=>(string)$paidSale,'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Devolver venta con abono'];$paidReturnId=dsId($post('seller',$returns,$paidReturn));
$nativeCount=(int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn();$creditCount=(int)$db->query("SELECT COUNT(*) FROM accounting_invoices WHERE document_type='credit'")->fetchColumn();
dsExpect($post('manager',$returns.'?id='.$paidReturnId,['action'=>'direct_return_approve'])[0]===422,'Recibe una devolución con pagos en revisión.');
dsExpect((int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn()===$nativeCount&&(int)$db->query("SELECT COUNT(*) FROM accounting_invoices WHERE document_type='credit'")->fetchColumn()===$creditCount,'Una aprobación fallida deja documentos parciales.');
$db->exec("UPDATE accounting_payments SET status='settled' WHERE id=".$paidPayment);$db->exec("UPDATE accounting_invoice_payments SET status='settled' WHERE payment_id=".$paidPayment);
dsId($post('manager',$returns.'?id='.$paidReturnId,['action'=>'direct_return_approve']));
$paidCredit=(int)$db->query('SELECT credit_id FROM direct_sale_actions WHERE id='.$paidReturnId)->fetchColumn();
dsExpect($db->query('SELECT status FROM accounting_payments WHERE id='.$paidPayment)->fetchColumn()==='settled'&&(int)$db->query('SELECT COUNT(*) FROM accounting_invoice_payments WHERE invoice_id='.$paidCredit)->fetchColumn()===0,'La devolución borra el cobro o genera un reembolso sin confirmación.');
echo "PASS: devolución total y parcial, recepción original, cantidades calculadas en servidor, notas sin residuos de redondeo, factura revertida, permisos, reintentos y cobros preservados.\n";

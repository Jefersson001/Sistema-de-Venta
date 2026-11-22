<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mailCount=count(file($dir.'/outbox'));
$noInvoiceSale=$insert('sales_quotations',['name'=>'ORDEN-SIN-FACTURA','customer_id'=>$contact,'order_date'=>date('Y-m-d'),'status'=>'draft','total_amount'=>'10','base_amount'=>'10']);$insert('sales_quotation_lines',$line+['quotation_id'=>$noInvoiceSale]);
$orderUrl='/modules/ventas/enviar.php?id='.$noInvoiceSale;[, $orderHtml]=authTestRequest($s,$orderUrl,$cookie);
cmCheck(substr_count($orderHtml,'name="documents[]"')===1&&str_contains($orderHtml,'value="order:'.$noInvoiceSale.'" checked'),'Order-only form default incorrect.');
$orderPost=$post;$orderPost['request_key']=bin2hex(random_bytes(32));$orderPost['documents']=['order:'.$noInvoiceSale];$orderPost['csrf']=authTestToken($orderHtml);
$result=authTestRequest($s,$orderUrl,$cookie,$orderPost);cmCheck($result[0]===303,'Order without invoice failed: '.mb_substr(strip_tags($result[1]),-900));
cmCheck(count(file($dir.'/outbox'))===$mailCount+1,'Order PDF not sent.');authTestRequest($s,$orderUrl,$cookie,$orderPost);cmCheck(count(file($dir.'/outbox'))===$mailCount+1,'Order retry duplicated mail.');
$q=$db->query('SELECT * FROM document_email_log ORDER BY id DESC LIMIT 1');$orderLog=$q->fetch();cmCheck($orderLog['invoice_id']===null&&$orderLog['origin_kind']==='order'&&(int)$orderLog['sale_id']===$noInvoiceSale&&$orderLog['status']==='sent','Order-only log incorrect.');
$noInvoiceOperation=$insert('inventory_operations',['name'=>'ENTREGA-SIN-FACTURA','quotation_id'=>$noInvoiceSale,'contact_id'=>$contact,'operation_type'=>'delivery','status'=>'done','source_location_id'=>$location,'destination_location_id'=>$location,'scheduled_date'=>date('Y-m-d')]);$insert('inventory_operation_lines',['operation_id'=>$noInvoiceOperation,'product_id'=>$product,'demand'=>'1','quantity'=>'1','position'=>0]);
$deliveryUrl='/modules/inventario/enviar.php?id='.$noInvoiceOperation;[, $deliveryHtml]=authTestRequest($s,$deliveryUrl,$cookie);cmCheck(substr_count($deliveryHtml,'name="documents[]"')===2&&str_contains($deliveryHtml,'value="operation:'.$noInvoiceOperation.'" checked'),'Delivery without invoice default incorrect.');
$deliveryPost=$post;$deliveryPost['request_key']=bin2hex(random_bytes(32));$deliveryPost['documents']=['operation:'.$noInvoiceOperation,'order:'.$noInvoiceSale];$deliveryPost['csrf']=authTestToken($deliveryHtml);
cmCheck(authTestRequest($s,$deliveryUrl,$cookie,$deliveryPost)[0]===303,'Delivery without invoice failed.');$messages=file($dir.'/outbox');cmCheck(count($messages)===$mailCount+2,'Delivery message missing.');$last=json_decode(end($messages),true);cmCheck(substr_count($last['message'],'Content-Type: application/pdf')===2,'Delivery/order attachments incorrect.');
$deliveryLog=$db->query('SELECT * FROM document_email_log ORDER BY id DESC LIMIT 1')->fetch();cmCheck($deliveryLog['invoice_id']===null&&(int)$deliveryLog['operation_id']===$noInvoiceOperation&&$deliveryLog['status']==='sent','Delivery log incorrect.');
$deliveryPost['request_key']=bin2hex(random_bytes(32));$deliveryPost['documents']=['invoice:'.$invoice];cmCheck(authTestRequest($s,$deliveryUrl,$cookie,$deliveryPost)[0]===422,'Unrelated invoice attached to delivery.');
foreach(['/modules/ventas/index.php?id='.$noInvoiceSale,'/modules/inventario/operaciones.php?type=delivery&id='.$noInvoiceOperation] as $formUrl){[, $formHtml]=authTestRequest($s,$formUrl,$cookie);cmCheck(str_contains($formHtml,'data-open-invoice-email')&&str_contains($formHtml,'data-invoice-email-dialog'),'Process email button/dialog missing.');}
[, $orderWithInvoice]=authTestRequest($s,'/modules/ventas/enviar.php?id='.$sale,$cookie);cmCheck(substr_count($orderWithInvoice,'name="documents[]"')===3,'Order with related invoice/delivery incomplete.');
cmCheck(authTestRequest($s,$deliveryUrl,$guest)[0]===303,'Guest accessed delivery email.');
echo "PASS: orden sola antes de entrega/factura, entrega con orden sin factura, selección por defecto, adjuntos PDF, reintentos, históricos y rechazo de documentos ajenos.\n";

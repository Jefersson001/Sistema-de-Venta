<?php
declare(strict_types=1);
require_once __DIR__.'/company-mail.php';
require_once __DIR__.'/document-print.php';

function emailInvoice(PDO $db,int $id,string $type): array {
    $resource=permissionInvoiceResource($type);permissionRequire($resource,'send',$id);
    $q=$db->prepare('SELECT i.*,c.email AS contact_email,c.name AS contact_name FROM accounting_invoices i JOIN contacts c ON c.id=i.contact_id WHERE i.id=? AND i.invoice_type=? AND i.company_id=? AND '.permissionWhere($resource,'i','send'));$q->execute([$id,$type,companyActiveId()]);$invoice=$q->fetch();
    if(!$invoice)throw new InvalidArgumentException('Activa la empresa de la factura o revisa tus permisos.',404);
    return $invoice;
}
function emailDocumentChoices(PDO $db,array $invoice): array {
    $sale=$invoice['invoice_type']==='customer';$base=authBasePath().'/modules/';
    $source=$base.'contabilidad/index.php?type='.$invoice['invoice_type'].'&id='.$invoice['id'];
    $choices=['invoice:'.$invoice['id']=>['label'=>($invoice['document_type']==='credit'?'Nota de crédito':'Factura').' · '.$invoice['name'],'source'=>$source,'id'=>(int)$invoice['id']]];
    $orderId=(int)($invoice[$sale?'sale_id':'purchase_id']??0);if(!$orderId)return $choices;
    $table=$sale?'sales_quotations':'purchase_orders';$resource=$sale?'ventas.orders':'compras.orders';
    $q=$db->prepare('SELECT id,name FROM '.$table.' WHERE id=? AND company_id=? AND '.($sale?'customer_id':'supplier_id').'=?');$q->execute([$orderId,$invoice['company_id'],$invoice['contact_id']]);$order=$q->fetch();
    if(!$order)return $choices;
    if(permissionHas($resource)&&permissionRecord($resource,$orderId))$choices['order:'.$orderId]=['label'=>($sale?'Orden de venta':'Orden de compra').' · '.$order['name'],'source'=>$base.($sale?'ventas':'compras').'/index.php?id='.$orderId,'id'=>$orderId];
    $resource=$sale?'inventario.deliveries':'inventario.receipts';if(!permissionHas($resource))return $choices;
    $q=$db->prepare("SELECT id,name FROM inventory_operations WHERE ".($sale?'quotation_id':'purchase_order_id')."=? AND company_id=? AND contact_id=? AND return_of_id IS NULL AND status='done' ORDER BY id");$q->execute([$orderId,$invoice['company_id'],$invoice['contact_id']]);
    foreach($q->fetchAll() as $operation)if(permissionRecord($resource,(int)$operation['id']))$choices['operation:'.$operation['id']]=['label'=>($sale?'Entrega':'Recepción').' · '.$operation['name'],'source'=>$base.'inventario/operaciones.php?type='.($sale?'delivery':'receipt').'&id='.$operation['id'],'id'=>(int)$operation['id']];
    return $choices;
}
function emailDocumentPdf(PDO $db,array $choice): string {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $model=transferRouteModel($choice['source']);if(!$model)throw new InvalidArgumentException('Documento no disponible.');
    $document=businessDocumentData($db,$model,$choice['id']);
    ob_start();require dirname(__DIR__).'/html/email-document-pdf.php';$html=ob_get_clean();
    $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('chroot',dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts');$options->set('defaultFont','DejaVu Sans');
    $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();return $pdf->output();
}
function emailSource(PDO $db,string $kind,int $id,string $type='customer'): array {
    if($kind==='invoice'){$row=emailInvoice($db,$id,$type);$row['email_default']='invoice:'.$id;return $row;}
    if(!in_array($kind,['order','delivery'],true))throw new InvalidArgumentException('Documento no disponible.',404);
    $resource=$kind==='order'?'ventas.orders':'inventario.deliveries';permissionRequire($resource,'send',$id);permissionRequire($resource,'read',$id);
    $table=$kind==='order'?'sales_quotations':'inventory_operations';$contact=$kind==='order'?'customer_id':'contact_id';
    $q=$db->prepare('SELECT d.*,c.email AS contact_email,c.name AS contact_name FROM '.$table.' d JOIN contacts c ON c.id=d.'.$contact.' WHERE d.id=? AND d.company_id=? AND '.permissionWhere($resource,'d','send').($kind==='delivery'?" AND d.operation_type='delivery' AND d.return_of_id IS NULL":''));$q->execute([$id,companyActiveId()]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Activa la empresa del documento o revisa tus permisos.',404);
    $row['contact_id']=$row[$contact];$row['invoice_type']='customer';$row['email_default']=($kind==='order'?'order:':'operation:').$id;
    $row['sale_id']=$kind==='order'?$id:($row['quotation_id']??null);return $row;
}
function emailSourceChoices(PDO $db,array $record,string $kind): array {
    if($kind==='invoice')return emailDocumentChoices($db,$record);
    $base=authBasePath().'/modules/';$id=(int)$record['id'];$order=(int)($record['sale_id']??0);
    $choices=[$record['email_default']=>['label'=>($kind==='order'?'Orden de venta':'Entrega').' · '.$record['name'],'source'=>$base.($kind==='order'?'ventas/index.php?id=':'inventario/operaciones.php?type=delivery&id=').$id,'id'=>$id]];
    if(!$order)return $choices;
    $q=$db->prepare('SELECT id,name FROM sales_quotations WHERE id=? AND company_id=? AND customer_id=?');$q->execute([$order,$record['company_id'],$record['contact_id']]);$sale=$q->fetch();if(!$sale)return $choices;
    if(permissionHas('ventas.orders')&&permissionRecord('ventas.orders',$order))$choices['order:'.$order]=['label'=>'Orden de venta · '.$sale['name'],'source'=>$base.'ventas/index.php?id='.$order,'id'=>$order];
    if(permissionHas('inventario.deliveries')){
        $q=$db->prepare("SELECT id,name FROM inventory_operations d WHERE d.quotation_id=? AND d.company_id=? AND d.contact_id=? AND d.operation_type='delivery' AND d.return_of_id IS NULL AND d.status='done' AND ".permissionWhere('inventario.deliveries','d'));$q->execute([$order,$record['company_id'],$record['contact_id']]);
        foreach($q->fetchAll() as $op)$choices['operation:'.$op['id']]=['label'=>'Entrega · '.$op['name'],'source'=>$base.'inventario/operaciones.php?type=delivery&id='.$op['id'],'id'=>(int)$op['id']];
    }
    if(permissionHas('contabilidad.customer_invoices')){
        $q=$db->prepare("SELECT id,name FROM accounting_invoices i WHERE i.sale_id=? AND i.contact_id=? AND i.company_id=? AND i.invoice_type='customer' AND i.document_type='invoice' AND i.status<>'cancel' AND ".permissionWhere('contabilidad.customer_invoices','i'));$q->execute([$order,$record['contact_id'],$record['company_id']]);
        foreach($q->fetchAll() as $bill)$choices['invoice:'.$bill['id']]=['label'=>'Factura · '.$bill['name'],'source'=>$base.'contabilidad/index.php?type=customer&id='.$bill['id'],'id'=>(int)$bill['id']];
    }
    return $choices;
}
function sendInvoiceDocuments(CompanyConnection $db,int $id,string $type,array $input): int {
    return sendEmailDocuments($db,'invoice',$id,$type,$input);
}
function sendEmailDocuments(CompanyConnection $db,string $kind,int $id,string $type,array $input): int {
    $invoice=emailSource($db,$kind,$id,$type);$recipient=mailAddress($input['recipient']??'');$subject=mailText($input['subject']??'',200);$body=mailText($input['body']??'',10000,true);
    if($subject==='')throw new InvalidArgumentException('Escribe un asunto.');
    $selected=$input['documents']??null;if(!is_array($selected)||!count($selected)||count($selected)>20)throw new InvalidArgumentException('Selecciona entre 1 y 20 documentos.');
    foreach($selected as $value)if(!is_string($value))throw new InvalidArgumentException('Selección de documentos no válida.');$selected=array_values(array_unique($selected));sort($selected);
    $key=$input['request_key']??null;if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Recarga la ventana antes de enviar.');
    $payload=[$id,$type,$recipient,$subject,$body,$selected];if($kind!=='invoice')array_unshift($payload,$kind);
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));$company=companyActiveId();
    $q=$db->prepare('SELECT id,payload_hash FROM document_email_log WHERE request_key=? AND company_id=?');$q->execute([$key,$company]);if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('Esta solicitud ya se utilizó con otros datos.',409);return (int)$old['id'];}
    $settings=loadCompanyMail($db,$company,true);$mail=companyMailer($settings,$company);$choices=emailSourceChoices($db,$invoice,$kind);$files=[];$bytes=0;
    foreach($selected as $value){if(!isset($choices[$value]))throw new InvalidArgumentException('Solo puedes adjuntar documentos autorizados relacionados con este registro.');}
    // Render a consistent read snapshot before any network connection is attempted.
    $db->beginTransaction();
    try{foreach($selected as $value){$pdf=emailDocumentPdf($db,$choices[$value]);$bytes+=strlen($pdf);if($bytes>12*1024*1024)throw new InvalidArgumentException('Los adjuntos superan 12 MB. Envía menos documentos.');$name=preg_replace('/[^\pL\pN._-]+/u','-',$choices[$value]['label']).'.pdf';$files[]=['name'=>$name,'pdf'=>$pdf];}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    try{
        $db->prepare("INSERT INTO document_email_log(invoice_id,origin_kind,origin_id,sale_id,operation_id,request_key,payload_hash,recipient,subject,body,documents_json,from_email,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'sending')")->execute([$kind==='invoice'?$id:null,$kind,$id,$kind==='order'?$id:($invoice['sale_id']??null),$kind==='delivery'?$id:null,$key,$hash,$recipient,$subject,$body,json_encode(array_map(static fn($value)=>['key'=>$value,'label'=>$choices[$value]['label']],$selected),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$settings['from_email']]);$log=(int)$db->lastInsertId();
    }catch(PDOException $e){if(($e->errorInfo[1]??null)!==1062)throw $e;$q=$db->prepare('SELECT id,payload_hash FROM document_email_log WHERE request_key=? AND company_id=?');$q->execute([$key,$company]);$old=$q->fetch();if(!$old||!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se utilizó.',409);return (int)$old['id'];}
    try{
        $mail->addAddress($recipient);$mail->Subject=$subject;$mail->Body=$body;$mail->isHTML(false);
        foreach($files as $file)$mail->addStringAttachment($file['pdf'],$file['name'],'base64','application/pdf');
        if(!$mail->send())throw new RuntimeException('SMTP');
        $db->prepare("UPDATE document_email_log SET status='sent',sent_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$log]);
        foreach($selected as $documentKey)if(str_starts_with($documentKey,'invoice:'))$db->prepare("UPDATE accounting_invoices SET sent_at=COALESCE(sent_at,CURRENT_TIMESTAMP) WHERE id=? AND company_id=? AND status='posted'")->execute([(int)substr($documentKey,8),$company]);
    }catch(Throwable){
        // SMTP delivery can be ambiguous after a connection drop. Never retry automatically.
        $db->prepare("UPDATE document_email_log SET status='uncertain',error_message=? WHERE id=?")->execute(['No se pudo confirmar el envío. Revisa la conexión y si el destinatario lo recibió antes de crear otro envío.',$log]);
    }
    return $log;
}

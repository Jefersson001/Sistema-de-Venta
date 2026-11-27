<?php
declare(strict_types=1);
require_once __DIR__.'/company-mail.php';
require_once __DIR__.'/document-print.php';
require_once __DIR__.'/currency-repository.php';
require_once __DIR__.'/document-messages.php';

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
    $orderId=(int)($invoice[$sale?'sale_id':'purchase_id']??0);if(!$orderId)return documentChoicesWithTickets($choices);
    $table=$sale?'sales_quotations':'purchase_orders';$resource=$sale?'ventas.orders':'compras.orders';
    $q=$db->prepare('SELECT id,name FROM '.$table.' WHERE id=? AND company_id=? AND '.($sale?'customer_id':'supplier_id').'=?');$q->execute([$orderId,$invoice['company_id'],$invoice['contact_id']]);$order=$q->fetch();
    if(!$order)return documentChoicesWithTickets($choices);
    if(permissionHas($resource)&&permissionRecord($resource,$orderId))$choices['order:'.$orderId]=['label'=>($sale?'Orden de venta':'Orden de compra').' · '.$order['name'],'source'=>$base.($sale?'ventas':'compras').'/index.php?id='.$orderId,'id'=>$orderId];
    $resource=$sale?'inventario.deliveries':'inventario.receipts';if(!permissionHas($resource))return documentChoicesWithTickets($choices);
    $q=$db->prepare("SELECT id,name FROM inventory_operations WHERE ".($sale?'quotation_id':'purchase_order_id')."=? AND company_id=? AND contact_id=? AND return_of_id IS NULL AND status='done' ORDER BY id");$q->execute([$orderId,$invoice['company_id'],$invoice['contact_id']]);
    foreach($q->fetchAll() as $operation)if(permissionRecord($resource,(int)$operation['id']))$choices['operation:'.$operation['id']]=['label'=>($sale?'Entrega':'Recepción').' · '.$operation['name'],'source'=>$base.'inventario/operaciones.php?type='.($sale?'delivery':'receipt').'&id='.$operation['id'],'id'=>(int)$operation['id']];
    return documentChoicesWithTickets($choices);
}
function emailDocumentPdf(PDO $db,array $choice): string {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $model=transferRouteModel($choice['source']);if(!$model)throw new InvalidArgumentException('Documento no disponible.');
    $document=businessDocumentData($db,$model,$choice['id']);
    if(($choice['format']??'a4')==='ticket')return emailInvoiceTicketPdf($db,$document);
    ob_start();require dirname(__DIR__).'/html/email-document-pdf.php';$html=ob_get_clean();
    $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('chroot',dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts');$options->set('defaultFont','DejaVu Sans');
    $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();return $pdf->output();
}
function emailInvoiceTicketPdf(PDO $db,array $document): string {
    if($document['kind']!=='invoices')throw new InvalidArgumentException('El ticket corresponde únicamente a facturas.');
    $document=invoiceTicketDocument($db,$document);
    ob_start();require dirname(__DIR__).'/html/email-ticket-pdf.php';$html=ob_get_clean();
    $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('defaultFont','Courier');$options->set('chroot',dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts');
    $width=80*72/25.4;$maximum=2000*72/25.4;
    $bottom=0;
    $pdf=new \Dompdf\Dompdf($options);
    // Frames are released after rendering; measure the ticket while its frame still exists.
    $pdf->setCallbacks([['event'=>'end_frame','f'=>static function($frame)use(&$bottom){if($frame->get_node()->nodeName==='main'){$box=$frame->get_border_box();$bottom=max($bottom,$box['y']+$box['h']+3*72/25.4);}}]]);
    $pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,$width,$maximum]);$pdf->render();
    if($bottom>0&&$bottom<$maximum&&$pdf->getCanvas()->get_page_count()===1){
        $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper([0,0,$width,max(40*72/25.4,ceil($bottom)+4)]);$pdf->render();
    }
    return $pdf->output();
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
    if(!$order)return documentChoicesWithTickets($choices);
    $q=$db->prepare('SELECT id,name FROM sales_quotations WHERE id=? AND company_id=? AND customer_id=?');$q->execute([$order,$record['company_id'],$record['contact_id']]);$sale=$q->fetch();if(!$sale)return documentChoicesWithTickets($choices);
    if(permissionHas('ventas.orders')&&permissionRecord('ventas.orders',$order))$choices['order:'.$order]=['label'=>'Orden de venta · '.$sale['name'],'source'=>$base.'ventas/index.php?id='.$order,'id'=>$order];
    if(permissionHas('inventario.deliveries')){
        $q=$db->prepare("SELECT id,name FROM inventory_operations d WHERE d.quotation_id=? AND d.company_id=? AND d.contact_id=? AND d.operation_type='delivery' AND d.return_of_id IS NULL AND d.status='done' AND ".permissionWhere('inventario.deliveries','d'));$q->execute([$order,$record['company_id'],$record['contact_id']]);
        foreach($q->fetchAll() as $op)$choices['operation:'.$op['id']]=['label'=>'Entrega · '.$op['name'],'source'=>$base.'inventario/operaciones.php?type=delivery&id='.$op['id'],'id'=>(int)$op['id']];
    }
    if(permissionHas('contabilidad.customer_invoices')){
        $q=$db->prepare("SELECT id,name FROM accounting_invoices i WHERE i.sale_id=? AND i.contact_id=? AND i.company_id=? AND i.invoice_type='customer' AND i.document_type='invoice' AND i.status<>'cancel' AND ".permissionWhere('contabilidad.customer_invoices','i'));$q->execute([$order,$record['contact_id'],$record['company_id']]);
        foreach($q->fetchAll() as $bill)$choices['invoice:'.$bill['id']]=['label'=>'Factura · '.$bill['name'],'source'=>$base.'contabilidad/index.php?type=customer&id='.$bill['id'],'id'=>(int)$bill['id']];
    }
    return documentChoicesWithTickets($choices);
}
final class EmailResendConfirmationRequired extends InvalidArgumentException {}
function emailPreviousDelivery(PDO $db,string $kind,int $id,int $company): ?array {
    $q=$db->prepare("SELECT id,status,recipient FROM document_email_log WHERE company_id=? AND ((origin_kind=? AND origin_id=?) OR (origin_kind='invoice' AND origin_id IS NULL AND invoice_id=?)) ORDER BY id DESC LIMIT 1");$q->execute([$company,$kind,$id,$kind==='invoice'?$id:0]);return $q->fetch()?:null;
}
function emailRequireResendConfirmation(PDO $db,string $kind,int $id,int $company,array $input): void {
    $previous=emailPreviousDelivery($db,$kind,$id,$company);
    if($previous&&(($input['confirm_resend']??'')!=='yes'||($input['previous_log_id']??null)!==(string)$previous['id']))throw new EmailResendConfirmationRequired('Existe un envío previo. Confirma si deseas volver a enviar el correo.',409);
}
function emailMessageRates(PDO $db,array $row,bool $stock,int $company): array {
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Caracas'));$today=$now->format('Y-m-d');
    $from=$row['currency_primary_code']??null;$to=$row['currency_alternate_code']??null;
    if(!$from||!$to){$q=$db->prepare('SELECT primary_code,alternate_code FROM accounting_currency_settings WHERE company_id=?');$q->execute([$company]);$pair=$q->fetch()?:[];$from=$pair['primary_code']??null;$to=$pair['alternate_code']??null;}
    $historical='No registrada en este documento';$current='No disponible para la fecha de envío';
    $formatDate=static fn(string $date):string=>(new DateTimeImmutable($date))->format('d/m/Y');
    if(!$stock&&$from&&$to&&!empty($row['exchange_rate'])&&bccomp((string)$row['exchange_rate'],'0',2)>0){
        $date=$row['exchange_rate_date']??$row['invoice_date']??$row['order_date']??null;
        $historical='1 '.$from.' = '.decimalDisplay((string)$row['exchange_rate']).' '.$to;
        if($date)$historical.=' ('.(!empty($row['exchange_rate_date'])?'fecha valor: ':'fecha del documento: ').$formatDate($date).')';
    }
    if($from&&$to){
        try{$snapshot=currencyRateSnapshot($db,$from,$to,$today,$company);}catch(InvalidArgumentException){$snapshot=null;}
        if($snapshot)$current='1 '.$from.' = '.decimalDisplay((string)$snapshot['rate']).' '.$to.' (fecha valor: '.$formatDate($snapshot['rate_date']).'; envío: '.$now->format('d/m/Y').')';
    }
    return ['{tasa_factura}'=>$historical,'{tasa_actual}'=>$current,'{fecha_envio}'=>$now->format('d/m/Y H:i')];
}
function emailRenderedMessage(PDO $db,array $record,string $kind,array $settings,?array $choices=null,array $extraVariables=[],?array $documentData=null): string {
    $choices??=emailSourceChoices($db,$record,$kind);$choice=$choices[$record['email_default']];$model=transferRouteModel($choice['source']);if(!$model)throw new InvalidArgumentException('Documento no disponible.');
    $data=$documentData??businessDocumentData($db,$model,(int)$record['id']);$row=$data['row'];$stock=$data['stock'];$currency=$stock?'':(string)$row['currency_code'];$products=[];
    foreach($data['lines'] as $line){
        $quantity=(string)($stock?($row['status']==='done'?$line['quantity']:$line['demand']):$line['quantity']);if(str_contains($quantity,'.'))$quantity=rtrim(rtrim($quantity,'0'),'.');
        $text='• '.$line['product_name'].' — Cantidad: '.$quantity;
        if(!$stock)$text.=' | Precio: '.decimalDisplay((string)$line['unit_price']).' '.$currency.' | Importe: '.decimalDisplay((string)$line['total_amount']).' '.$currency;
        $products[]=$text;
    }
    $template=companyMailMessageTemplate($settings['message_template'],array_keys($extraVariables));
    // Add missing rate lines beside the total without changing saved custom text.
    if(!$stock){
        $rateLines=[];if(!str_contains($template,'{tasa_factura}'))$rateLines[]='Tasa del documento: {tasa_factura}';if(!str_contains($template,'{tasa_actual}'))$rateLines[]='Tasa vigente al envío: {tasa_actual}';
        if($rateLines){$extra="\n".implode("\n",$rateLines);$position=strpos($template,'{total}');$template=$position===false?$template."\n".$extra:substr_replace($template,'{total}'.$extra,$position,strlen('{total}'));}
    }
    $variables=[
        '{cliente}'=>$record['contact_name'],'{empresa}'=>$data['company']['name'],'{documento}'=>$record['name'],
        '{productos}'=>implode("\n",$products),'{total}'=>$stock?'Sin importe monetario en este documento':decimalDisplay((string)$row['total_amount']).' '.$currency,
        '{moneda}'=>$currency,'{medios_pago}'=>$settings['payment_details']?:'Consulta con nosotros los medios de pago disponibles.',
        '{fecha}'=>$row['order_date']??$row['scheduled_date']??$row['invoice_date']??'',
        '{vencimiento}'=>$row['due_date']??'',
    ];
    if(str_contains($template,'{tasa_factura}')||str_contains($template,'{tasa_actual}')||str_contains($template,'{fecha_envio}'))$variables+=emailMessageRates($db,$row,$stock,(int)$row['company_id']);
    $body=strtr($template,$variables+$extraVariables);
    if(strlen($body)>60000)throw new InvalidArgumentException('El mensaje es demasiado extenso. Reduce la plantilla o envía únicamente los documentos PDF.');return $body;
}
function sendInvoiceDocuments(CompanyConnection $db,int $id,string $type,array $input): int {
    return sendEmailDocuments($db,'invoice',$id,$type,$input);
}
function sendEmailDocuments(CompanyConnection $db,string $kind,int $id,string $type,array $input): int {
    $invoice=emailSource($db,$kind,$id,$type);$recipient=mailAddress($input['recipient']??'');$subject=mailText($input['subject']??'',200);
    if($subject==='')throw new InvalidArgumentException('Escribe un asunto.');
    $choices=emailSourceChoices($db,$invoice,$kind);$selected=validateDocumentContentSelection($input['documents']??null,$choices);
    $key=$input['request_key']??null;if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Recarga la ventana antes de enviar.');
    $includeMessage=(bool)array_filter($selected,'isDocumentMessageSelection');$company=companyActiveId();$settings=loadCompanyMail($db,$company,true);$choices=emailSourceChoices($db,$invoice,$kind);
    $q=$db->prepare('SELECT id,payload_hash,body FROM document_email_log WHERE request_key=? AND company_id=?');$q->execute([$key,$company]);$old=$q->fetch();
    // A retry retains the exact original message even if company settings changed.
    $body=$old?$old['body']:($includeMessage?documentSelectedMessages($db,$invoice,$kind,$settings,$choices,$selected):'');
    $payload=[$id,$type,$recipient,$subject,$body,$selected];if($kind!=='invoice')array_unshift($payload,$kind);
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));if($old){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('Esta solicitud ya se utilizó con otros datos.',409);return (int)$old['id'];}
    $files=[];$bytes=0;$choices['message']=['label'=>documentMessageKinds()[$kind]];$choices+=documentMessageOptions($choices);
    emailRequireResendConfirmation($db,$kind,$id,$company,$input);$mail=companyMailer($settings,$company);
    // Render a consistent read snapshot before any network connection is attempted.
    $db->beginTransaction();
    try{foreach($selected as $value){if(isDocumentMessageSelection($value))continue;$pdf=emailDocumentPdf($db,$choices[$value]);$bytes+=strlen($pdf);if($bytes>12*1024*1024)throw new InvalidArgumentException('Los adjuntos superan 12 MB. Envía menos documentos.');$name=preg_replace('/[^\pL\pN._-]+/u','-',$choices[$value]['label']).'.pdf';$files[]=['name'=>$name,'pdf'=>$pdf];}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    try{
        // Serialize registering sends, without holding the lock during SMTP.
        $db->beginTransaction();$q=$db->prepare('SELECT id FROM business_company WHERE id=? FOR UPDATE');$q->execute([$company]);
        $q=$db->prepare('SELECT id,payload_hash FROM document_email_log WHERE request_key=? AND company_id=?');$q->execute([$key,$company]);if($existing=$q->fetch()){if(!hash_equals($existing['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se utilizó.',409);$db->commit();return (int)$existing['id'];}
        emailRequireResendConfirmation($db,$kind,$id,$company,$input);
        $db->prepare("INSERT INTO document_email_log(invoice_id,origin_kind,origin_id,sale_id,operation_id,request_key,payload_hash,recipient,subject,body,documents_json,from_email,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'sending')")->execute([$kind==='invoice'?$id:null,$kind,$id,$kind==='order'?$id:($invoice['sale_id']??null),$kind==='delivery'?$id:null,$key,$hash,$recipient,$subject,$body,json_encode(array_map(static fn($value)=>['key'=>$value,'label'=>$choices[$value]['label']],$selected),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$settings['from_email']]);$log=(int)$db->lastInsertId();
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if(!$e instanceof PDOException||($e->errorInfo[1]??null)!==1062)throw $e;$q=$db->prepare('SELECT id,payload_hash FROM document_email_log WHERE request_key=? AND company_id=?');$q->execute([$key,$company]);$old=$q->fetch();if(!$old||!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se utilizó.',409);return (int)$old['id'];}
    try{
        $mail->addAddress($recipient);$mail->Subject=$subject;$mail->Body=$body;$mail->isHTML(false);$mail->AllowEmpty=!$includeMessage&&count($files)>0;
        foreach($files as $file)$mail->addStringAttachment($file['pdf'],$file['name'],'base64','application/pdf');
        if(!$mail->send())throw new RuntimeException('SMTP');
        $db->prepare("UPDATE document_email_log SET status='sent',sent_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$log]);
        $sentDocuments=$selected;if(in_array('message:invoice',$selected,true)||($includeMessage&&$kind==='invoice'&&in_array('message',$selected,true)))$sentDocuments=array_merge($sentDocuments,array_keys(documentMessageTargets($choices,'invoice',$selected,$invoice['email_default'])));foreach($selected as $key)if(str_starts_with($key,'ticket:'))$sentDocuments[]='invoice:'.substr($key,7);
        foreach(array_unique($sentDocuments) as $documentKey)if(str_starts_with($documentKey,'invoice:'))$db->prepare("UPDATE accounting_invoices SET sent_at=COALESCE(sent_at,CURRENT_TIMESTAMP) WHERE id=? AND company_id=? AND status='posted'")->execute([(int)substr($documentKey,8),$company]);
    }catch(Throwable){
        // SMTP delivery can be ambiguous after a connection drop. Never retry automatically.
        $db->prepare("UPDATE document_email_log SET status='uncertain',error_message=? WHERE id=?")->execute(['No se pudo confirmar el envío. Revisa la conexión y si el destinatario lo recibió antes de crear otro envío.',$log]);
    }
    return $log;
}

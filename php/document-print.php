<?php
declare(strict_types=1);
require_once __DIR__.'/record-actions.php';

function businessDocumentData(PDO $db,array $m,int $id): array
{
    $row=recordActionRow($db,$m,$id);$kind=$m['save'];
    if(!in_array($kind,['sale_orders','purchase_orders','operations','invoices'],true))throw new InvalidArgumentException('Esta vista no tiene un documento imprimible.',404);
    $stock=$kind==='operations';$contactId=$row['customer_id']??$row['supplier_id']??$row['contact_id']??null;
    $q=$db->prepare('SELECT name,tax_id,address,phone,email FROM contacts WHERE id=?');$q->execute([$contactId]);$contact=$q->fetch()?:[];
    $q=$db->prepare('SELECT name,legal_name,tax_id,address,phone,email,website,logo_data,logo_mime FROM business_company WHERE id=?');$q->execute([$row['company_id']]);$company=$q->fetch();
    $q=$db->prepare('SELECT * FROM '.$m['line_table'].' WHERE '.$m['line_fk'].'=? ORDER BY position,id');$q->execute([$id]);$lines=$q->fetchAll();
    $title=match($kind){
        'sale_orders'=>$row['status']==='sale'?'Orden de venta':'Cotización',
        'purchase_orders'=>$row['status']==='purchase'?'Orden de compra':'Solicitud de cotización',
        'operations'=>match($row['operation_type']){'receipt'=>'Orden de recepción','internal'=>'Traslado interno',default=>'Orden de entrega'},
        'invoices'=>($row['document_type']==='credit'?'Nota de crédito':'Factura').' de '.($row['invoice_type']==='customer'?'cliente':'proveedor'),
    };
    $status=['draft'=>'Borrador','sent'=>'Enviada','sale'=>'Confirmada','purchase'=>'Confirmada','waiting'=>'En espera','ready'=>'Lista','done'=>'Realizada','posted'=>'Registrada','cancel'=>'Cancelada'][$row['status']]??$row['status'];
    $meta=['Fecha'=>$row['order_date']??$row['scheduled_date']??$row['invoice_date']??''];
    if(!empty($row['due_date']))$meta['Vencimiento']=$row['due_date'];
    if(!$stock)$meta['Moneda']=$row['currency_code'];
    $references=[];
    foreach(['quotation_id'=>['sales_quotations','Orden de venta'],'purchase_order_id'=>['purchase_orders','Orden de compra'],'sale_id'=>['sales_quotations','Orden de venta'],'purchase_id'=>['purchase_orders','Orden de compra'],'return_of_id'=>['inventory_operations','Devolución de'],'backorder_of_id'=>['inventory_operations','Pendiente de'],'reversal_of_id'=>['accounting_invoices','Factura original']] as $field=>[$table,$label])if(!empty($row[$field])){
        $q=$db->prepare('SELECT name FROM '.$table.' WHERE id=?');$q->execute([$row[$field]]);if($reference=$q->fetchColumn())$references[$label]=$reference;
    }
    if($stock){
        foreach(['source'=>'Origen','destination'=>'Destino'] as $side=>$label){$q=$db->prepare('SELECT l.name,w.name AS warehouse FROM inventory_locations l LEFT JOIN inventory_warehouses w ON w.id=l.warehouse_id WHERE l.id=?');$q->execute([$row[$side.'_location_id']]);$place=$q->fetch();$meta[$label]=$place?implode(' / ',array_filter([$place['warehouse'],$place['name']])):'—';}
        foreach($lines as &$line){$q=$db->prepare('SELECT name FROM inventory_products WHERE id=?');$q->execute([$line['product_id']]);$line['product_name']=$q->fetchColumn()?:'Producto #'.$line['product_id'];}unset($line);
    }else{
        if(!empty($row['invoice_address']))$contact['address']=$row['invoice_address'];
        if(!empty($row['payment_term_id'])){$q=$db->prepare('SELECT name FROM accounting_payment_terms WHERE id=?');$q->execute([$row['payment_term_id']]);if($term=$q->fetchColumn())$meta['Condiciones']=$term;}
        if(!empty($row['salesperson_id'])){$q=$db->prepare('SELECT name FROM contacts WHERE id=?');$q->execute([$row['salesperson_id']]);if($seller=$q->fetchColumn())$meta['Vendedor']=$seller;}
        if($kind==='invoices'){
            $q=$db->prepare('SELECT receipt_snapshot FROM restaurant_account_carts WHERE invoice_id=?');$q->execute([$id]);$snapshot=$q->fetchColumn();
            if($snapshot){$identity=json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);$contact=array_replace($contact,$identity['customer']);$company=array_replace($company,$identity['company']);}
        }
    }
    $taxes=[];
    if(!$stock)foreach($lines as $line){$key=($line['tax_name']??'Impuesto').'|'.($line['tax_rate']??'0');if(bccomp((string)($line['tax_amount']??'0'),'0',2)!==0){$taxes[$key]??=['name'=>($line['tax_name']?:'Impuesto').' ('.rtrim(rtrim((string)$line['tax_rate'],'0'),'.').' %)','amount'=>'0.00'];$taxes[$key]['amount']=bcadd($taxes[$key]['amount'],$line['tax_amount'],2);}}
    return compact('row','kind','stock','contact','company','lines','title','status','meta','references','taxes');
}

function renderBusinessDocument(PDO $db,array $m,?int $id,string $source): void
{
    if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'La impresión solo admite consultas.');}
    try{if(!$id)throw new InvalidArgumentException('Abre un documento guardado antes de imprimir.',404);$db->beginTransaction();$document=businessDocumentData($db,$m,$id);$db->commit();}
    catch(InvalidArgumentException $e){authStop($e->getCode()===404?404:422,$e->getMessage());}
    authPrivacyHeaders();require dirname(__DIR__).'/html/document-print.php';
}

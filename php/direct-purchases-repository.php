<?php
declare(strict_types=1);
require_once __DIR__.'/direct-sales-repository.php';

function directPurchaseCatalogs(CompanyConnection $db): array {
    $catalogs=loadPurchaseCatalogs($db,null);
    // A purchase always belongs to the active company, even in a consolidated view.
    foreach(['customers'=>'contacts','products'=>'inventory_products','terms'=>'accounting_payment_terms','taxes'=>'business_taxes'] as $key=>$table){
        $q=$db->prepare("SELECT id FROM $table WHERE company_id=?");$q->execute([companyActiveId()]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $catalogs[$key]=array_values(array_filter($catalogs[$key],static fn($row)=>in_array((int)$row['id'],$ids,true)));
    }
    $q=$db->prepare("SELECT l.*,w.name AS warehouse_name FROM inventory_locations l JOIN inventory_warehouses w ON w.id=l.warehouse_id WHERE l.company_id=? AND w.company_id=? AND l.location_type='internal' ORDER BY w.name,l.name");
    $q->execute([companyActiveId(),companyActiveId()]);$catalogs['destinations']=$q->fetchAll();
    $q=$db->prepare('SELECT id,tracking_mode,expiration_enabled FROM inventory_products WHERE company_id=?');$q->execute([companyActiveId()]);$tracking=[];foreach($q->fetchAll() as $row)$tracking[(int)$row['id']]=$row;
    foreach($catalogs['products'] as &$product){$product['tracking_mode']=$tracking[(int)$product['id']]['tracking_mode'];$product['expiration_enabled']=$tracking[(int)$product['id']]['expiration_enabled'];}unset($product);
    return $catalogs;
}

function directPurchaseCheckout(CompanyConnection $db,array $input): int {
    permissionRequire('compra_directa.purchases','create');
    foreach(['create','confirm','create_receipt','create_invoice'] as $action)permissionRequire('compras.orders',$action);
    permissionRequire('inventario.products','view_cost');
    $key=directKey($input['request_key']??null);
    if(!isset($input['lines'])||!is_array($input['lines'])||!count($input['lines'])||count($input['lines'])>100)throw new InvalidArgumentException('Agrega entre 1 y 100 productos.');
    $hash=hash('sha256',json_encode([$input['supplier_id']??null,$input['destination_location_id']??null,$input['payment_term_id']??null,$input['currency_code']??null,$input['order_date']??null,$input['lines']],JSON_THROW_ON_ERROR));
    return $db->atomicTransfer(function()use($db,$input,$key,$hash){
        // Serialize retries by actor. The unique key also rejects concurrent cross-actor reuse.
        $q=$db->prepare('SELECT id FROM auth_users WHERE id=? FOR UPDATE');$q->execute([permissionUser()['id']]);
        $q=$db->prepare('SELECT * FROM direct_purchase_checkouts WHERE request_key=? FOR UPDATE');$q->execute([$key]);
        if($old=$q->fetch()){
            if((int)$old['company_id']!==companyActiveId()||!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se utilizó con otros datos.',409);
            return (int)$old['id'];
        }
        $catalogs=directPurchaseCatalogs($db);
        if(!salesRecord($catalogs['destinations'],salesId($input['destination_location_id']??null)))throw new InvalidArgumentException('Selecciona un almacén y ubicación internos de esta empresa.');
        if(!salesRecord($catalogs['terms'],salesId($input['payment_term_id']??null)))throw new InvalidArgumentException('Selecciona un término de pago de esta empresa.');
        if(!$catalogs['settings']['exchange_rate'])throw new InvalidArgumentException('Configura una tasa vigente antes de comprar.');
        $tracking=[];$seen=[];
        foreach($input['lines'] as $line){
            if(!is_array($line)||!($id=salesId($line['product_id']??null))||isset($seen[$id]))throw new InvalidArgumentException('Selecciona productos válidos sin repetir líneas.');
            $seen[$id]=true;$tracking[$id]=$line['tracking']??[];
        }
        $post=$input;$post['order_type']='receipt';
        [$data,$lines,$errors]=validatePurchaseOrder($post,$catalogs,null);
        if($errors||!$lines)throw new InvalidArgumentException(implode(' ',$errors)?:'El carrito está vacío.');
        $db->prepare('INSERT INTO direct_purchase_checkouts(request_key,payload_hash) VALUES(?,?)')->execute([$key,$hash]);$checkout=(int)$db->lastInsertId();
        $purchase=savePurchaseOrder($db,$data,$lines,null,null);transitionPurchaseOrder($db,$purchase,1,'purchase');
        $operation=null;
        if(array_filter($lines,static fn($line)=>$line['product_type']==='goods')){
            $operation=createLinkedStockOperation($db,'purchase',$purchase);
            $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$operation]);$record=$q->fetch();
            foreach(loadInventoryOperationLines($db,$operation) as $line){$line['tracking']=$tracking[(int)$line['product_id']]??[];trackingSaveLine($db,$record,$line);}
            directCompleteOperation($db,$operation);
        }
        $invoice=workflowCreateInvoice($db,'purchase',$purchase);
        $db->prepare("UPDATE accounting_invoices SET status='posted',version=version+1 WHERE id=? AND status='draft'")->execute([$invoice]);
        $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);invoiceRecomputePaymentState($db,$q->fetch());
        $db->prepare('UPDATE direct_purchase_checkouts SET purchase_id=?,operation_id=?,invoice_id=? WHERE id=?')->execute([$purchase,$operation,$invoice,$checkout]);
        return $checkout;
    });
}

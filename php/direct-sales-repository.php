<?php
declare(strict_types=1);
require_once __DIR__.'/commerce-repository.php';
require_once __DIR__.'/inventory-stock-repository.php';
require_once __DIR__.'/inventory-tracking.php';
require_once __DIR__.'/operation-status-repository.php';
require_once __DIR__.'/invoice-status-repository.php';

function directKey(mixed $key): string {
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('La solicitud no es válida. Recarga la página.');
    return $key;
}
function directSeller(PDO $db,mixed $value,string $resource,string $action='read',bool $lock=false): array {
    $id=accessId($value);if(!$id)throw new InvalidArgumentException('Selecciona un vendedor.');
    if(permissionRuntime()&&$action==='create'&&!in_array($id,permissionContacts(),true))throw new InvalidArgumentException('Para vender o solicitar mercancía debes usar tu propio contacto vendedor.',403);
    if(permissionRuntime()&&permissionScope($resource,$action)==='own'&&!in_array($id,permissionContacts(),true))throw new InvalidArgumentException('Solo puedes operar con tu vendedor autorizado.',403);
    $q=$db->prepare("SELECT c.id,c.name,c.warehouse_id,c.location_id FROM contacts c
        JOIN contact_types t ON t.id=c.type_id
        WHERE c.id=? AND c.company_id=? AND c.active=1 AND t.system_key='salesperson'".($lock?' FOR UPDATE':''));
    $q->execute([$id,companyActiveId()]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Selecciona un vendedor activo de esta empresa.');
    if(!$row['warehouse_id'])throw new InvalidArgumentException('Asigna un almacén al vendedor en Contactos → ficha del vendedor → Inventario.');
    $q=$db->prepare("SELECT l.id,l.name FROM inventory_locations l
        JOIN inventory_warehouses w ON w.id=l.warehouse_id AND w.company_id=l.company_id
        WHERE l.warehouse_id=? AND l.company_id=? AND l.location_type='internal'".
        ($row['location_id']?' AND l.id=?':'')." ORDER BY l.id LIMIT 2".($lock?' FOR UPDATE':''));
    $args=[$row['warehouse_id'],companyActiveId()];if($row['location_id'])$args[]=$row['location_id'];
    $q->execute($args);$locations=$q->fetchAll();
    if(count($locations)!==1)throw new InvalidArgumentException('Selecciona una ubicación interna del almacén en Contactos → ficha del vendedor → Inventario. Si aún no existe, créala en Inventario.');
    $row['location_id']=$locations[0]['id'];$row['location_name']=$locations[0]['name'];
    return $row;
}
function directLines(mixed $rows): array {
    if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>100)throw new InvalidArgumentException('Agrega entre 1 y 100 productos.');
    $lines=[];foreach($rows as $row){
        if(!is_array($row)||!($id=accessId($row['product_id']??null))||isset($lines[$id]))throw new InvalidArgumentException('Revisa los productos del carrito y evita duplicados.');
        $qty=inventoryQuantity($row['quantity']??null);if($qty===null||bccomp($qty,'0',2)<=0)throw new InvalidArgumentException('Las cantidades deben ser positivas, con hasta dos decimales.');
        if(isset($row['unit_price'])||isset($row['discount']))throw new InvalidArgumentException('Ventas Directas utiliza el precio del catálogo, sin descuentos.');
        $lines[$id]=['product_id'=>$id,'quantity'=>$qty];
    }
    ksort($lines,SORT_NUMERIC);return array_values($lines);
}
function directProducts(PDO $db,int $location,bool $all=false): array {
    $q=$db->prepare("SELECT p.id,p.name,p.sale_price,p.sale_tax_id,p.currency_code,p.tracking_mode,p.commission_type,p.commission_value,
        COALESCE(t.rate,0) AS tax_rate,
        COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s WHERE s.product_id=p.id AND s.location_id=?),0)
        -COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.product_id=p.id AND r.location_id=?),0) AS available
        FROM inventory_products p LEFT JOIN business_taxes t ON t.id=p.sale_tax_id
        WHERE p.company_id=? AND p.product_type='goods' AND p.sale_enabled=1 AND ".inventoryConcreteCondition('p')." ORDER BY p.name,p.id");
    $q->execute([$location,$location,companyActiveId()]);$settings=accountingCurrencies($db);$rows=[];
    foreach($q->fetchAll() as $row){
        if($row['tracking_mode']!=='none'){
            $lots=$db->prepare('SELECT s.lot_id,s.quantity,t.expiration_date,t.removal_date FROM inventory_lot_stock s JOIN inventory_lots t ON t.id=s.lot_id WHERE s.product_id=? AND s.location_id=? AND s.quantity>0');
            $lots->execute([$row['id'],$location]);$available='0.00';
            foreach($lots->fetchAll() as $lot){
                if(($lot['expiration_date']&&$lot['expiration_date']<commerceToday())||($lot['removal_date']&&$lot['removal_date']<commerceToday()))continue;
                $qty=bcsub($lot['quantity'],trackingReserved($db,(int)$lot['lot_id'],$location),2);
                if(bccomp($qty,'0',2)>0)$available=bcadd($available,$qty,2);
            }
            if(bccomp($available,$row['available'],2)<0)$row['available']=$available;
        }
        if(bccomp($row['available'],'0',2)<0)$row['available']='0.00';
        if(!$all&&bccomp($row['available'],'0',2)<=0)continue;
        $price=bcadd($row['sale_price'],productCommissionUnit($row['sale_price'],$row['commission_type'],$row['commission_value'])??'0.00',2);
        try{$price=accountingConvert($price,$row['currency_code'],$settings['primary_code'],$settings);}catch(InvalidArgumentException){continue;}
        $rows[]=['id'=>(int)$row['id'],'name'=>$row['name'],'available'=>$row['available'],'unit_price'=>$price,'tax_rate'=>$row['tax_rate'],
            'unit_total'=>commerceAmounts('1.00',$price,$row['tax_rate'])['total_amount'],'tracking_mode'=>$row['tracking_mode']];
    }
    return $rows;
}
// Whole warehouses assigned to any seller are excluded, including inactive sellers.
function directCentralLocations(PDO $db): array {
    $q=$db->prepare("SELECT l.id,l.name,w.id AS warehouse_id,w.name AS warehouse_name
        FROM inventory_locations l JOIN inventory_warehouses w ON w.id=l.warehouse_id AND w.company_id=l.company_id
        WHERE l.company_id=? AND l.location_type='internal' AND NOT EXISTS (
            SELECT 1 FROM contacts c JOIN contact_types t ON t.id=c.type_id
            WHERE c.company_id=l.company_id AND c.warehouse_id=l.warehouse_id AND t.system_key='salesperson'
        ) ORDER BY w.name,l.name,l.id");
    $q->execute([companyActiveId()]);return $q->fetchAll();
}
function directSupplyStock(PDO $db,int $location): array {
    $q=$db->prepare("SELECT p.id,p.name,p.tracking_mode,s.quantity AS on_hand,
        COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.product_id=p.id AND r.location_id=s.location_id),0) AS reserved
        FROM inventory_stock s JOIN inventory_products p ON p.id=s.product_id
        WHERE s.company_id=? AND p.company_id=? AND s.location_id=? AND s.quantity<>0 ORDER BY p.name,p.id");
    $q->execute([companyActiveId(),companyActiveId(),$location]);$rows=$q->fetchAll();
    foreach($rows as &$row){
        $available=bcsub($row['on_hand'],$row['reserved'],2);
        if($row['tracking_mode']!=='none'){
            $lots=$db->prepare('SELECT s.lot_id,s.quantity,t.expiration_date,t.removal_date FROM inventory_lot_stock s JOIN inventory_lots t ON t.id=s.lot_id WHERE s.product_id=? AND s.location_id=? AND s.quantity>0');
            $lots->execute([$row['id'],$location]);$usable='0.00';
            foreach($lots->fetchAll() as $lot){
                if(($lot['expiration_date']&&$lot['expiration_date']<commerceToday())||($lot['removal_date']&&$lot['removal_date']<commerceToday()))continue;
                $qty=bcsub($lot['quantity'],trackingReserved($db,(int)$lot['lot_id'],$location),2);
                if(bccomp($qty,'0',2)>0)$usable=bcadd($usable,$qty,2);
            }
            if(bccomp($usable,$available,2)<0)$available=$usable;
        }
        $row['available']=bccomp($available,'0',2)>0?$available:'0.00';
    }unset($row);return $rows;
}
function directCompleteOperation(PDO $db,int $operation): void {
    transitionInventoryOperation($db,$operation,1,'confirm_operation');
    transitionInventoryOperation($db,$operation,2,'check_ready');
    $q=$db->prepare('SELECT status FROM inventory_operations WHERE id=?');$q->execute([$operation]);
    if($q->fetchColumn()!=='ready')throw new InvalidArgumentException('No hay existencias suficientes para completar toda la operación.');
    $db->prepare('UPDATE inventory_operation_lines SET quantity=demand WHERE operation_id=?')->execute([$operation]);
    validateInventoryStockOperation($db,$operation,3);
    $q=$db->prepare('SELECT COUNT(*) FROM inventory_operations WHERE backorder_of_id=?');$q->execute([$operation]);
    if((int)$q->fetchColumn()!==0)throw new InvalidArgumentException('No se pudo completar toda la mercancía; la operación fue revertida.');
}
function directCheckout(CompanyConnection $db,array $input): int {
    permissionRequire('venta_directa.sales','create');
    $key=directKey($input['request_key']??null);$lines=directLines($input['lines']??null);
    $mode=$input['payment_mode']??'';if(!in_array($mode,['cash','credit'],true))throw new InvalidArgumentException('Selecciona contado o crédito.');
    $seller=directSeller($db,$input['seller_id']??null,'venta_directa.sales','create');
    $customer=accessId($input['customer_id']??null);$term=$mode==='credit'?accessId($input['payment_term_id']??null):null;
    $geo=[];foreach(['sale_latitude','sale_longitude','sale_location_source'] as $field)if(isset($input[$field]))$geo[$field]=$input[$field];
    $hash=hash('sha256',json_encode([$seller['id'],$customer,$mode,$term,$lines,$geo],JSON_THROW_ON_ERROR));
    return $db->atomicTransfer(function()use($db,$input,$key,$lines,$mode,$seller,$customer,$term,$geo,$hash){
        $seller=directSeller($db,(string)$seller['id'],'venta_directa.sales','create',true);
        $q=$db->prepare('SELECT id,payload_hash,seller_id FROM direct_sale_checkouts WHERE request_key=? FOR UPDATE');$q->execute([$key]);
        if($old=$q->fetch()){
            if(!hash_equals($old['payload_hash'],$hash)||(int)$old['seller_id']!==(int)$seller['id'])throw new InvalidArgumentException('Esta solicitud ya se utilizó con otros datos.',409);
            return (int)$old['id'];
        }
        $catalogs=loadSalesCatalogs($db,null);
        foreach(['customers','salespeople','products'] as $list)$catalogs[$list]=array_values(array_filter($catalogs[$list],static fn($r)=>!isset($r['company_id'])||(int)$r['company_id']===companyActiveId()));
        $catalogs['salespeople']=[$seller+['storage_label'=>$seller['location_name']]];
        // Products and terms must belong to the active company even with multiple selected companies.
        $q=$db->prepare('SELECT id FROM inventory_products WHERE company_id=?');$q->execute([companyActiveId()]);$productIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $catalogs['products']=array_values(array_filter($catalogs['products'],static fn($p)=>in_array((int)$p['id'],$productIds,true)&&$p['product_type']==='goods'));
        $q=$db->prepare('SELECT id,name,days FROM accounting_payment_terms WHERE company_id=? ORDER BY days,id');$q->execute([companyActiveId()]);$terms=$q->fetchAll();
        if($mode==='credit'){
            $selected=salesRecord($terms,$term);if(!$selected||(int)$selected['days']<=0)throw new InvalidArgumentException('Selecciona un término de crédito con vencimiento mayor que cero.');
        }else{
            foreach($terms as $t)if((int)$t['days']===0){$term=(int)$t['id'];break;}
            if(!$term)throw new InvalidArgumentException('Configura un término de pago Contado de cero días.');
        }
        $catalogs['terms']=$terms;
        $settings=$catalogs['settings'];if(!$settings['exchange_rate'])throw new InvalidArgumentException('No hay una tasa vigente para registrar la factura.');
        $post=['customer_id'=>(string)$customer,'salesperson_id'=>(string)$seller['id'],'salesperson_explicit'=>'1','order_type'=>'delivery','order_date'=>commerceToday(),
            'payment_term_id'=>(string)$term,'currency_code'=>$settings['primary_code'],'lines'=>$lines]+$geo;
        [$data,$saleLines,$errors]=validateSalesQuotation($post,$catalogs,null);
        if($errors||!$saleLines)throw new InvalidArgumentException(implode(' ',$errors)?:'El carrito está vacío.');
        if((int)$data['location_id']!==(int)$seller['location_id'])throw new InvalidArgumentException('La ubicación del vendedor cambió; recarga.');
        $db->prepare('INSERT INTO direct_sale_checkouts(seller_id,request_key,payload_hash,payment_mode) VALUES(?,?,?,?)')->execute([$seller['id'],$key,$hash,$mode]);$id=(int)$db->lastInsertId();
        $sale=saveSalesQuotation($db,$data,$saleLines,null,null);transitionSalesQuotation($db,$sale,1,'sale');
        $operation=createLinkedStockOperation($db,'sale',$sale);directCompleteOperation($db,$operation);
        $invoice=workflowCreateInvoice($db,'sale',$sale);
        $db->prepare("UPDATE accounting_invoices SET status='posted',version=version+1 WHERE id=? AND status='draft'")->execute([$invoice]);
        $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);invoiceRecomputePaymentState($db,$q->fetch());
        $db->prepare('UPDATE direct_sale_checkouts SET sale_id=?,operation_id=?,invoice_id=? WHERE id=?')->execute([$sale,$operation,$invoice,$id]);
        return $id;
    });
}
function directCreateRequest(CompanyConnection $db,array $input): int {
    permissionRequire('venta_directa.requests','create');$key=directKey($input['request_key']??null);$lines=directLines($input['lines']??null);
    $seller=directSeller($db,$input['seller_id']??null,'venta_directa.requests','create');
    $notes=$input['notes']??'';if(!is_string($notes)||mb_strlen($notes)>1000)throw new InvalidArgumentException('La nota admite hasta 1000 caracteres.');
    $hash=hash('sha256',json_encode([$seller['id'],$lines,trim($notes)],JSON_THROW_ON_ERROR));
    return $db->atomicTransfer(function()use($db,$seller,$key,$lines,$notes,$hash){
        $seller=directSeller($db,(string)$seller['id'],'venta_directa.requests','create',true);
        $q=$db->prepare('SELECT id,payload_hash FROM direct_stock_requests WHERE request_key=? FOR UPDATE');$q->execute([$key]);
        if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se envió con otros datos.',409);return (int)$old['id'];}
        foreach($lines as $line){$p=trackingProduct($db,$line['product_id']);if($p['product_type']!=='goods'||!$p['sale_enabled']||inventoryIsTemplate($db,(int)$p['id'])||(int)$p['company_id']!==companyActiveId())throw new InvalidArgumentException('Selecciona artículos vendibles concretos.');}
        $db->prepare('INSERT INTO direct_stock_requests(seller_id,destination_location_id,request_key,payload_hash,notes) VALUES(?,?,?,?,?)')->execute([$seller['id'],$seller['location_id'],$key,$hash,trim($notes)]);$id=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO direct_stock_request_lines(request_id,product_id,requested_quantity) VALUES(?,?,?)');foreach($lines as $line)$q->execute([$id,$line['product_id'],$line['quantity']]);return $id;
    });
}
function directDecideRequest(CompanyConnection $db,int $id,array $input,bool $approve): int {
    permissionRequire('venta_directa.requests',$approve?'confirm':'cancel',$id);
    if($approve)permissionRequire('venta_directa.stock','read');
    if($approve&&permissionScope('venta_directa.requests','confirm')!=='all')throw new InvalidArgumentException('La aprobación requiere un supervisor con alcance Todos.',403);
    return $db->atomicTransfer(function()use($db,$id,$input,$approve){
        $q=$db->prepare('SELECT * FROM direct_stock_requests WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$id,companyActiveId()]);$request=$q->fetch();
        if(!$request)throw new InvalidArgumentException('La solicitud no está disponible.');
        if($request['status']==='transferred'&&$approve)return (int)$request['operation_id'];
        if($request['status']!=='pending'||(int)$request['version']!==(int)($input['version']??0))throw new InvalidArgumentException('La solicitud cambió o ya fue resuelta. Recarga.',409);
        $note=$input['decision_note']??'';if(!is_string($note)||mb_strlen($note)>1000)throw new InvalidArgumentException('La observación admite hasta 1000 caracteres.');
        $operation=null;$source=null;
        if($approve){
            $seller=directSeller($db,(string)$request['seller_id'],'venta_directa.requests','confirm',true);
            if((int)$seller['location_id']!==(int)$request['destination_location_id'])throw new InvalidArgumentException('El almacén del vendedor cambió. Cancela y crea una nueva solicitud.');
            $storage=loadStorageRelations($db);$source=accessId($input['source_location_id']??null);
            if(!in_array($source,array_map('intval',array_column(directCentralLocations($db),'id')),true))throw new InvalidArgumentException('Selecciona un origen de la empresa activa cuyo almacén no esté asignado a vendedores.');
            $q=$db->prepare('SELECT * FROM direct_stock_request_lines WHERE request_id=? ORDER BY product_id');$q->execute([$id]);$requested=$q->fetchAll();$lines=[];
            $quantities=$input['approved']??[];if(!is_array($quantities)||count($quantities)>100)throw new InvalidArgumentException('Revisa las cantidades aprobadas.');
            foreach($requested as $row){
                $qty=inventoryQuantity($quantities[$row['id']]??null);
                if($qty===null||bccomp($qty,'0',2)<0||bccomp($qty,$row['requested_quantity'],2)>0)throw new InvalidArgumentException('Aprueba cantidades entre cero y lo solicitado.');
                if(bccomp($qty,'0',2)>0)$lines[]=['product_id'=>$row['product_id'],'quantity'=>$qty,'demand'=>$qty];
                $db->prepare('UPDATE direct_stock_request_lines SET approved_quantity=? WHERE id=?')->execute([$qty,$row['id']]);
            }
            if(!$lines)throw new InvalidArgumentException('Aprueba al menos una cantidad o rechaza la solicitud.');
            [$data,$errors]=validateInventoryOperation(['scheduled_date'=>commerceToday(),'source_location_id'=>(string)$source,'destination_location_id'=>(string)$request['destination_location_id'],'notes'=>'Solicitud de vendedor #'.$id],'internal',$storage,[]);
            if($errors)throw new InvalidArgumentException(implode(' ',$errors));
            $operation=saveInventoryOperation($db,$data,null,null,$lines);directCompleteOperation($db,$operation);
        }elseif(trim($note)===''&&permissionScope('venta_directa.requests','cancel')==='all')throw new InvalidArgumentException('Indica el motivo del rechazo.');
        $status=$approve?'transferred':(permissionScope('venta_directa.requests','cancel')==='all'?'rejected':'cancelled');
        $db->prepare('UPDATE direct_stock_requests SET status=?,version=version+1,source_location_id=?,operation_id=?,decision_note=?,decided_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$source,$operation,trim($note),permissionUser()['id'],$id]);
        return (int)$operation;
    });
}

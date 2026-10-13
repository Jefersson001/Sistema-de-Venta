<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow-repository.php';

function createLinkedStockOperation(PDO $db, string $kind, int $orderId, ?int $backorder = null, bool $insideTransaction = false): int
{
    if (!$insideTransaction) workflowBegin($db);
    try {
        $sale=$kind==='sale'; $table=$sale?'sales_quotations':'purchase_orders'; $fk=$sale?'quotation_id':'purchase_order_id';
        $q=$db->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE"); $q->execute([$orderId]); $order=$q->fetch();
        if(!$order || $order['status']!==($sale?'sale':'purchase')) throw new InvalidArgumentException('Confirma la orden antes de generar su movimiento de inventario.');
        $q=$db->prepare("SELECT id FROM inventory_operations WHERE $fk=? AND return_of_id IS NULL AND status IN ('draft','waiting','ready') ORDER BY id DESC LIMIT 1"); $q->execute([$orderId]); $active=$q->fetchColumn();
        if($active) { if(!$insideTransaction)$db->commit(); return (int)$active; }
        $lines=array_values(array_filter(commerceLineProgress($db,$kind,$orderId),static fn($l)=>$l['product_type']==='goods' && bccomp($l['pending'],'0',3)>0));
        if(!$lines) {
            $q=$db->prepare("SELECT id FROM inventory_operations WHERE $fk=? AND return_of_id IS NULL AND status='done' ORDER BY id DESC LIMIT 1"); $q->execute([$orderId]); $last=$q->fetchColumn();
            if(!$last)throw new InvalidArgumentException('La orden no tiene mercancía pendiente de entregar o recibir.');
            if(!$insideTransaction)$db->commit(); return (int)$last;
        }
        $storage=loadStorageRelations($db); $contact=(int)$order[$sale?'customer_id':'supplier_id'];
        $q=$db->prepare('SELECT partner_location_id FROM contacts WHERE id=?'); $q->execute([$contact]);
        $external=storagePartnerLocation($storage,$sale?'customer':'supplier',(int)$q->fetchColumn()?:null);
        if(!$external)throw new InvalidArgumentException('Configura una ubicación externa para el contacto.');
        $internal=$sale?($order['location_id']??null):$order['destination_location_id']; $warehouse=$sale?($order['warehouse_id']??null):null;
        if($backorder) {
            $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$backorder]);$previous=$q->fetch();
            $internal=$previous[$sale?'source_location_id':'destination_location_id']; $warehouse=$previous[$sale?'source_warehouse_id':'destination_warehouse_id'];
        }
        if(!$internal && $warehouse) {
            $q=$db->prepare("SELECT id FROM inventory_locations WHERE warehouse_id=? AND location_type='internal' LIMIT 2");$q->execute([$warehouse]);$locations=$q->fetchAll(PDO::FETCH_COLUMN);if(count($locations)===1)$internal=$locations[0];
        }
        $location=storageRecord($storage['locations'],(int)$internal?:null); $warehouse=$location['warehouse_id']??$warehouse;
        if($location && $location['location_type']!=='internal')throw new InvalidArgumentException('Selecciona una ubicación interna para la operación.');
        $db->prepare('INSERT INTO inventory_operations (operation_type,'.$fk.',contact_id,scheduled_date,source_location_id,destination_location_id,source_warehouse_id,destination_warehouse_id,backorder_of_id,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$sale?'delivery':'receipt',$orderId,$contact,$order['order_date'],$sale?$internal:$external['id'],$sale?$external['id']:$internal,$sale?$warehouse:null,$sale?null:$warehouse,$backorder,'']);
        $id=(int)$db->lastInsertId();$db->prepare('UPDATE inventory_operations SET name=? WHERE id=?')->execute([($sale?'OUT/':'IN/').str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);
        $q=$db->prepare('INSERT INTO inventory_operation_lines (operation_id,position,product_id,demand,quantity,sale_line_id,purchase_line_id) VALUES (?,?,?,?,0,?,?)');
        foreach($lines as $pos=>$l)$q->execute([$id,$pos,$l['product_id'],$l['pending'],$sale?$l['id']:null,$sale?null:$l['id']]);
        if(!$insideTransaction)$db->commit(); return $id;
    }catch(Throwable $e){if(!$insideTransaction)$db->rollBack();throw $e;}
}

function createStockReturn(PDO $db,int $id,?int $version): int
{
    $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$id]);$operation=$q->fetch();
    if(!$operation)throw new InvalidArgumentException('La operación no existe.');
    workflowBegin($db);
    try { workflowLockOrder($db,$operation);
        $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=? FOR UPDATE');$q->execute([$id]);$operation=$q->fetch();
        if($operation['status']!=='done' || $operation['return_of_id'] || $operation['operation_type']==='internal' || (int)$operation['version']!==$version)throw new InvalidArgumentException('Solo puedes devolver una entrega o recepción hecha; recarga antes de continuar.');
        $q=$db->prepare("SELECT id FROM inventory_operations WHERE return_of_id=? AND status IN ('draft','waiting','ready') LIMIT 1");$q->execute([$id]);$pending=$q->fetchColumn();
        if($pending){$db->commit();return (int)$pending;}
        $lines=[];
        foreach(loadInventoryOperationLines($db,$id) as $l){
            $q=$db->prepare("SELECT COALESCE(SUM(r.demand),0) FROM inventory_operation_lines r JOIN inventory_operations o ON o.id=r.operation_id WHERE r.return_line_id=? AND o.status IN ('draft','waiting','ready')");$q->execute([$l['id']]);$reserved=(string)$q->fetchColumn();
            $q=$db->prepare("SELECT COALESCE(SUM(r.quantity),0) FROM inventory_operation_lines r JOIN inventory_operations o ON o.id=r.operation_id WHERE r.return_line_id=? AND o.status='done'");$q->execute([$l['id']]);
            $left=bcsub(bcsub($l['quantity'],(string)$q->fetchColumn(),3),$reserved,3);
            if(bccomp($left,'0',3)>0)$lines[]=array_merge($l,['remaining'=>$left]);
        }
        if(!$lines)throw new InvalidArgumentException('No quedan cantidades de esta operación por devolver.');
        $db->prepare('INSERT INTO inventory_operations (operation_type,quotation_id,purchase_order_id,contact_id,scheduled_date,source_location_id,destination_location_id,source_warehouse_id,destination_warehouse_id,return_of_id,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$operation['operation_type'],$operation['quotation_id'],$operation['purchase_order_id'],$operation['contact_id'],commerceToday(),$operation['destination_location_id'],$operation['source_location_id'],$operation['destination_warehouse_id'],$operation['source_warehouse_id'],$id,'Devolución de '.$operation['name']]);
        $new=(int)$db->lastInsertId();$db->prepare('UPDATE inventory_operations SET name=? WHERE id=?')->execute(['RET/'.str_pad((string)$new,5,'0',STR_PAD_LEFT),$new]);
        $q=$db->prepare('INSERT INTO inventory_operation_lines (operation_id,position,product_id,demand,quantity,sale_line_id,purchase_line_id,return_line_id) VALUES (?,?,?,?,0,?,?,?)');
        foreach($lines as $pos=>$l)$q->execute([$new,$pos,$l['product_id'],$l['remaining'],$l['sale_line_id'],$l['purchase_line_id'],$l['id']]);
        $db->commit();return $new;
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

function cancelStockOperation(PDO $db,int $id,?int $version): void
{
    $q=$db->prepare('SELECT quotation_id,purchase_order_id FROM inventory_operations WHERE id=?');$q->execute([$id]);$relation=$q->fetch();
    if(!$relation)throw new InvalidArgumentException('La operación no existe.');
    workflowBegin($db);
    try{
        workflowLockOrder($db,$relation);
        $q=$db->prepare('SELECT status,version FROM inventory_operations WHERE id=? FOR UPDATE');$q->execute([$id]);$o=$q->fetch();
        if(!$o || !in_array($o['status'],['draft','waiting','ready'],true) || (int)$o['version']!==$version)throw new InvalidArgumentException('La operación no admite cancelación. Recarga antes de continuar.');
        $db->prepare('DELETE FROM inventory_reservations WHERE operation_id=?')->execute([$id]);
        $db->prepare("UPDATE inventory_operations SET status='cancel',version=version+1 WHERE id=?")->execute([$id]);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

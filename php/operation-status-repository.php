<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-operations-repository.php';
require_once __DIR__ . '/workflow-operations.php';
function operationStatuses(): array { return ['draft'=>'Borrador','waiting'=>'En espera','ready'=>'Listo','done'=>'Hecho','cancel'=>'Cancelado']; }
function transitionInventoryOperation(PDO $db,int $id,?int $version,string $action): void
{
    $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$q->execute([$id]);$o=$q->fetch();
    if(!$o)throw new InvalidArgumentException('La operación no existe.');
    workflowBegin($db);
    try { workflowLockOrder($db,$o);
        $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=? FOR UPDATE');$q->execute([$id]);$o=$q->fetch();
        if((int)$o['version']!==$version)throw new InvalidArgumentException('La operación cambió. Recarga antes de continuar.');
        $lines=loadInventoryOperationLines($db,$id);
        if(!$lines)throw new InvalidArgumentException('Agrega al menos un producto antes de confirmar la operación.');
        if($action==='confirm_operation'){
            if($o['status']!=='draft')throw new InvalidArgumentException('Solo puedes confirmar un borrador.');$target='waiting';
        }elseif($action==='check_ready'){
            if($o['status']!=='waiting')throw new InvalidArgumentException('Solo puedes comprobar una operación en espera.');
            $storage=loadStorageRelations($db);$source=storageRecord($storage['locations'],inventoryId($o['source_location_id']));$dest=storageRecord($storage['locations'],inventoryId($o['destination_location_id']));
            if(!$source||!$dest)throw new InvalidArgumentException('Selecciona ubicaciones válidas antes de continuar.');
            $return=!empty($o['return_of_id']);$type=$o['operation_type'];
            $sourceType=$return?($type==='delivery'?'customer':'internal'):($type==='receipt'?'supplier':'internal');
            $destinationType=$return?($type==='receipt'?'supplier':'internal'):($type==='delivery'?'customer':'internal');
            if($source['location_type']!==$sourceType || $dest['location_type']!==$destinationType)throw new InvalidArgumentException('Guarda ubicaciones válidas para el tipo de operación.');
            validateLinkedOperationLines($db,$o,$lines);
            $needed=[];
            $hasQuantities=(bool)array_filter($lines,static fn($l)=>bccomp($l['quantity'],'0',2)>0);
            foreach($lines as $l){
                $p=$db->prepare('SELECT product_type FROM inventory_products WHERE id=?');$p->execute([$l['product_id']]);if($p->fetchColumn()!=='goods')throw new InvalidArgumentException('Los servicios no generan movimientos de inventario.');
                $qty=$hasQuantities?$l['quantity']:$l['demand'];if(bccomp($qty,'0',2)>0)$needed[(int)$l['product_id']]=bcadd($needed[(int)$l['product_id']]??'0.00',$qty,2);
            }
            ksort($needed);
            if($source['location_type']==='internal') foreach($needed as $product=>$qty){
                $db->prepare('INSERT INTO inventory_stock (product_id,location_id) VALUES (?,?) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)')->execute([$product,$source['id']]);
                $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=? FOR UPDATE');$q->execute([$product,$source['id']]);$available=bcsub((string)$q->fetchColumn(),inventoryReserved($db,$product,(int)$source['id'],$id),2);
                if(bccomp($available,$qty,2)<0)throw new InvalidArgumentException('No hay cantidad suficiente disponible después de las reservas. La operación permanece En espera.');
                $db->prepare('INSERT INTO inventory_reservations (operation_id,product_id,location_id,quantity) VALUES (?,?,?,?)')->execute([$id,$product,$source['id'],$qty]);
            }
            $target='ready';
        }else throw new InvalidArgumentException('La transición solicitada no es válida.');
        $db->prepare('UPDATE inventory_operations SET status=?,version=version+1 WHERE id=?')->execute([$target,$id]);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

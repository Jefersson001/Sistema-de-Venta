<?php
declare(strict_types=1);

function trackingModes(): array { return ['none'=>'Sin seguimiento','lot'=>'Por lotes','serial'=>'Por número de serie']; }
function trackingToday(): string { return (new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d'); }
function trackingProduct(PDO $db,int $id,bool $lock=false): array
{
    $q=$db->prepare('SELECT * FROM inventory_products WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);
    $p=$q->fetch();if(!$p)throw new InvalidArgumentException('El producto no está disponible.');
    if($lock&&$db instanceof CompanyConnection&&(int)$p['company_id']!==$db->activeCompanyId())throw new InvalidArgumentException('Activa la empresa del producto antes de modificar su seguimiento.');
    return $p;
}
function trackingProductData(array $input): array
{
    $data=[];
    if(array_key_exists('tracking_mode',$input)){
        if(!is_string($input['tracking_mode'])||!isset(trackingModes()[$input['tracking_mode']]))throw new InvalidArgumentException('Selecciona un seguimiento válido.');
        $data['tracking_mode']=$input['tracking_mode'];
    }
    foreach(['expiration_enabled','expiration_days','alert_days','removal_days','best_before_days'] as $field)if(array_key_exists($field,$input)){
        $max=$field==='expiration_enabled'?1:36500;
        if(!is_scalar($input[$field])||!preg_match('/^[0-9]{1,5}$/D',(string)$input[$field])||(int)$input[$field]>$max)throw new InvalidArgumentException('Los plazos deben ser días enteros entre 0 y 36500.');
        $data[$field]=(int)$input[$field];
    }
    return $data;
}
function trackingGuardProduct(PDO $db,array &$data,?array $current): void
{
    $fields=['tracking_mode'=>'none','expiration_enabled'=>0,'expiration_days'=>0,'alert_days'=>0,'removal_days'=>0,'best_before_days'=>0];
    $valid=trackingProductData($data);
    foreach($fields as $field=>$default)$data[$field]=$valid[$field]??$current[$field]??$default;
    if($data['product_type']==='service'&&$data['tracking_mode']!=='none')throw new InvalidArgumentException('Los servicios no admiten lotes ni series.');
    if($data['tracking_mode']==='none'&&$data['expiration_enabled'])throw new InvalidArgumentException('Activa lotes o series antes de controlar vencimientos.');
    if($current && $current['tracking_mode']!==$data['tracking_mode']){
        $q=$db->prepare('SELECT id FROM inventory_lots WHERE product_id=? LIMIT 1 FOR UPDATE');$q->execute([$current['id']]);
        if($q->fetchColumn())throw new InvalidArgumentException('Un producto con lotes o series conserva su seguimiento para proteger la trazabilidad.');
        $q=$db->prepare("SELECT l.id FROM inventory_operation_lines l JOIN inventory_operations o ON o.id=l.operation_id WHERE l.product_id=? AND o.status='ready' LIMIT 1 FOR UPDATE");$q->execute([$current['id']]);
        if($q->fetchColumn())throw new InvalidArgumentException('Devuelve a espera las operaciones listas de este producto antes de cambiar el seguimiento.');
    }
    if($current && !$current['expiration_enabled'] && $data['expiration_enabled']){
        $q=$db->prepare('SELECT id FROM inventory_lots WHERE product_id=? AND expiration_date IS NULL LIMIT 1 FOR UPDATE');$q->execute([$current['id']]);
        if($q->fetchColumn())throw new InvalidArgumentException('Completa primero el vencimiento de los lotes o series existentes.');
    }
}
function trackingDate(mixed $value): ?string
{
    if($value===null||$value==='')return null;
    if(!is_string($value))throw new InvalidArgumentException('Fecha de vencimiento inválida.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d||$d->format('Y-m-d')!==$value||$value<'1000-01-01'||$value>'9999-12-31')throw new InvalidArgumentException('Fecha de vencimiento inválida.');
    return $value;
}
function trackingRows(mixed $input): array
{
    if(is_string($input)){
        if(strlen($input)>200000)throw new InvalidArgumentException('Demasiados datos de seguimiento.');
        try{$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('El detalle de lotes o series no es válido.');}
    }
    if(!is_array($input)||count($input)>1000)throw new InvalidArgumentException('Se admiten hasta 1000 lotes o series por línea.');
    $rows=[];
    foreach($input as $raw){
        if(!is_array($raw))throw new InvalidArgumentException('Detalle de seguimiento inválido.');
        $name=is_string($raw['name']??null)?trim($raw['name']):'';
        if($name===''&&!inventoryId($raw['lot_id']??null))continue;
        $quantity=inventoryQuantity(is_scalar($raw['quantity']??null)?(string)$raw['quantity']:'');
        if(!$quantity||bccomp($quantity,'0',2)<=0)throw new InvalidArgumentException('Cada lote o serie necesita una cantidad positiva.');
        $rows[]=['lot_id'=>inventoryId($raw['lot_id']??null),'name'=>$name,'quantity'=>$quantity,'expiration_date'=>trackingDate($raw['expiration_date']??null)];
    }
    return $rows;
}
function trackingLot(PDO $db,array $product,array $row,bool $create): array
{
    $name=trim($row['name']??'');$lot=null;
    if(!empty($row['lot_id'])){$q=$db->prepare('SELECT * FROM inventory_lots WHERE id=? AND product_id=?');$q->execute([$row['lot_id'],$product['id']]);$lot=$q->fetch();if(!$lot)throw new InvalidArgumentException('El lote o serie no corresponde al producto o empresa.');}
    elseif($name!==''){$q=$db->prepare('SELECT * FROM inventory_lots WHERE product_id=? AND name=?');$q->execute([$product['id'],$name]);$lot=$q->fetch();}
    if($lot){
        if($row['expiration_date']!==null && $row['expiration_date']!==$lot['expiration_date'])throw new InvalidArgumentException('El vencimiento debe coincidir con el lote o serie existente.');
        if($lot['tracking_mode']!==$product['tracking_mode'])throw new InvalidArgumentException('El seguimiento del producto no coincide con el identificador.');
        return $lot;
    }
    if(!$create)throw new InvalidArgumentException('Selecciona un lote o serie existente para una salida, traslado o devolución.');
    if($name===''||mb_strlen($name)>100||preg_match('/[\x00-\x1F\x7F]/u',$name))throw new InvalidArgumentException('El identificador admite entre 1 y 100 caracteres, sin saltos de línea.');
    $expiration=$row['expiration_date'];
    if(!$expiration && $product['expiration_enabled'] && $product['expiration_days'])$expiration=(new DateTimeImmutable(trackingToday()))->modify('+'.(int)$product['expiration_days'].' days')->format('Y-m-d');
    if($product['expiration_enabled']&&!$expiration)throw new InvalidArgumentException('Indica el vencimiento de cada lote o serie de este producto.');
    $dates=[];foreach(['alert_days','removal_days','best_before_days'] as $key)$dates[]=$expiration?(new DateTimeImmutable($expiration))->modify('-'.(int)$product[$key].' days')->format('Y-m-d'):null;
    if($expiration)foreach($dates as $date)trackingDate($date);
    $db->prepare('INSERT INTO inventory_lots(product_id,name,tracking_mode,expiration_date,alert_date,removal_date,best_before_date) VALUES(?,?,?,?,?,?,?)')->execute([$product['id'],$name,$product['tracking_mode'],$expiration,...$dates]);
    $q=$db->prepare('SELECT * FROM inventory_lots WHERE id=?');$q->execute([$db->lastInsertId()]);return $q->fetch();
}
function trackingLoadLine(PDO $db,int $line): array
{
    $q=$db->prepare('SELECT a.lot_id,a.quantity,t.name,t.expiration_date FROM inventory_lot_lines a JOIN inventory_lots t ON t.id=a.lot_id WHERE a.line_id=? ORDER BY a.id');$q->execute([$line]);return $q->fetchAll();
}
function trackingSaveLine(PDO $db,array $operation,array $line): void
{
    $p=trackingProduct($db,(int)$line['product_id'],true);
    $rows=trackingRows($line['tracking']??[]);
    if($p['tracking_mode']==='none'&&$rows)throw new InvalidArgumentException('Este producto no utiliza seguimiento.');
    $db->prepare('DELETE FROM inventory_lot_lines WHERE line_id=?')->execute([$line['id']]);
    $seen=[];
    foreach($rows as $row){
        if($p['tracking_mode']==='serial'&&bccomp($row['quantity'],'1',2)!==0)throw new InvalidArgumentException('Cada número de serie representa exactamente una unidad.');
        $lot=trackingLot($db,$p,$row,$operation['operation_type']==='receipt'&&empty($operation['return_of_id']));
        if(isset($seen[$lot['id']]))throw new InvalidArgumentException('No repitas un lote o serie dentro de la misma línea.');$seen[$lot['id']]=true;
        $db->prepare('INSERT INTO inventory_lot_lines(line_id,lot_id,quantity) VALUES(?,?,?)')->execute([$line['id'],$lot['id'],$row['quantity']]);
    }
}
function trackingReserved(PDO $db,int $lot,int $location,int $exclude=0): string
{
    // Stock transitions use READ COMMITTED plus the product mutex. Do not lock
    // another operation here: it may already be waiting for that same mutex.
    $q=$db->prepare("SELECT a.quantity FROM inventory_lot_lines a JOIN inventory_operation_lines l ON l.id=a.line_id JOIN inventory_operations o ON o.id=l.operation_id WHERE a.lot_id=? AND o.source_location_id=? AND o.status='ready' AND o.id<>?");$q->execute([$lot,$location,$exclude]);
    $sum='0.00';foreach($q->fetchAll(PDO::FETCH_COLUMN) as $qty)$sum=bcadd($sum,$qty,2);return $sum;
}
function trackingLockProducts(PDO $db,array $lines): array
{
    $ids=array_unique(array_map('intval',array_column($lines,'product_id')));sort($ids,SORT_NUMERIC);$products=[];
    foreach($ids as $id)$products[$id]=trackingProduct($db,$id,true);return $products;
}
function trackingIsCommercialOutput(array $operation): bool { return $operation['operation_type']==='delivery'&&empty($operation['return_of_id']); }
function trackingAvailableLots(PDO $db,array $product,int $location,int $exclude): array
{
    $q=$db->prepare('SELECT t.*,s.quantity FROM inventory_lots t JOIN inventory_lot_stock s ON s.lot_id=t.id WHERE t.product_id=? AND s.location_id=? AND s.quantity>0 ORDER BY COALESCE(t.removal_date,t.expiration_date,\'9999-12-31\'),t.id FOR UPDATE');$q->execute([$product['id'],$location]);$rows=$q->fetchAll();
    foreach($rows as &$row)$row['available']=bcsub($row['quantity'],trackingReserved($db,(int)$row['id'],$location,$exclude),2);unset($row);return $rows;
}
/** Called with order/operation/product locks; this does not commit independently. */
function trackingCheckOperation(PDO $db,array $operation,array $lines,bool $reserve): array
{
    $products=trackingLockProducts($db,$lines);$used=[];$hasQuantities=(bool)array_filter($lines,static fn($l)=>bccomp($l['quantity'],'0',2)>0);
    $location=(int)$operation['source_location_id'];$source=$db->prepare('SELECT location_type FROM inventory_locations WHERE id=?');$source->execute([$location]);$internal=$source->fetchColumn()==='internal';
    foreach($lines as &$line){
        $p=$products[(int)$line['product_id']];if($p['tracking_mode']==='none')continue;
        $quantity=$reserve&&!$hasQuantities?$line['demand']:$line['quantity'];
        if($p['tracking_mode']==='serial'&&bccomp($quantity,bcdiv($quantity,'1',0),2)!==0)throw new InvalidArgumentException('Los productos por serie solo admiten cantidades enteras.');
        $rows=trackingLoadLine($db,(int)$line['id']);
        if(bccomp($quantity,'0',2)===0){if($reserve)$db->prepare('DELETE FROM inventory_lot_lines WHERE line_id=?')->execute([$line['id']]);continue;}
        // Assign FEFO only on internal sources; return allocations must come from the original document.
        if(!$rows&&$reserve&&$internal&&empty($operation['return_of_id'])){
            $left=$quantity;
            foreach(trackingAvailableLots($db,$p,$location,(int)$operation['id']) as $lot){
                if(trackingIsCommercialOutput($operation)&&(($lot['expiration_date']&&$lot['expiration_date']<trackingToday())||($lot['removal_date']&&$lot['removal_date']<trackingToday())))continue;
                $available=bcsub($lot['available'],$used[$lot['id']]??'0',2);if(bccomp($available,'0',2)<=0)continue;
                $take=bccomp($left,$available,2)<0?$left:$available;
                $db->prepare('INSERT INTO inventory_lot_lines(line_id,lot_id,quantity) VALUES(?,?,?)')->execute([$line['id'],$lot['id'],$take]);
                $rows[]=['lot_id'=>$lot['id'],'quantity'=>$take];$left=bcsub($left,$take,2);if(bccomp($left,'0',2)===0)break;
            }
        }
        $total='0.00';
        foreach($rows as $row){
            $q=$db->prepare('SELECT * FROM inventory_lots WHERE id=? AND product_id=?');$q->execute([$row['lot_id'],$p['id']]);$lot=$q->fetch();if(!$lot)throw new InvalidArgumentException('Identificador no válido para el producto.');
            if($p['tracking_mode']==='serial'&&bccomp($row['quantity'],'1',2)!==0)throw new InvalidArgumentException('Una serie debe tener cantidad 1.');
            if($p['expiration_enabled']&&!$lot['expiration_date'])throw new InvalidArgumentException('Falta el vencimiento del lote o serie.');
            if(trackingIsCommercialOutput($operation)&&(($lot['expiration_date']&&$lot['expiration_date']<trackingToday())||($lot['removal_date']&&$lot['removal_date']<trackingToday())))throw new InvalidArgumentException('No puedes entregar lotes o series vencidos o retirados.');
            if(!empty($line['return_line_id'])){
                $q=$db->prepare('SELECT quantity FROM inventory_lot_lines WHERE line_id=? AND lot_id=?');$q->execute([$line['return_line_id'],$lot['id']]);$original=$q->fetchColumn();
                $q=$db->prepare("SELECT COALESCE(SUM(a.quantity),0) FROM inventory_lot_lines a JOIN inventory_operation_lines l ON l.id=a.line_id JOIN inventory_operations o ON o.id=l.operation_id WHERE l.return_line_id=? AND a.lot_id=? AND o.status='done' AND o.id<>?");$q->execute([$line['return_line_id'],$lot['id'],$operation['id']]);
                if($original===false||bccomp(bcadd($row['quantity'],(string)$q->fetchColumn(),2),$original,2)>0)throw new InvalidArgumentException('La devolución supera lo entregado de ese lote o serie.');
            }
            $used[$lot['id']]=bcadd($used[$lot['id']]??'0',$row['quantity'],2);
            if($internal){
                $q=$db->prepare('SELECT quantity FROM inventory_lot_stock WHERE lot_id=? AND location_id=? FOR UPDATE');$q->execute([$lot['id'],$location]);$onhand=$q->fetchColumn()?:'0';
                if(bccomp($used[$lot['id']],bcsub($onhand,trackingReserved($db,(int)$lot['id'],$location,(int)$operation['id']),2),2)>0)throw new InvalidArgumentException('No hay cantidad disponible del lote o serie en origen. Revisa reservas o identifica las existencias iniciales.');
            }elseif($p['tracking_mode']==='serial'){
                $q=$db->prepare("SELECT s.quantity FROM inventory_lot_stock s JOIN inventory_locations loc ON loc.id=s.location_id WHERE s.lot_id=? AND loc.location_type='internal' FOR UPDATE");$q->execute([$lot['id']]);$onhand='0';foreach($q->fetchAll(PDO::FETCH_COLUMN) as $qty)$onhand=bcadd($onhand,$qty,2);
                if(bccomp(bcadd($onhand,$used[$lot['id']],2),'1',2)>0)throw new InvalidArgumentException('Ese número de serie ya está en existencias o está repetido en esta recepción.');
            }
            $total=bcadd($total,$row['quantity'],2);
        }
        if(bccomp($total,$quantity,2)!==0)throw new InvalidArgumentException('Distribuye toda la cantidad de «'.$p['name'].'» entre sus lotes o series antes de continuar.');
        $line['tracking']=$rows;
    }
    unset($line);return $lines;
}
function trackingMove(PDO $db,int $lot,int $product,int $location,string $change,?int $operation,?int $line,string $reason): void
{
    $db->prepare('INSERT INTO inventory_lot_stock(lot_id,product_id,location_id,quantity) VALUES(?,?,?,0) ON DUPLICATE KEY UPDATE lot_id=VALUES(lot_id)')->execute([$lot,$product,$location]);
    $q=$db->prepare('SELECT quantity FROM inventory_lot_stock WHERE lot_id=? AND location_id=? FOR UPDATE');$q->execute([$lot,$location]);$next=bcadd((string)$q->fetchColumn(),$change,2);
    $q=$db->prepare('SELECT location_type FROM inventory_locations WHERE id=?');$q->execute([$location]);if($q->fetchColumn()==='internal'&&bccomp($next,'0',2)<0)throw new InvalidArgumentException('La cantidad del lote o serie no puede quedar negativa en almacén.');
    $db->prepare('UPDATE inventory_lot_stock SET quantity=? WHERE lot_id=? AND location_id=?')->execute([$next,$lot,$location]);
    $db->prepare('INSERT INTO inventory_lot_moves(lot_id,product_id,location_id,operation_id,line_id,quantity,reason) VALUES(?,?,?,?,?,?,?)')->execute([$lot,$product,$location,$operation,$line,$change,$reason]);
}
function trackingApplyOperation(PDO $db,array $operation,array $lines): void
{
    foreach($lines as $line){
        if(bccomp($line['quantity'],'0',2)<=0)continue;
        foreach($line['tracking']??[] as $row){
            trackingMove($db,(int)$row['lot_id'],(int)$line['product_id'],(int)$operation['source_location_id'],bcsub('0',$row['quantity'],2),(int)$operation['id'],(int)$line['id'],'movement');
            trackingMove($db,(int)$row['lot_id'],(int)$line['product_id'],(int)$operation['destination_location_id'],$row['quantity'],(int)$operation['id'],(int)$line['id'],'movement');
        }
    }
}
function trackingInitialize(PDO $db,int $product,int $location,mixed $input): void
{
    $rows=trackingRows($input);$db->beginTransaction();
    try{
        $p=trackingProduct($db,$product,true);if($p['tracking_mode']==='none')throw new InvalidArgumentException('Activa el seguimiento en la ficha del producto.');
        inventoryRequireConcreteProducts($db,[['product_id'=>$product]]);
        $q=$db->prepare("SELECT id FROM inventory_locations WHERE id=? AND location_type='internal'");$q->execute([$location]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona una ubicación interna de la empresa activa.');
        $q=$db->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=? FOR UPDATE');$q->execute([$product,$location]);$stock=$q->fetchColumn()?:'0';
        $q=$db->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_lot_stock WHERE product_id=? AND location_id=?');$q->execute([$product,$location]);$pending=bcsub($stock,(string)$q->fetchColumn(),2);
        if(bccomp($pending,'0',2)<=0)throw new InvalidArgumentException('No hay existencias pendientes de identificar en esa ubicación.');
        $total='0';$seen=[];
        foreach($rows as $row){
            $lot=trackingLot($db,$p,$row,true);
            if(isset($seen[$lot['id']]))throw new InvalidArgumentException('No repitas identificadores en la carga inicial.');$seen[$lot['id']]=true;
            if($p['tracking_mode']==='serial'){
                if(bccomp($row['quantity'],'1',2)!==0)throw new InvalidArgumentException('Cada serie debe tener cantidad 1.');
                $q=$db->prepare("SELECT COALESCE(SUM(s.quantity),0) FROM inventory_lot_stock s JOIN inventory_locations loc ON loc.id=s.location_id WHERE s.lot_id=? AND loc.location_type='internal'");$q->execute([$lot['id']]);if(bccomp((string)$q->fetchColumn(),'0',2)!==0)throw new InvalidArgumentException('La serie ya está en existencias.');
            }
            trackingMove($db,(int)$lot['id'],$product,$location,$row['quantity'],null,null,'initial');$total=bcadd($total,$row['quantity'],2);
        }
        if(bccomp($total,$pending,2)!==0)throw new InvalidArgumentException('La distribución debe sumar exactamente '.$pending.' unidades pendientes de identificar.');
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

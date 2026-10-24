<?php
declare(strict_types=1);

function inventoryProductParent(PDO $db,int $id,bool $lock=false): array
{
    $q=$db->prepare('SELECT * FROM inventory_products WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$parent=$q->fetch();
    if(!$parent||$parent['parent_product_id'])throw new InvalidArgumentException('Selecciona un producto principal válido. Una variante no puede tener otras variantes.');
    if($db instanceof CompanyConnection&&(int)$parent['company_id']!==$db->activeCompanyId())throw new InvalidArgumentException('Selecciona un producto principal de la empresa activa.');
    return $parent;
}

function inventoryCreateVariant(PDO $db,array $input): int
{
    $parentId=inventoryId($input['parent_product_id']??null);
    if(!$parentId)throw new InvalidArgumentException('Selecciona el producto principal.');
    $ownTransaction=!$db->inTransaction();if($ownTransaction)$db->beginTransaction();
    try{
        $parent=inventoryProductParent($db,$parentId,true);
        $data=array_intersect_key($parent,array_flip(['sale_enabled','purchase_enabled','sale_price','category_id','purchase_tax_id','sale_tax_id','product_type','invoice_policy','cost_price','currency_code','commission_type','commission_value']));
        $data['name']=is_string($input['name']??null)?trim($input['name']):'';$data['parent_product_id']=$parentId;
        if($data['name']===''||mb_strlen($data['name'])>160)throw new InvalidArgumentException('Escribe el nombre de la variante, de hasta 160 caracteres.');
        if(array_key_exists('sale_price',$input)&&$input['sale_price']!==''){
            $price=is_string($input['sale_price'])?str_replace(',','.',trim($input['sale_price'])):'';
            if(!preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/D',$price))throw new InvalidArgumentException('Indica un precio válido con hasta dos decimales.');
            $data['sale_price']=$price;
        }
        $commission=productCommissionUnit((string)$data['sale_price'],$data['commission_type'],$data['commission_value']);
        if($commission===null||bccomp(bcadd((string)$data['sale_price'],$commission,2),'9999999999.99',2)>0)throw new InvalidArgumentException('El precio para vendedor supera el máximo permitido.');
        $id=saveInventoryProduct($db,$data,null);
        $q=$db->prepare('SELECT supplier_id,price FROM product_supplier_prices WHERE product_id=?');$q->execute([$parentId]);
        foreach($q->fetchAll() as $row)$db->prepare('INSERT INTO product_supplier_prices(product_id,supplier_id,price) VALUES(?,?,?)')->execute([$id,$row['supplier_id'],$row['price']]);
        if($ownTransaction)$db->commit();return $id;
    }catch(Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}

function inventoryProductVariants(PDO $db,int $parentId): array
{
    $q=$db->prepare('SELECT id,name,parent_product_id,sale_price,currency_code,sale_enabled,purchase_enabled FROM inventory_products WHERE parent_product_id=? ORDER BY name,id');$q->execute([$parentId]);return $q->fetchAll();
}

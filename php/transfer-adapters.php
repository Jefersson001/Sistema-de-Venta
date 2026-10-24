<?php
declare(strict_types=1);
foreach(['contacts-repository','inventory-repository','storage-repository','sales-repository','commerce-repository','inventory-stock-repository','inventory-operations-repository','payments-repository','treasury-repository','tax-repository','currency-catalog-repository','company-repository','commission-repository','workflow-invoices'] as $repository)require_once __DIR__.'/'.$repository.'.php';

function transferValidated(array $result): array
{
    if($result[1])throw new InvalidArgumentException(implode(' ',array_unique($result[1])));return $result[0];
}
function transferList(mixed $value): array { return $value===''||$value===null?[]:(is_array($value)?$value:array_values(array_filter(array_map('trim',explode('|',$value))))); }
function transferWriteMaster(PDO $db,string $table,array $data,?int $id): int
{
    $fields=array_keys($data);$sql=$id?'UPDATE '.$table.' SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).' WHERE id=?'
        :'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')';
    $values=array_values($data);if($id)$values[]=$id;$db->prepare($sql)->execute($values);return $id??(int)$db->lastInsertId();
}

/** Invoke the same validators and repositories used by the forms. */
function transferSave(PDO $db,array $m,array $input,?array $old,string $key): int
{
    $id=$old?(int)$old['id']:null;$version=$old['version']??null;$actor=(int)permissionUser()['id'];$save=$m['save'];
    if(is_callable($save))return $save($db,$input,$old,$key);
    if($save==='contacts') {
        $data=transferValidated(validateContact($input,loadContactCatalogs($db),loadContactSalespeople($db,accessId($old['salesperson_id']??null),$id),$id,loadStorageRelations($db)));
        $data['active']=(int)($input['active']??'1');return saveContact($db,$data,$id);
    }
    if($save==='products') {
        $input['currency_code']??=accountingCurrencies($db)['primary_code'];
        if(!in_array($input['currency_code'],array_merge(accountingCurrencyCodes(accountingCurrencies($db)),[$old['currency_code']??null]),true))throw new InvalidArgumentException('Selecciona una moneda configurada.');
        $saved=saveInventoryProduct($db,transferValidated(validateInventoryProduct($input,loadInventoryCategories($db),loadBusinessTaxes($db,'compras'),loadBusinessTaxes($db,'ventas'))),$id);
        if(isset($input['lines'])) {
            if(!permissionHas('inventario.products','view_cost'))throw new InvalidArgumentException('No tienes permiso para importar precios de proveedor.');
            $seen=[];$rows=[];
            foreach($input['lines'] as $line){$supplier=accessId($line['supplier_id']??null);$price=salesDecimal($line['price']??null,10,2);$q=$db->prepare("SELECT 1 FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=? AND c.active=1 AND t.system_key='supplier'");$q->execute([$supplier]);if(!$supplier||isset($seen[$supplier])||$price===null||!$q->fetchColumn())throw new InvalidArgumentException('Selecciona proveedores activos sin repetir y precios válidos.');$seen[$supplier]=true;$rows[]=[$saved,$supplier,$price];}
            $db->prepare('DELETE FROM product_supplier_prices WHERE product_id=?')->execute([$saved]);$q=$db->prepare('INSERT INTO product_supplier_prices(product_id,supplier_id,price) VALUES(?,?,?)');foreach($rows as $row)$q->execute($row);
        }
        return $saved;
    }
    if($save==='categories')return saveInventoryCategory($db,transferValidated(validateInventoryCategory($input+['restaurant_enabled'=>$old['restaurant_enabled']??0],loadInventoryCategories($db),$id)),$id);
    if(in_array($save,['warehouses','locations'],true))return saveInventoryStorage($db,$save,transferValidated(validateInventoryStorage($save,$input,loadInventoryWarehouses($db),loadInventoryLocations($db),$id)),$id);
    if($save==='contact_catalog') {
        $data=transferValidated(validateContactCatalog($m['section'],$input,loadContactCatalogs($db),$id));saveContactCatalog($db,$m['section'],$data,$id);return $id??(int)$db->lastInsertId();
    }
    if($save==='taxes')return saveBusinessTax($db,$m['domain']['module_key'],transferValidated(validateBusinessTax($input)),$id);
    if(in_array($save,['sale_orders','purchase_orders'],true)) {
        $sale=$save==='sale_orders';$existing=$id?($sale?loadSalesQuotation($db,$id):loadPurchaseOrder($db,$id)):null;
        $input['order_type']??=$sale?'delivery':'receipt';$input['currency_code']??=accountingCurrencies($db)['primary_code'];
        if(!array_key_exists('lines',$input)&&$existing)$input['lines']=$existing['lines'];
        $catalogs=$sale?loadSalesCatalogs($db,$existing):loadPurchaseCatalogs($db,$existing);
        [$data,$lines,$errors]=$sale?validateSalesQuotation($input,$catalogs,$existing):validatePurchaseOrder($input,$catalogs,$existing);
        if($errors)throw new InvalidArgumentException(implode(' ',array_unique($errors)));
        return $sale?saveSalesQuotation($db,$data,$lines,$id,$version):savePurchaseOrder($db,$data,$lines,$id,$version);
    }
    if($save==='operations') {
        $storage=loadStorageRelations($db);$q=$db->prepare('SELECT c.* FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE t.system_key=? AND (c.active=1 OR c.id=?)');$q->execute([inventoryOperationTypes()[$m['type']]['role'],$old['contact_id']??null]);$contacts=permissionFilterContacts($q->fetchAll());
        $data=transferValidated(validateInventoryOperation($input,$m['type'],$storage,$contacts));
        if($id){$q=$db->prepare('SELECT * FROM inventory_operation_lines WHERE operation_id=? ORDER BY position,id');$q->execute([$id]);$oldLines=$q->fetchAll();
            if(!isset($input['lines']))$input['lines']=$oldLines;
            else foreach($input['lines'] as $position=>&$line)if(isset($oldLines[$position])&&(int)$oldLines[$position]['product_id']===(int)($line['product_id']??0))$line['id']=$oldLines[$position]['id'];unset($line);
        }
        $products=permissionHas('inventario.products')?$db->query("SELECT id,name FROM inventory_products WHERE product_type='goods'")->fetchAll():[];
        $lines=transferValidated(validateInventoryOperationLines($input['lines']??[],$products));return saveInventoryOperation($db,$data,$id,$version,$lines);
    }
    if($save==='payments'){if(($input['concept_id']??null)==='')unset($input['concept_id']);return saveAccountingPayment($db,$input,$id,$version,$key);}
    if($save==='treasury')return saveTreasuryDocument($db,$input,$id,$version,$key);
    if($save==='invoices') {
        if(!$id) {
            $source=accessId($input['source_order_id']??null);if(!$source)throw new InvalidArgumentException('Indica una orden de origen confirmada.');
            $kind=$m['type']==='customer'?'sale':'purchase';$resource=$kind==='sale'?'ventas.orders':'compras.orders';
            if(!permissionHas($resource,'create_invoice')||!permissionRecord($resource,$source,'create_invoice'))throw new InvalidArgumentException('No tienes permiso para facturar esa orden.');
            $id=workflowCreateInvoice($db,$kind,$source);
            $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();
        }
        if(!$old||$old['status']!=='draft')throw new InvalidArgumentException('Solo puedes importar fechas de facturas en borrador.');
        $date=treasuryDate($input['invoice_date']??$old['invoice_date']);$due=($input['due_date']??$old['due_date'])?:null;
        if($due){$due=treasuryDate($due);if($due<$date)throw new InvalidArgumentException('El vencimiento no puede ser anterior a la fecha de factura.');}
        workflowLockOrder($db,['quotation_id'=>$old['sale_id'],'purchase_order_id'=>$old['purchase_id']]);
        $snapshot=invoiceExchangeSnapshot($db,$old,$date);
        $db->prepare('UPDATE accounting_invoices SET invoice_date=?,due_date=?,exchange_rate=?,exchange_rate_date=?,exchange_rate_source=?,version=version+1 WHERE id=?')->execute([$date,$due,$snapshot['exchange_rate'],$snapshot['exchange_rate_date'],$snapshot['exchange_rate_source'],$id]);return $id;
    }
    if($save==='commission_payments') {
        if($id)throw new InvalidArgumentException('Los pagos de comisiones existentes se revierten desde su ficha.');
        $invoice=accessId($input['invoice_id']??null);if(!$invoice||!permissionRecord('ventas.commissions',$invoice,'pay'))throw new InvalidArgumentException('La factura no está autorizada.');
        $input['movement_date']=$input['payment_date']??'';return paySalesCommission($db,$invoice,$input,$key);
    }
    if($save==='currencies') {
        $existing=$id?loadCurrencyRecord($db,$id):null;$input['rates']=$input['lines']??($existing['rates']??[]);
        foreach($input['rates'] as &$rate)foreach($existing['rates']??[] as $oldRate)if((int)$oldRate['base_currency_id']===(int)($rate['base_currency_id']??0)&&$oldRate['rate_date']===($rate['rate_date']??''))$rate['id']=$oldRate['id'];unset($rate);
        return saveCurrencyRecord($db,$input,$id,$version);
    }
    if($save==='currency_settings') {
        foreach(['primary_code'=>'primary_id','alternate_code'=>'alternate_id'] as $code=>$field){$q=$db->prepare('SELECT id FROM accounting_currencies WHERE code=? AND active=1');$q->execute([$input[$code]??'']);$input[$field]=(string)$q->fetchColumn();}
        $input['version']=(string)$version;saveBusinessCurrencies($db,$input);return 1;
    }
    if($save==='users') {
        $existing=$id?loadAuthUser($db,$id):null;$input['groups']=isset($input['groups'])?transferList($input['groups']):array_map('strval',$existing['group_ids']??[]);
        if(array_key_exists('company_ids',$input)){$input['companies_present']='1';$input['company_ids']=!$id&&$input['company_ids']===''?[(string)companyActiveId()]:transferList($input['company_ids']);}
        $input['password_confirmation']=$input['password']??'';
        return saveAuthUser($db,$input,$id,$version,$actor,$id?null:$key);
    }
    if($save==='groups') {
        $existing=$id?loadAccessGroup($db,$id):null;
        $input['permissions']=isset($input['permissions'])?transferList($input['permissions']):array_keys($existing['permissions']??[]);
        if(isset($input['scopes'])){try{$input['scopes']=json_decode($input['scopes'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('Los alcances deben ser un objeto JSON.');}}
        else { $input['scopes']=[];foreach($existing['permissions']??[] as $code=>$scope)$input['scopes'][substr($code,0,strrpos($code,'.'))]=$scope; }
        if(!is_array($input['scopes']))throw new InvalidArgumentException('Alcances de permisos inválidos.');
        return saveAccessGroup($db,$input,$id,$version,$id?null:$key);
    }
    if($save==='companies') {
        if(!$id)return createBusinessCompany($db,$input,$actor);
        saveBusinessCompany($db,$input,(int)$version,null,false,$id);return $id;
    }
    if($save==='master') {
        $schema=transferSchema($db,$m['table']);$data=[];foreach($m['write'] as $field)if(array_key_exists($field,$input))$data[$field]=$input[$field]===''&&$schema[$field]['nullable']?null:$input[$field];
        $saved=transferWriteMaster($db,$m['table'],$data,$id);if($id&&isset($schema['version']))$db->prepare('UPDATE '.$m['table'].' SET version=version+1 WHERE id=?')->execute([$id]);return $saved;
    }
    if(in_array($save,['payment_terms','accounts','methods','concepts'],true)) {
        $name=trim($input['name']??'');if($name===''||mb_strlen($name)>($save==='payment_terms'?120:160))throw new InvalidArgumentException('Revisa el nombre del registro.');$data=['name'=>$name];
        if($save==='payment_terms'){$days=filter_var($input['days']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>3650]]);if($days===false)throw new InvalidArgumentException('Días debe ser un entero entre 0 y 3650.');$data['days']=$days;}
        if($save==='methods')$data['allow_change']=(int)($input['allow_change']??$old['allow_change']??0);
        if($save==='accounts') {
            $type=$input['account_type']??'';$currency=$input['currency_code']??'';
            if(!in_array($type,['cash','bank'],true)||($currency!==''&&!in_array($currency,array_merge(accountingCurrencyCodes(accountingCurrencies($db)),[$old['currency_code']??null]),true)))throw new InvalidArgumentException('Revisa el tipo de cuenta y su moneda.');
            $data+=['account_type'=>$type,'currency_code'=>$currency?:null];
            if($id&&($old['account_type']!==$type||$old['currency_code']!==($currency?:null)))foreach(['accounting_treasury_entries','accounting_payments','accounting_treasury_documents'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE account_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('Una cuenta utilizada conserva su tipo y moneda.');}
        }
        if($save==='concepts') {
            $direction=$input['direction']??'';if(!in_array($direction,['inbound','outbound','both'],true))throw new InvalidArgumentException('Selecciona el sentido del concepto.');
            if($old&&$old['direction']!==$direction)foreach(['accounting_treasury_entries','accounting_payments','accounting_treasury_documents'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE concept_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('Un concepto utilizado conserva su sentido.');}
            $data+=['direction'=>$direction,'active'=>(int)($input['active']??'1')];
        }
        return transferWriteMaster($db,$m['table'],$data,$id);
    }
    throw new InvalidArgumentException('Este modelo es de consulta; sus datos se generan desde las operaciones de origen.');
}

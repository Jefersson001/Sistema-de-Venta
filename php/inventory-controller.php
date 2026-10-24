<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/inventory-repository.php';
require_once __DIR__ . '/tax-repository.php';
require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/inventory-stock-repository.php';
require_once __DIR__ . '/storage-repository.php';
require_once __DIR__.'/currency-repository.php';

function inventoryEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderInventoryProducts(bool $variantCreation=false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['inventory_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'list',
        'csrf' => $_SESSION['inventory_csrf'], 'flash' => $_SESSION['inventory_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['inventory_flash']);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['available'] = true;
        $page['variantCreation']=$variantCreation;
        if($variantCreation){
            $page['area']='variant_form';
            $page['variantParents']=$db->query('SELECT id,name FROM inventory_products WHERE parent_product_id IS NULL AND company_id='.($db instanceof CompanyConnection?$db->activeCompanyId():1).' ORDER BY name,id')->fetchAll();
            $parentValue=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['parent_product_id']??null):($_GET['parent_id']??null);
            $parentId=inventoryId($parentValue);
            if($parentValue!==null&&$parentValue!==''&&!$parentId)throw new InvalidArgumentException('Selecciona un producto principal válido.');
            if($parentId){
                $parent=inventoryProductParent($db,$parentId);
                $page['record']=array_intersect_key($parent,array_flip(['name','sale_enabled','purchase_enabled','sale_price','category_id','purchase_tax_id','sale_tax_id','product_type','invoice_policy','cost_price','currency_code','commission_type','commission_value']));
                $page['record']['parent_product_id']=$parentId;
            }
        }
        $id = inventoryId($_GET['id'] ?? null);
        if($variantCreation&&isset($_GET['id']))throw new InvalidArgumentException('Crea la variante sin indicar un producto existente.');
        $page['view'] = $id || isset($_GET['new']) ? 'form' : 'list';
        if (isset($_GET['id']) && ! $id) {
            http_response_code(404);
            $page['available'] = false;
            $page['errors'][] = 'El producto solicitado no existe.';
        }
        if ($id) {
            $statement = $db->prepare('SELECT id, name, sale_enabled, purchase_enabled, sale_price, category_id, purchase_tax_id, sale_tax_id, product_type, invoice_policy, cost_price, currency_code, commission_type, commission_value,parent_product_id FROM inventory_products WHERE id = ?');
            $statement->execute([$id]);
            $page['record'] = $statement->fetch() ?: [];
            if (! $page['record']) {
                http_response_code(404);
                $page['available'] = false;
                $page['errors'][] = 'El producto solicitado no existe.';
            }
        }
        if ($page['view'] === 'form' && $page['available']) {
            $page['variants']=$id?inventoryProductVariants($db,$id):[];
            $page['variantParent']=null;
            if(!empty($page['record']['parent_product_id'])){
                $q=$db->prepare('SELECT id,name FROM inventory_products WHERE id=?');$q->execute([$page['record']['parent_product_id']]);$page['variantParent']=$q->fetch();$page['area']='variant_form';
            }
            $page['settings']=accountingCurrencies($db);
            $page['categories'] = loadInventoryCategories($db);
            $page['purchaseTaxes'] = loadBusinessTaxes($db, 'compras');
            $page['saleTaxes'] = loadBusinessTaxes($db, 'ventas');
            $page['productStock'] = $id && permissionHas('inventario.stock') ? loadInventoryProductStock($db, $id) : ['total' => null, 'reserved'=>null,'available'=>null,'locations' => []];
            $page['productIndicators'] = $id ? loadInventoryProductIndicators($db, $id) : ['incoming' => '0', 'outgoing' => '0', 'sold' => '0.000', 'purchased' => '0.000'];
            $page['storage'] = loadStorageRelations($db);
            $page['suppliers']=$db->query("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE t.system_key='supplier' AND c.active=1 ORDER BY c.name")->fetchAll();
            $prices=$db->prepare('SELECT * FROM product_supplier_prices WHERE product_id=? ORDER BY id');$prices->execute([$id??($variantCreation?($page['record']['parent_product_id']??null):null)]);$page['supplierPrices']=$prices->fetchAll();
            $page['productRevision']=$id?($_SERVER['REQUEST_METHOD']==='POST'?(is_string($_POST['product_revision']??null)?$_POST['product_revision']:''):inventoryProductRevision($page['record'],$page['supplierPrices'])):null;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== 'save_product') {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                if(permissionHas('inventario.products','view_cost')&&is_array($_POST['supplier_prices']??null)){
                    $page['supplierPrices']=[];
                    foreach($_POST['supplier_prices'] as $row)if(is_array($row)){
                        $supplier=inventoryId($row['supplier_id']??null);$price=is_string($row['price']??null)?$row['price']:'';
                        if($supplier||!in_array($price,['','0','0.00'],true))$page['supplierPrices'][]=['supplier_id'=>$supplier,'price'=>$price];
                    }
                }
                $input=$_POST;$input['currency_code']??=$page['record']['currency_code']??$page['settings']['primary_code'];
                $input['parent_product_id']=$page['record']['parent_product_id']??null;
                if($variantCreation&&!$input['parent_product_id'])$page['errors'][]='Selecciona el producto principal de la variante.';
                $input['commission_type']??=$page['record']['commission_type']??'none';
                $input['commission_value']??=$page['record']['commission_value']??'0.0000';
                if(!in_array($input['currency_code'],array_merge(accountingCurrencyCodes($page['settings']),[$page['record']['currency_code']??null]),true)){$page['errors'][]='Selecciona una moneda configurada.';}
                [$data, $productErrors] = validateInventoryProduct($input, $page['categories'], $page['purchaseTaxes'], $page['saleTaxes']);
                $page['errors']=array_merge($page['errors'],$productErrors);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    try {
                        $supplierRows=[]; $seen=[];
                        foreach(is_array($_POST['supplier_prices']??null)?$_POST['supplier_prices']:[] as $row) {
                            if(!is_array($row))throw new InvalidArgumentException('Precios de proveedor inválidos.');
                            if(empty($row['supplier_id']))continue;
                            $supplier=inventoryId($row['supplier_id']);$price=is_string($row['price']??null)?str_replace(',','.',trim($row['price'])):'';
                            if(!inventoryCategoryRecord($page['suppliers'],$supplier) || isset($seen[$supplier]) || !preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/',$price))throw new InvalidArgumentException('Selecciona proveedores sin repetir e importes válidos.');
                            $supplierRows[]=[$supplier,$price];$seen[$supplier]=true;
                        }
                        $db->beginTransaction(); $saved=saveInventoryProduct($db,$data,$id,$id?$page['productRevision']:null);
                        if(permissionHas('inventario.products','view_cost')){
                            $db->prepare('DELETE FROM product_supplier_prices WHERE product_id=?')->execute([$saved]);
                            foreach($supplierRows as [$supplier,$price])$db->prepare('INSERT INTO product_supplier_prices (product_id,supplier_id,price) VALUES (?,?,?)')->execute([$saved,$supplier,$price]);
                        }elseif($variantCreation&&!$id){
                            foreach($page['supplierPrices'] as $row)$db->prepare('INSERT INTO product_supplier_prices(product_id,supplier_id,price) VALUES(?,?,?)')->execute([$saved,$row['supplier_id'],$row['price']]);
                        }
                        $db->commit();
                    } catch (InvalidArgumentException $e) { if($db->inTransaction())$db->rollBack();if($e->getCode()===409)http_response_code(409);$page['errors'][]=$e->getMessage(); }
                    catch (Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
                    if(!$page['errors']) {
                    $_SESSION['inventory_flash'] = !empty($data['parent_product_id'])?'Variante guardada correctamente.':'Producto guardado correctamente.';
                    header('Location: '.(!empty($data['parent_product_id'])?'variantes.php?parent_id='.(int)$data['parent_product_id']:'index.php'), true, 303);
                    exit; }
                }
            }
        }
        if ($page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
            $where = ' WHERE p.parent_product_id IS NULL'.($page['search'] !== '' ? ' AND p.name LIKE :search' : '');
            $params = $page['search'] !== '' ? ['search' => '%' . $page['search'] . '%'] : [];
            $count = $db->prepare('SELECT COUNT(*) FROM inventory_products p' . $where);
            $count->execute($params);
            $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20));
            $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $offset = ($page['currentPage'] - 1) * 20;
            $list = $db->prepare('SELECT p.id,p.name,p.sale_enabled,p.purchase_enabled,(SELECT COUNT(*) FROM inventory_products v WHERE v.parent_product_id=p.id) AS variants_count FROM inventory_products p'
                . $where . ' ORDER BY p.name,p.id LIMIT 20 OFFSET ' . $offset);
            $list->execute($params);
            $page['products'] = $list->fetchAll();
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);$page['available']=false;$page['errors'][]=$exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Productos: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los productos. Verifica la conexión y la instalación de Inventario.';
    }
    renderModulePage('inventario', false, $page);
}

function renderInventoryConfiguration(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['inventory_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'home',
        'csrf' => $_SESSION['inventory_csrf'], 'flash' => $_SESSION['inventory_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['inventory_flash']);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['categories'] = loadInventoryCategories($db);
        $page['warehouses'] = loadInventoryWarehouses($db);
        $page['locations'] = loadInventoryLocations($db);
        $page['available'] = true;
        $section = $_GET['section'] ?? null;
        $definitions = inventoryConfigurationDefinitions();
        $page['section'] = is_string($section) && isset($definitions[$section]) ? $section : null;
        $id = inventoryId($_GET['id'] ?? null);
        if (($section !== null && $page['section'] === null)
            || ($section === null && (isset($_GET['id']) || isset($_GET['new'])))
            || (isset($_GET['id']) && ! $id)) {
            http_response_code(404);
            $page['available'] = false;
            $page['errors'][] = 'La página solicitada no existe.';
        }
        $page['view'] = $section === null ? 'home' : ($id || isset($_GET['new']) ? 'form' : 'list');
        if ($id && $page['available']) {
            $page['record'] = inventoryCategoryRecord($page[$page['section']], $id) ?? [];
            if (! $page['record']) {
                http_response_code(404);
                $page['available'] = false;
                $page['errors'][] = 'El registro solicitado no existe.';
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== $definitions[$page['section']]['action']) {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                [$data, $page['errors']] = $section === 'categories'
                    ? validateInventoryCategory($_POST, $page['categories'], $id)
                    : validateInventoryStorage($section, $_POST, $page['warehouses'], $page['locations'], $id);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    try {
                        if ($section === 'categories') { saveInventoryCategory($db, $data, $id); }
                        else { saveInventoryStorage($db, $section, $data, $id); }
                        $_SESSION['inventory_flash'] = 'Registro guardado correctamente.';
                        header('Location: configuracion.php?section=' . $section, true, 303);
                        exit;
                    } catch (InvalidArgumentException $exception) {
                        $page['errors'][] = $exception->getMessage();
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 1062) {
                            throw $exception;
                        }
                        $page['errors'][] = match ($section) {
                            'categories' => 'Ya existe una categoría con ese nombre dentro de la misma categoría superior.',
                            'warehouses' => 'Ya existe un almacén con ese nombre.',
                            'locations' => 'Ya existe una ubicación con ese nombre dentro del mismo almacén y ubicación superior.',
                        };
                    }
                }
            }
        }
        $records = $page[$page['section'] ?? 'categories'];
        $page['excludedParents'] = $id && $section !== 'warehouses' ? inventoryCategoryDescendants($records, $id) : [];
        $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
        $term = mb_strtolower($page['search'], 'UTF-8');
        $filtered = array_values(array_filter($records, static function ($record) use ($term, $section, $records, $page): bool {
            $name = $section === 'warehouses' ? $record['name'] : inventoryCategoryPath($records, $record);
            if ($section === 'locations') { $name .= ' ' . (inventoryCategoryRecord($page['warehouses'], (int) $record['warehouse_id'])['name'] ?? ''); }
            return $term === '' || str_contains(mb_strtolower($name, 'UTF-8'), $term);
        }));
        $page['total'] = count($filtered);
        $page['pages'] = max(1, (int) ceil($page['total'] / 20));
        $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
        $page['rows'] = array_slice($filtered, ($page['currentPage'] - 1) * 20, 20);
    } catch (PDOException $exception) {
        error_log('Inventario: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudo cargar la configuración. Verifica la conexión y la instalación de Inventario.';
    }
    renderModulePage('inventario', true, $page);
}

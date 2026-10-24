<?php
declare(strict_types=1);
require_once __DIR__.'/inventory-controller.php';

function renderInventoryVariants(): void
{
    if(isset($_GET['new'])){renderInventoryProducts(true);return;}
    $page=['area'=>'variants','available'=>true,'errors'=>[],'record'=>[],'view'=>'list',
        'csrf'=>$_SESSION['inventory_csrf']??$_SESSION['auth_csrf'],'flash'=>$_SESSION['inventory_flash']??null];
    unset($_SESSION['inventory_flash']);
    try{
        $db=createDatabaseConnection();
        if(isset($_GET['id']))throw new InvalidArgumentException('Edita la variante desde su ficha de producto.',404);
        $page['parents']=$db->query('SELECT id,name,sale_price,currency_code FROM inventory_products WHERE parent_product_id IS NULL AND company_id='.($db instanceof CompanyConnection?$db->activeCompanyId():1).' ORDER BY name,id')->fetchAll();
        $page['parentId']=inventoryId($_GET['parent_id']??null);
        if(isset($_GET['parent_id'])&&$_GET['parent_id']!==''&&!$page['parentId'])throw new InvalidArgumentException('Selecciona un producto principal válido.');
        if($_SERVER['REQUEST_METHOD']==='POST')throw new InvalidArgumentException('Acción no válida.');
        if($page['view']==='list'){
            $page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,160):'';
            $where=' WHERE v.parent_product_id IS NOT NULL';$parameters=[];
            if($page['parentId']){$where.=' AND v.parent_product_id=?';$parameters[]=$page['parentId'];}
            if($page['search']!==''){$where.=' AND (v.name LIKE ? OR p.name LIKE ?)';$parameters[]='%'.$page['search'].'%';$parameters[]='%'.$page['search'].'%';}
            $from=' FROM inventory_products v JOIN inventory_products p ON p.id=v.parent_product_id';
            $q=$db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($parameters);$page['total']=(int)$q->fetchColumn();
            $page['pages']=max(1,(int)ceil($page['total']/20));$page['currentPage']=min(inventoryId($_GET['page']??null)??1,$page['pages']);
            $q=$db->prepare('SELECT v.id,v.name,v.parent_product_id,v.sale_price,v.currency_code,p.name AS parent_name'.$from.$where.' ORDER BY p.name,v.name,v.id LIMIT 20 OFFSET '.(($page['currentPage']-1)*20));
            $q->execute($parameters);$page['variants']=$q->fetchAll();
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,404,409],true)?$e->getCode():422);$page['errors'][]=$e->getMessage();if($page['view']==='list'||in_array($e->getCode(),[403,404],true))$page['available']=false;}
    catch(PDOException $e){error_log('Variantes: '.$e->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudieron cargar las variantes. Verifica la instalación de Inventario.';}
    renderModulePage('inventario',false,$page);
}

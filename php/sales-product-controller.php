<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/sales-repository.php';

function renderSalesProductSnapshot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD']!=='GET') {
        header('Allow: GET');http_response_code(405);echo json_encode(['error'=>'La consulta solo admite GET.']);return;
    }
    try {
        $id=salesId($_GET['id']??null);
        if (!$id) throw new InvalidArgumentException('Selecciona un producto válido.');
        $db=createDatabaseConnection();
        $q=$db->prepare('SELECT id,name,sale_price,currency_code,commission_type,commission_value,sale_tax_id FROM inventory_products WHERE id=?');
        $q->execute([$id]);$product=$q->fetch();
        if (!$product) { http_response_code(404);echo json_encode(['error'=>'El producto solicitado no existe.']);return; }
        if ($product['commission_type']==='fixed') $product['commission_value']=bcadd($product['commission_value'],'0',2);
        $tax=null;
        if ($product['sale_tax_id']) {
            $q=$db->prepare("SELECT id,name,rate FROM business_taxes WHERE id=? AND module_key='ventas'");$q->execute([$product['sale_tax_id']]);$tax=$q->fetch()?:null;
        }
        echo json_encode(['product'=>$product,'tax'=>$tax],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);echo json_encode(['error'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Configuración del producto para venta: '.$exception->getMessage());
        http_response_code(503);echo json_encode(['error'=>'No se pudo consultar el precio, la comisión y el impuesto del producto. Vuelve a seleccionarlo.']);
    }
}

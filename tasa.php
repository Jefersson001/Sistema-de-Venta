<?php
declare(strict_types=1);
require_once __DIR__.'/php/auth.php';require_once __DIR__.'/php/permissions.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
$GLOBALS['permission_runtime']=true;$user=authRequireLogin(true);$GLOBALS['permission_user']=$user;
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'Solo se admite consulta.',true);}
require_once __DIR__.'/php/exchange-rate-status.php';require_once __DIR__.'/php/workflow-invoices.php';
try{
    $db=createDatabaseConnection();
    if(isset($_GET['invoice_id'])){
        $id=accessId($_GET['invoice_id']);if(!$id)throw new InvalidArgumentException('Factura no válida.');
        $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$id]);$invoice=$q->fetch();
        if(!$invoice)authStop(404,'La factura no existe.',true);
        $resource='contabilidad.'.$invoice['invoice_type'].'_invoices';
        if(!permissionHas($resource)||!permissionRecord($resource,$id))authStop(403,'No tienes acceso a esta factura.',true);
        $date=cashFlowDateForRate($_GET['date']??null);
        if($invoice['status']!=='draft'&&$date!==$invoice['invoice_date'])throw new InvalidArgumentException('La factura registrada conserva su fecha y tasa.');
        $snapshot=$invoice['status']==='draft'?invoiceExchangeSnapshot($db,$invoice,$date):array_intersect_key($invoice,array_flip(['exchange_rate','exchange_rate_date','exchange_rate_source']));
        echo json_encode($snapshot+['reference'=>invoiceCurrencyReference(array_replace($invoice,$snapshot)),'scope'=>companyScopeToken()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }else echo json_encode(dailyExchangeRateStatus($db),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){authStop(422,$e->getMessage(),true);}
catch(Throwable $e){error_log('Consulta de tasa: '.$e->getMessage());authStop(503,'No se pudo consultar la tasa guardada.',true);}

function cashFlowDateForRate(mixed $value): string
{
    $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
    if(!$date||$date->format('Y-m-d')!==$value||$value<'1000-01-01'||$value>'9999-12-31')throw new InvalidArgumentException('Selecciona una fecha válida.');return $value;
}

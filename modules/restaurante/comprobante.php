<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/php/auth-bootstrap.php';
require_once dirname(__DIR__,2).'/php/restaurant-controller.php';
require_once dirname(__DIR__,2).'/php/restaurant-receipt.php';
try{
    $receipt=restaurantLoadReceipt(createDatabaseConnection(),restaurantInt($_GET['id']??null));
    header('Cache-Control: private, no-store');$h='restaurantEscape';
    require dirname(__DIR__,2).'/html/restaurant/receipt.php';
}catch(InvalidArgumentException $e){authStop(in_array($e->getCode(),[403,404],true)?$e->getCode():422,$e->getMessage());}
catch(Throwable $e){error_log('Comprobante restaurante: '.$e->getMessage());authStop(503,'No se pudo cargar el comprobante. La factura y los pagos se conservan.');}

<?php
declare(strict_types=1);
require_once __DIR__.'/php/record-actions.php';
try{renderRecordActions();}
catch(Throwable $e){error_log('Acciones comunes: '.$e->getMessage());authStop(503,'No se pudo abrir la acción. Intenta nuevamente.');}

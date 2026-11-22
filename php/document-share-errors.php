<?php
declare(strict_types=1);

final class DocumentSharePreparationException extends RuntimeException
{
    public function __construct(public readonly string $stage, Throwable $previous)
    {
        parent::__construct('Document sharing failed at '.$stage, 0, $previous);
    }
}

/** Keep SQL, document contents, credentials and tokens out of application logs. */
function documentShareFailure(Throwable $error): string
{
    $reference=bin2hex(random_bytes(6));
    $stage=$error instanceof DocumentSharePreparationException?$error->stage:'preparation';
    $cause=$error instanceof DocumentSharePreparationException?$error->getPrevious():$error;
    $details='';
    if($cause instanceof PDOException){
        $state=(string)($cause->errorInfo[0]??'');$number=(string)($cause->errorInfo[1]??'');
        if(preg_match('/^[A-Z0-9]{5}$/D',$state))$details.=' sqlstate='.$state;
        if(ctype_digit($number))$details.=' driver_code='.$number;
    }
    error_log('[WhatsApp '.$reference.'] stage='.$stage.' exception='.get_class($cause).' file='.basename($cause->getFile()).' line='.$cause->getLine().$details);
    $message=match($stage){
        'encryption'=>'El servidor no puede utilizar la clave de cifrado. El administrador debe revisar NEXO_MAIL_ENCRYPTION_KEY y los permisos de la clave existente.',
        'pdf'=>'No se pudieron generar los PDF. El administrador debe revisar las dependencias y los permisos del generador PDF en el servidor.',
        'storage'=>'No se pudieron guardar los documentos. El administrador debe revisar la conexión y los límites de la base de datos.',
        'recovery'=>'No se pudo recuperar el enlace. El administrador debe comprobar que se conserva la misma clave de cifrado utilizada al crearlo.',
        default=>'No se pudo preparar el enlace. Solicita al administrador revisar el registro de errores del servidor.',
    };
    return $message.' Referencia: '.$reference.'.';
}

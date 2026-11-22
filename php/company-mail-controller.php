<?php
declare(strict_types=1);
require_once __DIR__.'/company-mail.php';
require_once __DIR__.'/module-page.php';
function renderCompanyMail(): void {
    authPrivacyHeaders();$db=createDatabaseConnection();$company=companyActiveId();$errors=[];$notice='';
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            permissionRequire('ajustes.mail','configure');
            if(($_POST['action']??'')==='save_mail'){saveCompanyMail($db,$company,$_POST);header('Location: correo.php?saved=1',true,303);return;}
            if(($_POST['action']??'')!=='test_mail')throw new InvalidArgumentException('Acción no válida.');
            try{$mail=companyMailer(loadCompanyMail($db,$company,true),$company);if(!$mail->smtpConnect())throw new RuntimeException('SMTP');$mail->smtpClose();$notice='Conexión y autenticación SMTP correctas. No se ha enviado ningún correo.';}catch(Throwable){$errors[]='No se pudo verificar la conexión. Revisa servidor, puerto, seguridad y credencial de aplicación.';}
        }
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===409?409:422);$errors[]=$e->getMessage();}
    catch(Throwable){http_response_code(503);$errors[]='No se pudo guardar la configuración de correo. Revisa el almacenamiento y la clave de cifrado del servidor.';}
    $settings=loadCompanyMail($db,$company);$q=$db->prepare('SELECT name FROM business_company WHERE id=?');$q->execute([$company]);$companyName=$q->fetchColumn();
    $modules=require __DIR__.'/config/modules.php';$module=$modules['ajustes'];$moduleKey='ajustes';$databaseStatus=getDatabaseStatus();ob_start();require dirname(__DIR__).'/html/settings/mail.php';echo permissionRenderHtml(ob_get_clean());
}

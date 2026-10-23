<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/settings-repository.php';
require_once __DIR__.'/settings-sections.php';
require_once __DIR__.'/currency-repository.php';

function settingsEscape(mixed $value): string
{
    return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function renderBusinessSettings(bool $company = false): void
{
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $_SESSION['settings_csrf'] ??= bin2hex(random_bytes(32));
    $page = ['area'=>$company?'company':'settings','available'=>true,'errors'=>[],
        'databaseStatus'=>getDatabaseStatus(),'csrf'=>$_SESSION['settings_csrf'],'record'=>[],
        'sections'=>businessSettingsSections(),'section'=>'general','view'=>$company?'list':'settings',
        'flash'=>$_SESSION['settings_flash']??null,'currencies'=>null];
    unset($_SESSION['settings_flash']);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method,$company?['GET','POST']:['GET'],true)) {
        header('Allow: '.($company?'GET, POST':'GET')); http_response_code(405);
        $page['available']=false; $page['errors'][]='El método solicitado no está disponible.';
    }
    try {
        $section = $_GET['section']??'general';
        if (!is_string($section) || !isset($page['sections'][$section]) || ($company && isset($_GET['new']))
            || (isset($_GET['id']) && (!$company || $_GET['id']!=='1'))) {
            http_response_code(404); $page['available']=false; $page['errors'][]='La página solicitada no existe.';
        }
        if ($page['available']) {
            $db = createDatabaseConnection(); $page['record']=loadBusinessCompany($db); $page['section']=$section;
            if ($company) {
                $page['view'] = isset($_GET['id'])?'form':'list';
                if ($method==='POST') {
                    if (!is_string($_POST['csrf']??null) || !hash_equals($page['csrf'],$_POST['csrf'])) {
                        http_response_code(403); $page['errors'][]='La sesión del formulario no es válida. Recarga la página.';
                    } elseif ($page['view']!=='form' || ($_POST['action']??null)!=='save_company') {
                        http_response_code(400); $page['errors'][]='La operación solicitada no es válida.';
                    } else {
                        try {
                            $version = filter_var($_POST['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                            if ($version===false) throw new InvalidArgumentException('Recarga la ficha de Empresa antes de guardar.');
                            saveBusinessCompany($db,$_POST,$version,companyLogoUpload($_FILES),($_POST['remove_logo']??null)==='1');
                            $_SESSION['settings_flash']='Datos de Empresa guardados.';
                            header('Location: empresa.php?id=1',true,303); return;
                        } catch (InvalidArgumentException $exception) {
                            if ($exception->getCode()===409) http_response_code(409);
                            $page['errors'][]=$exception->getMessage();
                            $page['record']=array_filter($_POST,'is_string')+$page['record'];
                        }
                    }
                }
            }
            try { $page['currencies']=accountingCurrencies($db); }
            catch (PDOException $exception) { error_log('Monedas de Ajustes: '.$exception->getMessage()); }
        }
    } catch (PDOException|RuntimeException $exception) {
        error_log('Ajustes: '.$exception->getMessage()); http_response_code(503); $page['available']=false;
        $page['errors'][]='No se pudo cargar Ajustes. Verifica la conexión y ejecuta php/install-settings.php.';
    }
    renderModulePage('ajustes',false,$page);
}

function renderBusinessCompanyLogo(): void
{
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); http_response_code(405); return; }
    try {
        $id=isset($_GET['id'])?filter_var($_GET['id'],FILTER_VALIDATE_INT):companyActiveId();
        $allowed=array_map('intval',array_column($GLOBALS['company_context']['companies']??[],'id'));
        if(!$id||!in_array($id,$allowed,true)){http_response_code(404);return;}
        $db = createDatabaseConnection(); $q=$db->prepare('SELECT logo_data,logo_mime FROM business_company WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        if (!$row || !$row['logo_data'] || !in_array($row['logo_mime'],['image/png','image/jpeg','image/webp'],true)) { http_response_code(404); return; }
        header('Content-Type: '.$row['logo_mime']); echo $row['logo_data'];
    } catch (PDOException $exception) { error_log('Logo de Empresa: '.$exception->getMessage()); http_response_code(503); }
}

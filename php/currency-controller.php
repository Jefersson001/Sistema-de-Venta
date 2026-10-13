<?php
declare(strict_types=1);
require_once __DIR__ . '/accounting-controller.php';
require_once __DIR__ . '/currency-catalog-repository.php';

function renderCurrencyConfiguration(string $section): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['accounting_csrf'] ??= bin2hex(random_bytes(32));
    $id = currencyCatalogId($_GET['id'] ?? null);
    $config = require __DIR__ . '/config/database.php';
    $page = ['area'=>'currencies','section'=>$section,'view'=>$section==='currency_settings'?'settings':($id||isset($_GET['new'])?'form':'list'),
        'available'=>false,'errors'=>[],'csrf'=>$_SESSION['accounting_csrf'],'record'=>[],'rows'=>[],
        'databaseStatus'=>['connected'=>false,'database'=>$config['database'],'error'=>null]];
    try {
        $db = createDatabaseConnection(); $page['available'] = true; $page['databaseStatus']['connected'] = true;
        $page['settings'] = accountingCurrencies($db);
        $page['currencies'] = $db->query('SELECT * FROM accounting_currencies ORDER BY active DESC,code,id')->fetchAll();
        if ($page['view'] === 'form') {
            $page['record'] = $id ? loadCurrencyRecord($db,$id) : ['code'=>'','name'=>'','active'=>1,'symbol'=>'','unit_label'=>'','subunit_label'=>'','symbol_position'=>'after','version'=>0,'rates'=>[]];
            if ((isset($_GET['id'])&&!$id) || !$page['record']) { http_response_code(404); $page['available']=false; $page['errors'][]='La moneda no existe.'; }
            $page['codeLocked'] = $id && $page['record'] ? currencyCodeIsUsed($db,$page['record']) : false;
            if ($page['record']) {
                $page['rateCount'] = count($page['record']['rates']);
                $page['ratePages'] = max(1,(int)ceil($page['rateCount']/25));
                $page['ratePage'] = min(currencyCatalogId($_GET['rate_page']??null)??1,$page['ratePages']);
                $page['record']['rates'] = array_slice($page['record']['rates'],($page['ratePage']-1)*25,25);
            }
        } elseif (isset($_GET['id']) && !$id) { http_response_code(404); $page['available']=false; $page['errors'][]='La moneda no existe.'; }
        if ($_SERVER['REQUEST_METHOD']==='POST' && $page['available']) {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($page['csrf'],$_POST['csrf'])) { http_response_code(403); $page['errors'][]='La sesión del formulario no es válida.'; }
            else try {
                if ($page['view']==='settings' && ($_POST['action'] ?? '')==='save_currency_settings') { saveBusinessCurrencies($db,$_POST); header('Location: configuracion.php?section=currencies',true,303); exit; }
                if ($page['view']!=='form' || ($_POST['action'] ?? '')!=='save_currency') throw new InvalidArgumentException('La operación no es válida.');
                $saved = saveCurrencyRecord($db,$_POST,$id,currencyCatalogId($_POST['version'] ?? null));
                header('Location: configuracion.php?section=currencies&id='.$saved,true,303); exit;
            } catch (InvalidArgumentException $error) { $page['errors'][]=$error->getMessage(); }
            catch (PDOException $error) { if (($error->errorInfo[1] ?? null)!==1062) throw $error; $page['errors'][]='Ya existe ese código de moneda o una tasa para la misma fecha y moneda base.'; }
            if ($page['errors']) {
                foreach (['code','name','symbol','unit_label','subunit_label','symbol_position'] as $field) if (is_string($_POST[$field] ?? null)) $page['record'][$field]=$_POST[$field];
                $page['record']['active']=($_POST['active'] ?? '')==='1';
                if (is_array($_POST['rates'] ?? null)) $page['record']['rates']=array_slice(array_filter($_POST['rates'],'is_array'),0,100);
            }
        }
        if ($page['view']==='list') {
            $page['search']=is_string($_GET['q'] ?? null)?mb_substr(trim($_GET['q']),0,120):'';
            $page['filter']=in_array($_GET['active'] ?? '',['active','inactive','all'],true)?$_GET['active']:'all';
            $page['rows']=array_values(array_filter($page['currencies'],static fn($c)=>($page['filter']==='all'||(bool)$c['active']===($page['filter']==='active')) && ($page['search']===''||mb_stripos($c['code'].' '.$c['name'].' '.$c['symbol'],$page['search'])!==false)));
            foreach ($page['rows'] as &$row) {
                $row['current_rate']=currencyRateForPair($db,$page['settings']['primary_code'],$row['code'],commerceToday());
            } unset($row);
        }
    } catch (PDOException $error) { error_log('Monedas: '.$error->getMessage()); http_response_code(503); $page['available']=false; $page['errors'][]='No se pudieron cargar las monedas. Verifica la instalación del catálogo de monedas.'; }
    renderModulePage('contabilidad',true,$page);
}

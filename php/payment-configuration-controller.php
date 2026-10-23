<?php
declare(strict_types=1);
require_once __DIR__.'/accounting-controller.php';require_once __DIR__.'/payments-repository.php';
function renderPaymentConfiguration(string $section): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION['accounting_csrf']??=bin2hex(random_bytes(32));$config=require __DIR__.'/config/database.php';
    $definitions=['accounts'=>['title'=>'Cajas y bancos','table'=>'accounting_payment_accounts'],'methods'=>['title'=>'Medios de pago','table'=>'accounting_payment_methods'],'concepts'=>['title'=>'Conceptos de ingresos y egresos','table'=>'accounting_cash_concepts'],'currencies'=>['title'=>'Monedas y tasa de cambio','table'=>'accounting_currency_settings']];$def=$definitions[$section];
    $id=$section==='currencies'?1:accountingId($_GET['id']??null);$page=['area'=>'payment_configuration','section'=>$section,'title'=>$def['title'],'view'=>$id||isset($_GET['new'])?'form':'list','csrf'=>$_SESSION['accounting_csrf'],'available'=>false,'record'=>[],'rows'=>[],'errors'=>[],'databaseStatus'=>['connected'=>false,'database'=>$config['database'],'error'=>null]];
    try{
        $db=createDatabaseConnection();$page['available']=true;$page['databaseStatus']['connected']=true;$page['settings']=accountingCurrencies($db);
        if(isset($_GET['id'])&&!accountingId($_GET['id'])){http_response_code(404);$page['available']=false;$page['errors'][]='El registro no existe.';}
        if($id){$q=$db->prepare('SELECT * FROM '.$def['table'].' WHERE id=?');$q->execute([$id]);$page['record']=$q->fetch()?:[];if(!$page['record']){http_response_code(404);$page['available']=false;$page['errors'][]='El registro no existe.';}}
        if($_SERVER['REQUEST_METHOD']==='POST'&&$page['available']){
            if(!is_string($_POST['csrf']??null)||!hash_equals($page['csrf'],$_POST['csrf'])){http_response_code(403);$page['errors'][]='La sesión del formulario no es válida.';}
            elseif($page['view']!=='form'||($_POST['action']??'')!=='save_configuration'){http_response_code(400);$page['errors'][]='La acción no es válida.';}
            else try{
                if($section==='currencies'){
                    $main=is_string($_POST['primary_code']??null)?strtoupper(trim($_POST['primary_code'])):'';$alt=is_string($_POST['alternate_code']??null)?strtoupper(trim($_POST['alternate_code'])):'';
                    if(!preg_match('/^[A-Z]{3}$/',$main)||!preg_match('/^[A-Z]{3}$/',$alt)||$main===$alt)throw new InvalidArgumentException('Indica dos códigos de moneda distintos de tres letras, por ejemplo USD y VES.');
                    $rate=accountingExchangeRate($_POST['exchange_rate']??null);$version=accountingId($_POST['version']??null);
                    $q=$db->prepare('UPDATE accounting_currency_settings SET primary_code=?,alternate_code=?,exchange_rate=?,version=version+1 WHERE id=1 AND version=?');$q->execute([$main,$alt,$rate,$version]);if(!$q->rowCount())throw new InvalidArgumentException('La configuración cambió en otra ventana. Recarga antes de continuar.');
                }else{
                    $name=is_string($_POST['name']??null)?trim($_POST['name']):'';if($name===''||mb_strlen($name)>120)throw new InvalidArgumentException('Indica un nombre de entre 1 y 120 caracteres.');$data=['name'=>$name];
                    if($section==='methods')$data['allow_change']=isset($_POST['allow_change'])?1:0;
                    if($section==='concepts'){
                        $direction=$_POST['direction']??'';if(!in_array($direction,['inbound','outbound','both'],true))throw new InvalidArgumentException('Selecciona el sentido del concepto.');
                        if($id){workflowBegin($db);$q=$db->prepare('SELECT direction FROM accounting_cash_concepts WHERE id=? FOR UPDATE');$q->execute([$id]);$currentDirection=$q->fetchColumn();
                            if($currentDirection!==$direction){$q=$db->prepare('SELECT COUNT(*) FROM accounting_treasury_entries WHERE concept_id=?');$q->execute([$id]);$used=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM accounting_payments WHERE concept_id=?');$q->execute([$id]);$used+=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM accounting_treasury_documents WHERE concept_id=?');$q->execute([$id]);if($used||$q->fetchColumn())throw new InvalidArgumentException('Un concepto utilizado conserva su sentido. Crea otro concepto.');}
                        }
                        $data+=['direction'=>$direction,'active'=>isset($_POST['active'])?1:0];
                    }
                    if($section==='accounts'){$type=$_POST['account_type']??'';$currency=$_POST['currency_code']??'';if(!in_array($type,['cash','bank'],true)||!is_string($currency)||($currency!==''&&!in_array($currency,array_merge(accountingCurrencyCodes($page['settings']),[$page['record']['currency_code']??null]),true)))throw new InvalidArgumentException('Selecciona el tipo de caja/banco y su moneda.');$data+=['account_type'=>$type,'currency_code'=>$currency?:null];
                        if($id){workflowBegin($db);$locked=treasuryLockAccounts($db,[$id])[$id];$q=$db->prepare('SELECT COUNT(*) FROM accounting_treasury_entries WHERE account_id=?');$q->execute([$id]);$used=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM accounting_payments WHERE account_id=?');$q->execute([$id]);if(($used||$q->fetchColumn())&&($locked['account_type']!==$type||$locked['currency_code']!==($currency?:null)))throw new InvalidArgumentException('Una caja o banco con movimientos conserva su tipo y moneda. Crea otro registro para una configuración distinta.');}}
                    $q=$id?$db->prepare('UPDATE '.$def['table'].' SET '.implode(',',array_map(static fn($k)=>$k.'=:'.$k,array_keys($data))).' WHERE id=:id'):$db->prepare('INSERT INTO '.$def['table'].'('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')');$q->execute($data+($id?['id'=>$id]:[]));
                    if($db->inTransaction())$db->commit();
                }
                header('Location: configuracion.php?section='.$section,true,303);exit;
            }catch(InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();$page['errors'][]=$e->getMessage();$page['record']=$_POST+$page['record'];}
            catch(PDOException $e){if($db->inTransaction())$db->rollBack();if(($e->errorInfo[1]??null)!==1062)throw $e;$page['errors'][]='Ya existe un registro con ese nombre.';}
        }
        if($page['view']==='list'){$page['search']=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,120):'';$q=$db->prepare('SELECT * FROM '.$def['table'].' WHERE name LIKE ? ORDER BY name,id');$q->execute(['%'.$page['search'].'%']);$page['rows']=$q->fetchAll();}
    }catch(PDOException $e){error_log('Configuración de pagos: '.$e->getMessage());http_response_code(503);$page['available']=false;$page['errors'][]='No se pudo cargar la configuración de pagos.';}
    renderModulePage('contabilidad',true,$page);
}

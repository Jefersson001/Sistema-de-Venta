<?php
declare(strict_types=1);
require_once __DIR__.'/module-page.php';
require_once __DIR__.'/direct-sales-operations.php';

function renderDirectManagement(string $section): void {
    authPrivacyHeaders();$db=createDatabaseConnection();
    $resource=match($section){'returns'=>'venta_directa.returns','closing'=>'venta_directa.closing',default=>'venta_directa.configuration'};
    $id=accessId($_GET['id']??null);if(isset($_GET['id'])&&!$id)authStop(404,'Registro no disponible.');
    $page=['section'=>$section,'csrf'=>$_SESSION['auth_csrf'],'key'=>bin2hex(random_bytes(32)),'errors'=>[],'input'=>[],'rows'=>[],'detail'=>null,'sale'=>null,'lines'=>[],'sellers'=>[],'seller_id'=>0,'summary'=>null,'closing_date'=>commerceToday(),'customers'=>[],'credit'=>null,'settings'=>accountingCurrencies($db)];
    try{
        if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))authStop(405,'Método no admitido.');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $page['input']=$_POST;$action=$_POST['action']??'';
            if($section==='returns'&&$action==='direct_return'){$saved=directRequestCustomerReturn($db,$_POST);header('Location: devoluciones.php?id='.$saved,true,303);return;}
            if($section==='returns'&&$id&&in_array($action,['direct_return_approve','direct_return_reject'],true)){directResolveCustomerReturn($db,$id,$_POST,$action==='direct_return_approve');header('Location: devoluciones.php?id='.$id,true,303);return;}
            if($section==='closing'&&$action==='direct_close'){$saved=directSubmitClosing($db,$_POST);header('Location: cierre.php?id='.$saved,true,303);return;}
            if($section==='closing'&&$id&&$action==='direct_close_validate'){directValidateClosing($db,$id);header('Location: cierre.php?id='.$id,true,303);return;}
            if($section==='credit'&&$action==='save_credit'){directSaveCredit($db,$_POST);header('Location: configuracion.php?contact_id='.(int)$_POST['contact_id'],true,303);return;}
            throw new InvalidArgumentException('Acción no válida.');
        }
    }catch(InvalidArgumentException $e){http_response_code(in_array($e->getCode(),[403,409],true)?$e->getCode():422);$page['errors'][]=$e->getMessage();}
    catch(Throwable $e){error_log('Gestión directa: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo completar la operación. Recarga y revisa su estado antes de reintentar.';}
    try{
        if($section==='returns'){
            $q=$db->query("SELECT r.*,i.name AS invoice_name,s.name AS seller_name FROM direct_sale_actions r JOIN direct_sale_checkouts d ON d.id=r.sale_id JOIN accounting_invoices i ON i.id=d.invoice_id JOIN contacts s ON s.id=r.seller_id WHERE r.action_kind='return' AND r.company_id=".companyActiveId().' AND '.permissionWhere($resource,'r').' ORDER BY r.id DESC LIMIT 100');$page['rows']=$q->fetchAll();
            if($id){$q=$db->prepare("SELECT r.*,i.name AS invoice_name,s.name AS seller_name FROM direct_sale_actions r JOIN direct_sale_checkouts d ON d.id=r.sale_id JOIN accounting_invoices i ON i.id=d.invoice_id JOIN contacts s ON s.id=r.seller_id WHERE r.id=? AND r.company_id=? AND r.action_kind='return' AND ".permissionWhere($resource,'r'));$q->execute([$id,companyActiveId()]);$page['detail']=$q->fetch();if(!$page['detail'])authStop(404,'Devolución no disponible.');$page['detail']['payload']=json_decode($page['detail']['payload_json'],true,8,JSON_THROW_ON_ERROR);$saleId=(int)$page['detail']['sale_id'];}
            else $saleId=accessId($page['input']['sale_id']??$_GET['sale_id']??null);
            if($saleId){$page['sale']=directSaleRecord($db,$saleId);$q=$db->prepare('SELECT id,product_name,quantity FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY position,id');$q->execute([$page['sale']['invoice_id']]);$page['lines']=$q->fetchAll();}
        }elseif($section==='closing'){
            $q=$db->query('SELECT r.*,s.name AS seller_name FROM direct_day_closings r JOIN contacts s ON s.id=r.seller_id WHERE r.company_id='.companyActiveId().' AND '.permissionWhere($resource,'r').' ORDER BY r.closing_date DESC,r.id DESC LIMIT 100');$page['rows']=$q->fetchAll();
            if($id){$q=$db->prepare('SELECT r.*,s.name AS seller_name FROM direct_day_closings r JOIN contacts s ON s.id=r.seller_id WHERE r.id=? AND r.company_id=? AND '.permissionWhere($resource,'r'));$q->execute([$id,companyActiveId()]);$page['detail']=$q->fetch();if(!$page['detail'])authStop(404,'Cierre no disponible.');$page['summary']=json_decode($page['detail']['snapshot_json'],true,32,JSON_THROW_ON_ERROR);$page['declared']=json_decode($page['detail']['declared_json'],true,8,JSON_THROW_ON_ERROR);}
            else{
                $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.company_id=? AND t.system_key='salesperson' AND ".(permissionScope($resource)==='own'?'c.id IN ('.implode(',',permissionContacts()?:[0]).')':'1=1').' ORDER BY c.name');$q->execute([companyActiveId()]);$page['sellers']=$q->fetchAll();
                $seller=accessId($page['input']['seller_id']??$_GET['seller_id']??null)?:permissionContact();if(!$seller&&permissionScope($resource)==='all')$seller=(int)($page['sellers'][0]['id']??0);
                if($seller&&!in_array($seller,array_map('intval',array_column($page['sellers'],'id')),true))authStop(403,'Vendedor no autorizado.');
                $page['seller_id']=$seller;$page['closing_date']=directDate($page['input']['closing_date']??$_GET['closing_date']??commerceToday());
                if($seller)$page['summary']=directDaySummary($db,$seller,$page['closing_date']);
            }
        }else{
            $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.company_id=? AND t.system_key='client' ORDER BY c.name");$q->execute([companyActiveId()]);$page['customers']=$q->fetchAll();$customer=accessId($page['input']['contact_id']??$_GET['contact_id']??null);
            if($customer){if(!in_array($customer,array_map('intval',array_column($page['customers'],'id')),true))authStop(403,'Cliente no disponible.');$q=$db->prepare('SELECT * FROM direct_customer_credit WHERE contact_id=?');$q->execute([$customer]);$page['credit']=$q->fetch()?:['contact_id'=>$customer,'credit_limit'=>null,'policy'=>'warn','version'=>0,'currency_code'=>$page['settings']['primary_code']];$page['exposure']=directCredit($db,$customer);}
        }
    }catch(InvalidArgumentException $e){http_response_code(422);$page['errors'][]=$e->getMessage();}
    catch(Throwable $e){error_log('Consulta directa: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudo cargar la información. Comprueba las migraciones y la configuración.';}
    $moduleKey='venta_directa';$homeHref='../../index.php';$isHomePage=false;$databaseStatus=getDatabaseStatus();ob_start();require dirname(__DIR__).'/html/direct-sales/management.php';echo permissionRenderHtml(ob_get_clean());
}

<?php
declare(strict_types=1);
require_once __DIR__.'/permissions.php';
function permissionAbsoluteUrl(string $href,string $current): string
{
    if($href===''||$href[0]==='#'||preg_match('~^[a-z]+:~i',$href))return $href;
    if($href[0]==='/')return $href;
    $parts=[];foreach(explode('/',dirname(parse_url($current,PHP_URL_PATH)).'/'.$href) as $part){if($part==='..')array_pop($parts);elseif($part!=='.'&&$part!=='')$parts[]=$part;}
    return '/'.implode('/',$parts);
}
function permissionFormActionAllowed(string $url,string $action): bool
{
    $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    if($path===authBasePath().'/logout.php')return true;
    if($path===authBasePath().'/empresas.php')return true;
    if($path===authBasePath().'/modules/ajustes/empresa.php') {
        if(in_array($action,['save_company_users','delete_company'],true))return (bool)permissionUser()['administrator'];
        if($action==='save_company')return permissionHas('ajustes.company','edit')&&(!isset($query['new'])||permissionUser()['administrator']);
    }
    $resource=permissionRoute($path??'', $query);if(!$resource)return false;
    $id=accessId($query['id']??null);
    if($resource==='ventas.commission_payments'){$resource='ventas.commissions';$id=accessId($query['invoice_id']??null);}
    $code=permissionAction($resource,$action,$id);
    if(str_contains($resource,'_invoices')&&$action==='cancel_payment')$code='unallocate';
    if($action==='apply_existing_payment')$code='register_payment';
    if(!$code||!permissionHas($resource,$code)||($id&&!permissionRecord($resource,$id,$code)))return false;
    if($code==='pay')return permissionHas('contabilidad.expense','create')&&permissionHas('contabilidad.expense','confirm');
    if($resource==='contabilidad.expense'&&$code==='reverse'&&$id){$q=createDatabaseConnection()->prepare('SELECT invoice_id FROM sales_commission_payments WHERE treasury_document_id=?');$q->execute([$id]);if($invoice=$q->fetchColumn())return permissionRecord('ventas.commissions',(int)$invoice,'reverse');}
    return true;
}
// Las decisiones de presentación utilizan la política que protege las solicitudes.
function permissionRenderHtml(string $html): string
{
    if(!permissionRuntime())return $html;
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
    $loaded=$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
    if(!$loaded)throw new RuntimeException('No se pudo preparar la vista autorizada.');
    $xpath=new DOMXPath($dom);$current=$_SERVER['REQUEST_URI'];
    if(isset($GLOBALS['permission_dashboard_metrics'])) {
        foreach(['income'=>permissionHas('dashboard.cash_flow')&&permissionHas('contabilidad.cash_flow'),'overview'=>permissionHas('dashboard.business'),
            'alerts'=>permissionHas('dashboard.inventory')&&permissionHas('inventario.stock')&&permissionHas('inventario.products'),
            'tasks'=>permissionHas('dashboard.tasks'),'commissions'=>permissionHas('dashboard.commissions')&&permissionHas('ventas.commissions')] as $section=>$allowed) {
            if(!$allowed)foreach(iterator_to_array($xpath->query('//*[@id="dashboard-tab-'.$section.'" or @id="dashboard-page-'.$section.'"]')) as $node)$node->parentNode?->removeChild($node);
        }
        foreach(iterator_to_array($xpath->query('//*[@data-dashboard-metric]')) as $node)if(!in_array($node->getAttribute('data-dashboard-metric'),$GLOBALS['permission_dashboard_metrics'],true)){$card=$xpath->query('ancestor::article[1]',$node)->item(0);$card?->parentNode?->removeChild($card);}
    }
    foreach(iterator_to_array($xpath->query('//a[@href]')) as $link) {
        $href=$link->getAttribute('href');if($href===''||$href[0]==='#')continue;
        if(!permissionUrlAllowed(permissionAbsoluteUrl($href,$current))) {
            if(str_contains($link->getAttribute('class'),'contact-name'))$link->parentNode?->replaceChild($dom->createTextNode($link->textContent),$link);
            else $link->parentNode?->removeChild($link);
        }
    }
    foreach(iterator_to_array($xpath->query('//img[@src]')) as $image)if(!permissionUrlAllowed(permissionAbsoluteUrl($image->getAttribute('src'),$current)))$image->parentNode?->removeChild($image);
    foreach(iterator_to_array($xpath->query('//form[translate(@method,"POST","post")="post"]')) as $form) {
        if(isset($GLOBALS['company_context'])) {
            $scopeInput=$dom->createElement('input');$scopeInput->setAttribute('type','hidden');
            $scopeInput->setAttribute('name','company_scope');$scopeInput->setAttribute('value',companyScopeToken());$form->appendChild($scopeInput);
        }
        $url=permissionAbsoluteUrl($form->getAttribute('action')?:$current,$current);
        $hidden=$xpath->query('.//input[@name="action"]',$form)->item(0);
        foreach(iterator_to_array($xpath->query('.//button[@name="action"]',$form)) as $button)if(!permissionFormActionAllowed($url,$button->getAttribute('value')))$button->parentNode?->removeChild($button);
        if($hidden&&!permissionFormActionAllowed($url,$hidden->getAttribute('value'))) {
            $form->setAttribute('data-permission-readonly','true');
            foreach($xpath->query('.//fieldset',$form) as $fieldset)$fieldset->setAttribute('disabled','disabled');
            foreach($xpath->query('.//input[not(@type="hidden")]|.//select|.//textarea',$form) as $input){$input->setAttribute('disabled','disabled');$input->setAttribute('aria-readonly','true');}
            foreach(iterator_to_array($xpath->query('.//button[not(@type) or @type="submit"]',$form)) as $button)$button->parentNode?->removeChild($button);
            foreach(iterator_to_array($xpath->query('.//*[@data-add-line or @data-remove-line or @data-operation-add or @data-operation-remove or @data-order-add or @data-order-remove]',$form)) as $button)$button->parentNode?->removeChild($button);
        }
    }
    foreach(iterator_to_array($xpath->query('//button[@name="action" and @form]')) as $button){
        $form=$dom->getElementById($button->getAttribute('form'));
        if($form&&!permissionFormActionAllowed(permissionAbsoluteUrl($form->getAttribute('action')?:$current,$current),$button->getAttribute('value')))$button->parentNode?->removeChild($button);
    }
    foreach(iterator_to_array($xpath->query('//details[contains(@class,"module-menu")]')) as $menu)if($xpath->query('.//a|.//form',$menu)->length===0)$menu->parentNode?->removeChild($menu);
    $resource=$GLOBALS['permission_resource']??'';
    if($resource==='ajustes.users'&&!permissionHas($resource,'password'))foreach(iterator_to_array($xpath->query('//*[@data-open-user-password or @data-user-password-dialog]')) as $node)$node->parentNode?->removeChild($node);
    if(str_contains($resource,'_invoices')&&!permissionHas($resource,'register_payment'))foreach(iterator_to_array($xpath->query('//*[@data-invoice-wizard-open="invoice-payment-wizard" or @id="invoice-payment-wizard"]')) as $node)$node->parentNode?->removeChild($node);
    if(str_contains($resource,'_invoices')&&!permissionHas($resource,'credit'))foreach(iterator_to_array($xpath->query('//*[@data-invoice-wizard-open="invoice-credit-wizard" or @id="invoice-credit-wizard"]')) as $node)$node->parentNode?->removeChild($node);
    if(str_contains($resource,'_invoices')&&!permissionHas($resource,'edit'))foreach($xpath->query('//*[@id="invoice-details"]//input[not(@type="hidden")]|//*[@id="invoice-details"]//textarea|//*[@form="invoice-details" and not(self::button)]') as $node)$node->setAttribute('disabled','disabled');
    if($resource==='ajustes.users'){
        if(!permissionHas($resource,'assign_groups'))foreach($xpath->query('//input[@name="groups[]"]') as $node)$node->setAttribute('disabled','disabled');
        if(isset($_GET['id'])&&!permissionHas($resource,'archive'))foreach($xpath->query('//input[@name="active"]') as $node)$node->setAttribute('disabled','disabled');
        if(!permissionHas('contactos.contacts'))foreach($xpath->query('//select[@name="contact_id"]') as $node)$node->setAttribute('disabled','disabled');
    }
    if($resource==='ajustes.groups'&&isset($_GET['id'])&&!permissionHas($resource,'archive'))foreach($xpath->query('//input[@name="active"]') as $node)$node->setAttribute('disabled','disabled');
    require_once __DIR__.'/company-view.php';companyDecorateView($dom,$xpath,$current);
    foreach(iterator_to_array($dom->childNodes) as $node)if($node->nodeType===XML_PI_NODE)$dom->removeChild($node);
    return "<!DOCTYPE html>\n".$dom->saveHTML($dom->documentElement);
}
function permissionPageData(string $module,?array $page): ?array
{
    if(!$page||!permissionRuntime())return $page;
    // Una ficha autorizada conserva las referencias que ya contiene, sin abrir otros registros.
    if(!empty($page['record']['id'])){
        $record=$page['record'];
        if(isset($page['catalogs']['products']))foreach($record['lines']??[] as $line){$ids=array_column($page['catalogs']['products'],'id');if(!in_array((int)$line['product_id'],array_map('intval',$ids),true))$page['catalogs']['products'][]=['id'=>(int)$line['product_id'],'name'=>$line['product_name'],'sale_price'=>$line['unit_price'],'price'=>$line['unit_price'],'currency_code'=>$record['currency_code']??'','sale_enabled'=>0,'purchase_enabled'=>0,'commission_type'=>'none','commission_value'=>'0'];}
        foreach(['customers'=>($record['customer_id']??$record['supplier_id']??null),'salespeople'=>($record['salesperson_id']??null)] as $catalog=>$id)if($id&&isset($page['catalogs'][$catalog])&&!in_array((int)$id,array_map('intval',array_column($page['catalogs'][$catalog],'id')),true)){
            $q=createDatabaseConnection()->prepare('SELECT id,name,active FROM contacts WHERE id=?');$q->execute([$id]);if($ref=$q->fetch())$page['catalogs'][$catalog][]=$ref+['salesperson_id'=>$record['salesperson_id']??null,'storage_label'=>$record['storage_label']??''];
        }
        if(isset($page['products']))foreach($record['lines']??[] as $line)if(!in_array((int)$line['product_id'],array_map('intval',array_column($page['products'],'id')),true))$page['products'][]=['id'=>(int)$line['product_id'],'name'=>$line['product_name']];
    }
    if(isset($page['catalogs']['products'])&&!permissionHas('inventario.products','view_cost'))foreach($page['catalogs']['products'] as &$product)unset($product['cost_price']);unset($product);
    if(isset($page['productIndicators']))foreach(['sold'=>'ventas.orders','purchased'=>'compras.orders','incoming'=>'inventario.movements','outgoing'=>'inventario.movements'] as $key=>$resource)if(!permissionHas($resource))$page['productIndicators'][$key]=null;
    if(isset($page['contacts'])&&($page['area']??'')!=='')$page['contacts']=permissionFilterContacts($page['contacts']);
    return $page;
}

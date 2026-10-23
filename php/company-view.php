<?php
declare(strict_types=1);

function companyViewTable(string $url): ?string
{
    $path=parse_url($url,PHP_URL_PATH)??'';parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    if($table=companyRouteTable($path,$query))return $table;
    $resource=permissionRoute($path,$query);
    if($resource&&($table=companyResourceTable($resource)))return $table;
    if(basename($path)==='configuracion.php')return match($query['section']??''){
        'categories'=>'inventory_categories','warehouses'=>'inventory_warehouses','locations'=>'inventory_locations',
        'payment_terms'=>'accounting_payment_terms','accounts'=>'accounting_payment_accounts',
        'methods'=>'accounting_payment_methods','concepts'=>'accounting_cash_concepts','currencies'=>'accounting_currencies',
        'taxes'=>'business_taxes',default=>null,
    };
    return null;
}

function companyDecorateView(DOMDocument $dom,DOMXPath $xpath,string $current): void
{
    if(!isset($GLOBALS['company_context']))return;
    $db=createDatabaseConnection();$names=[];
    foreach($GLOBALS['company_context']['companies'] as $c)$names[(int)$c['id']]=$c['name']?:'Empresa '.$c['id'];
    $id=accessId($_GET['id']??null);$table=companyViewTable($current);
    if($id&&$table) {
        $q=$db->prepare('SELECT r.company_id,r.created_at,r.updated_at,c.name AS creator,u.name AS editor FROM '.$table.' r LEFT JOIN auth_users c ON c.id=r.created_by LEFT JOIN auth_users u ON u.id=r.updated_by WHERE r.id=?');$q->execute([$id]);$audit=$q->fetch();
        if($audit) {
            $box=$dom->createElement('aside');$box->setAttribute('class','notice company-record-info');$box->setAttribute('aria-label','Control del registro');
            $text='Empresa: '.($names[(int)$audit['company_id']]??'').' · Creado: '.($audit['created_at']??'Sin fecha histórica').' · Por: '.($audit['creator']??'Sin usuario histórico').' · Modificado: '.($audit['updated_at']??'Sin fecha histórica').' · Por: '.($audit['editor']??'Sin usuario histórico');
            $box->appendChild($dom->createTextNode($text));
            $main=$xpath->query('//main//div[contains(@class,"page-content")]')->item(0);$main?->insertBefore($box,$main->firstChild);
            if((int)$audit['company_id']!==companyActiveId()) {
                $box->appendChild($dom->createTextNode(' · Activa esta empresa en el selector para modificar el registro.'));
                foreach($xpath->query('//main//form[translate(@method,"POST","post")="post"]//input|//main//form[translate(@method,"POST","post")="post"]//select|//main//form[translate(@method,"POST","post")="post"]//textarea|//main//form[translate(@method,"POST","post")="post"]//button') as $control)$control->setAttribute('disabled','disabled');
            }
        }
    }
    $report=in_array(basename(parse_url($current,PHP_URL_PATH)??''),['informes.php','ingresos-egresos.php'],true);
    if($id||isset($_GET['new'])||(!$table&&!$report))return;
    $meta=$dom->createElement('meta');$meta->setAttribute('name','nexo-company-live-list');$meta->setAttribute('content','30');
    if($report)$meta->setAttribute('data-report','true');
    $dom->getElementsByTagName('head')->item(0)?->appendChild($meta);
    foreach(iterator_to_array($xpath->query('//main//table[contains(@class,"contact-table")]')) as $grid) {
        $rows=[];$ids=[];
        foreach($xpath->query('./tbody/tr',$grid) as $row) {
            foreach($xpath->query('.//a[@href]',$row) as $link) {
                $url=permissionAbsoluteUrl($link->getAttribute('href'),$current);$rowTable=companyViewTable($url);
                if(!$rowTable||($table&&$rowTable!==$table))continue;
                parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$key=accessId($query['id']??null);
                if($key){$ids[$rowTable][]=$key;$rows[]=[$row,$rowTable,$key];break;}
            }
        }
        if(!$ids)continue;
        $owners=[];
        foreach($ids as $rowTable=>$keys){$q=$db->prepare('SELECT id,company_id FROM '.$rowTable.' WHERE id IN('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);$owners[$rowTable]=$q->fetchAll(PDO::FETCH_KEY_PAIR);}
        $header=$xpath->query('./thead/tr',$grid)->item(0);
        if($header){$th=$dom->createElement('th','Empresa');$header->appendChild($th);}
        foreach($rows as [$row,$rowTable,$key]){$cell=$dom->createElement('td');$cell->appendChild($dom->createTextNode($names[(int)($owners[$rowTable][$key]??0)]??'—'));$row->appendChild($cell);}
    }
}

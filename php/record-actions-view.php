<?php
declare(strict_types=1);
require_once __DIR__.'/record-actions.php';

function recordDecorateView(DOMDocument $dom,DOMXPath $xpath,string $current,array $m,DOMElement $options,DOMElement $toolbar): void
{
    parse_str(parse_url($current,PHP_URL_QUERY)??'',$filters);$id=accessId($filters['id']??null);$new=isset($filters['new'])&&!$id;
    $base=authBasePath().'/acciones.php?';
    if($id&&in_array($m['save'],['sale_orders','purchase_orders','operations','invoices'],true)){
        $link=$dom->createElement('a','Imprimir / Guardar PDF');$link->setAttribute('href',$base.http_build_query(['source'=>$current,'action'=>'print']));$options->insertBefore($link,$options->firstChild);
    }
    foreach(['duplicate'=>'Duplicar','delete'=>'Eliminar'] as $action=>$label){
        if($action==='duplicate'&&!$id)continue;
        $supported=recordActionSupported($m,$action);$allowed=permissionHas($m['resource'],recordActionPermission($m,$action));
        if($supported&&$allowed&&!$new){
            $link=$dom->createElement('a',$label);$link->setAttribute('href',$base.http_build_query(['source'=>$current,'action'=>$action]));
            if($action==='delete'){$link->setAttribute('class','record-delete-link');if(!$id)$link->setAttribute('data-record-delete-selection','');}
        }else{
            $link=$dom->createElement('span',$label);$link->setAttribute('aria-disabled','true');$link->setAttribute('title',$new?'Guarda el registro antes de usar esta acción.':(!$supported?'Este registro se conserva. Usa Archivar, Cancelar o el procedimiento específico de su módulo.':'Tu grupo no permite esta acción.'));
        }
        $options->appendChild($link);
    }
    if($new)return;
    if($id){
        $db=createDatabaseConnection();try{$row=recordActionRow($db,$m,$id);}catch(InvalidArgumentException){return;}$queryFilters=$filters;unset($queryFilters['id']);
        [$where,$args]=transferDomain($db,$m,$queryFilters);$where=$where?'('.implode(' AND ',$where).')':'1=1';
        $byId=in_array($m['save'],['sale_orders','purchase_orders','operations','invoices','payments','treasury'],true);$column=$byId?'id':(isset($row['name'])?'name':(isset($row['code'])?'code':'id'));$desc=$byId;
        $nav=$dom->createElement('nav');$nav->setAttribute('class','record-pagination');$nav->setAttribute('aria-label','Navegar entre registros');
        foreach(['previous'=>'← Anterior','next'=>'Siguiente →'] as $direction=>$label){
            $greater=($direction==='next')!==$desc;$op=$greater?'>':'<';$sort=$greater?'ASC':'DESC';
            if($column==='id'){$condition='r.id'.$op.'?';$values=[$id];}
            else{$condition='(r.'.$column.$op.'? OR (r.'.$column.'=? AND r.id'.$op.'?))';$values=[$row[$column],$row[$column],$id];}
            $q=$db->prepare('SELECT r.id FROM '.$m['table'].' r WHERE '.$where.' AND '.$condition.' ORDER BY r.'.$column.' '.$sort.($column==='id'?'':',r.id '.$sort).' LIMIT 1');$q->execute(array_merge($args,$values));$neighbor=$q->fetchColumn();
            $link=$dom->createElement($neighbor?'a':'span',$label);$link->setAttribute('class','contact-button');
            if($neighbor)$link->setAttribute('href',recordFormUrl($current,(int)$neighbor));else $link->setAttribute('aria-disabled','true');$nav->appendChild($link);
        }
        $toolbar->appendChild($nav);
    }elseif(recordActionSupported($m,'delete')&&permissionHas($m['resource'],recordActionPermission($m,'delete'))){
        $table=$xpath->query('//main//table[contains(concat(" ",normalize-space(@class)," ")," contact-table ")]')->item(0);
        if($table){
            $header=$xpath->query('.//thead/tr',$table)->item(0);
            if($header){$cell=$dom->createElement('th');$cell->setAttribute('class','record-selection');$all=$dom->createElement('input');$all->setAttribute('type','checkbox');$all->setAttribute('data-record-select-all','');$all->setAttribute('aria-label','Seleccionar registros de esta página');$cell->appendChild($all);$header->insertBefore($cell,$header->firstChild);}
            foreach($xpath->query('.//tbody/tr',$table) as $tr){
                $recordId=null;
                foreach($xpath->query('.//a[@href]',$tr) as $link){$url=permissionAbsoluteUrl($link->getAttribute('href'),$current);parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);if(accessId($q['id']??null)&&($candidate=transferRouteModel($url))&&$candidate['key']===$m['key']){$recordId=accessId($q['id']);break;}}
                if(!$recordId){foreach($xpath->query('.//td[@colspan]',$tr) as $cell)$cell->setAttribute('colspan',(string)((int)$cell->getAttribute('colspan')+1));continue;}
                $cell=$dom->createElement('td');$cell->setAttribute('class','record-selection');$checkbox=$dom->createElement('input');$checkbox->setAttribute('type','checkbox');$checkbox->setAttribute('value',(string)$recordId);$checkbox->setAttribute('data-record-select','');$checkbox->setAttribute('aria-label','Seleccionar registro '.$recordId);$cell->appendChild($checkbox);$tr->insertBefore($cell,$tr->firstChild);
            }
        }
        foreach(iterator_to_array($xpath->query('//main//a[contains(concat(" ",normalize-space(@class)," ")," restaurant-list-row ")]')) as $row){
            parse_str(parse_url($row->getAttribute('href'),PHP_URL_QUERY)??'',$q);$recordId=accessId($q['id']??null);if(!$recordId)continue;
            $wrapper=$dom->createElement('div');$wrapper->setAttribute('class','record-selectable-card');$checkbox=$dom->createElement('input');$checkbox->setAttribute('type','checkbox');$checkbox->setAttribute('data-record-select','');$checkbox->setAttribute('value',(string)$recordId);$checkbox->setAttribute('aria-label','Seleccionar registro '.$recordId);$row->parentNode->insertBefore($wrapper,$row);$wrapper->appendChild($checkbox);$wrapper->appendChild($row);
        }
    }
}

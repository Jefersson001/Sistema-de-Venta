<?php
declare(strict_types=1);
require_once __DIR__.'/transfer-service.php';
require_once __DIR__.'/record-actions-view.php';

function transferDecorateView(DOMDocument $dom,DOMXPath $xpath,string $current): void
{
    $model=transferRouteModel($current);if(!$model||!permissionHas($model['resource']))return;
    $toolbar=$xpath->query('//main//*[contains(concat(" ",normalize-space(@class)," ")," contact-toolbar ") or contains(concat(" ",normalize-space(@class)," ")," restaurant-heading ")]')->item(0);
    if(!$toolbar)return;
    $menu=$dom->createElement('details');$menu->setAttribute('class','transfer-menu');$menu->setAttribute('data-model-options',$model['key']);
    $summary=$dom->createElement('summary','⚙ Opciones');$summary->setAttribute('class','contact-button');$summary->setAttribute('aria-label','Opciones de '.$model['label']);$menu->appendChild($summary);
    $options=$dom->createElement('div');$options->setAttribute('class','transfer-menu-options');
    foreach(['template'=>'Descargar plantilla','import'=>'Importar','export'=>'Exportar'] as $action=>$label) {
        if($action!=='export'&&!transferCanImport($model)){
            $disabled=$dom->createElement('span',$label);$disabled->setAttribute('aria-disabled','true');$disabled->setAttribute('title',$model['save']==='readonly'?'Datos calculados desde operaciones de origen.':'Tu usuario no tiene permiso para importar este modelo.');$options->appendChild($disabled);continue;
        }
        parse_str(parse_url($current,PHP_URL_QUERY)??'',$query);
        if($action==='export'&&isset($query['new'])&&!isset($query['id'])){$disabled=$dom->createElement('span',$label);$disabled->setAttribute('aria-disabled','true');$disabled->setAttribute('title','Guarda el registro antes de exportar esta ficha.');$options->appendChild($disabled);continue;}
        $link=$dom->createElement('a',$label);$link->setAttribute('href',authBasePath().'/datos.php?'.http_build_query(['model'=>$model['key'],'action'=>$action,'source'=>$current]));$options->appendChild($link);
    }
    recordDecorateView($dom,$xpath,$current,$model,$options,$toolbar);
    $menu->appendChild($options);
    $pager=$xpath->query('./nav[contains(concat(" ",normalize-space(@class)," ")," record-pagination ")]',$toolbar)->item(0);
    $context=$xpath->query('//main//div[contains(concat(" ",normalize-space(@class)," ")," module-context ")]')->item(0);
    if($pager||$context){
        $controls=$dom->createElement('div');$controls->setAttribute('class','record-toolbar-controls');
        if($pager)$controls->appendChild($pager);$controls->appendChild($menu);
        if($context){$context->appendChild($controls);}else{$toolbar->appendChild($controls);}
    }else{$toolbar->appendChild($menu);}
    if(!$context)return; // Embedded contact wizards keep their existing compact form.
    parse_str(parse_url($current,PHP_URL_QUERY)??'',$query);
    if(isset($query['id'])||isset($query['new'])){
        foreach(iterator_to_array($xpath->query('./div/a[contains(concat(" ",normalize-space(@class)," ")," overview-link ")]',$toolbar)) as $back)$back->parentNode->removeChild($back);
        if(in_array($model['save'],['sale_orders','purchase_orders','operations','payments','treasury'],true)){
            $title=$xpath->query('.//h1',$toolbar)->item(0);
            $sheet=$xpath->query('//main//*[contains(concat(" ",normalize-space(@class)," ")," contact-sheet ") and not(ancestor::dialog)]')->item(0);
            if($title&&$sheet){
                $heading=$dom->createElement('header');$heading->setAttribute('class','record-heading');$heading->appendChild($title);
                foreach(iterator_to_array($xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," quotation-status ") or contains(concat(" ",normalize-space(@class)," ")," document-state-groups ")]',$toolbar)) as $states)$heading->appendChild($states);
                $sheet->insertBefore($heading,$sheet->firstChild);
                if(in_array($model['save'],['sale_orders','purchase_orders'],true)&&$title->hasAttribute('id')){
                    $section=$xpath->query('.//section[contains(concat(" ",normalize-space(@class)," ")," workspace-form-section ")]',$sheet)->item(0);
                    if($section){$caption=$xpath->query('./h2[contains(concat(" ",normalize-space(@class)," ")," workspace-section-title ")]',$section)->item(0);if($caption){$section->setAttribute('aria-labelledby',$title->getAttribute('id'));$section->removeChild($caption);}}
                }
            }
        }
        foreach(iterator_to_array($xpath->query('./div[not(*) and not(normalize-space())]',$toolbar)) as $empty)$toolbar->removeChild($empty);
        if(!$xpath->query('./*',$toolbar)->length)$toolbar->parentNode->removeChild($toolbar);
    }
}

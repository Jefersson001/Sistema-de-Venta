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
    $menu->appendChild($options);$toolbar->appendChild($menu);
}

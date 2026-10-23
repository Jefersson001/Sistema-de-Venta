<?php
declare(strict_types=1);

/** All ordinary GET filters share the same automatic behavior, including future lists. */
function liveFiltersDecorateView(DOMDocument $dom,DOMXPath $xpath): void
{
    $main=$xpath->query('//main')->item(0);if(!$main||$xpath->query('.//*[@data-live-report]',$main)->length)return;
    $count=0;
    foreach($xpath->query('.//form[contains(concat(" ",normalize-space(@class)," ")," contact-filters ")]',$main) as $form){
        if(strtolower($form->getAttribute('method')?:'get')!=='get'||$form->hasAttribute('data-cash-flow-filter')||$form->hasAttribute('data-dashboard-filter'))continue;
        $form->setAttribute('data-live-filters','');$count++;
        foreach(iterator_to_array($xpath->query('.//button',$form)) as $button)if(in_array(mb_strtolower(trim($button->textContent)),['buscar','filtrar','consultar','aplicar','aplicar filtros'],true))$button->parentNode->removeChild($button);
        foreach($xpath->query('.//*[@data-auto-submit]',$form) as $field)$field->removeAttribute('data-auto-submit');
    }
    if(!$count)return;
    $main->setAttribute('data-live-module','');
    $status=$dom->createElement('p');$status->setAttribute('class','section-subtitle');$status->setAttribute('data-live-filter-status','');$status->setAttribute('role','status');$status->setAttribute('aria-live','polite');
    $first=$xpath->query('.//form[@data-live-filters]',$main)->item(0);$first->appendChild($status);
    $script=$dom->createElement('script');$script->setAttribute('src',authBasePath().'/js/live-report.js?v='.filemtime(dirname(__DIR__).'/js/live-report.js'));$script->setAttribute('defer','defer');$dom->getElementsByTagName('body')->item(0)->appendChild($script);
}

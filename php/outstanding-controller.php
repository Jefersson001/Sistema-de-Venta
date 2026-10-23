<?php
declare(strict_types=1);
require_once __DIR__.'/module-page.php';require_once __DIR__.'/connection.php';require_once __DIR__.'/outstanding-repository.php';
function renderOutstandingReport(): void
{
    if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'El informe solo admite consultas.');}
    $page=['area'=>'outstanding','sections'=>['customer'=>null,'supplier'=>null],'filters'=>['q'=>'','due'=>'all','contact_id'=>null],'errors'=>[],'as_of'=>commerceToday(),'databaseStatus'=>getDatabaseStatus()];
    try{
        $page['filters']=outstandingFilters($_GET);$db=createDatabaseConnection();$page['sections']=loadOutstandingReport($db,$page['filters']);
        foreach($page['sections'] as $type=>&$section){if($section===null)continue;
            $requested=accessId($_GET[$type.'_page']??null)??1;$section['pages']=max(1,(int)ceil($section['group_count']/20));$section['page']=min($requested,$section['pages']);$section['groups']=array_slice($section['groups'],($section['page']-1)*20,20);
        }unset($section);
    }catch(InvalidArgumentException $e){http_response_code(422);$page['errors'][]=$e->getMessage();}
    catch(Throwable $e){error_log('Cuentas pendientes: '.$e->getMessage());http_response_code(503);$page['errors'][]='No se pudieron consultar los saldos pendientes.';}
    renderModulePage('contabilidad',false,$page);
}

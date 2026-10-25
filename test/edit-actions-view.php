<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/permission-view.php';
require_once dirname(__DIR__).'/php/edit-actions-view.php';
function editCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$model=['table'=>'records','save'=>'records','resource'=>'demo','key'=>'records'];
$resolve=static fn(string $url): ?array => str_contains($url,'report.php')?array_replace($model,['save'=>'readonly']):$model;
$allowed=static fn(array $model,int $id): bool => $id!==2;
function editFixture(string $body,string $url,callable $resolve,callable $allowed): array {
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8"><main>'.$body.'</main>');libxml_clear_errors();
    $xp=new DOMXPath($dom);editActionsDecorateView($dom,$xp,$url,$resolve,$allowed);
    $once=$dom->saveHTML();editActionsDecorateView($dom,$xp,$url,$resolve,$allowed);
    editCheck($dom->saveHTML()===$once,'La decoración no es repetible.');return [$dom,$xp];
}
[, $xp]=editFixture('<table class="contact-table"><thead><tr><th>Nombre</th><th>Acción</th><th>Empresa</th></tr></thead><tbody><tr><td><a class="contact-name" href="index.php?id=1">Uno</a></td><td><a href="index.php?id=1">Editar Uno</a><button type="button">Archivar</button></td><td>Empresa A</td></tr></tbody></table>','/modules/demo/index.php',$resolve,$allowed);
editCheck($xp->query('//thead/tr/th[last()][text()="Acciones"]')->length===1,'Acciones no es la última columna.');
editCheck($xp->query('//tbody/tr/td[last()]/a[text()="Editar"]')->length===1,'No se normalizó Editar.');
editCheck($xp->query('//tbody/tr/td[last()]/button[text()="Archivar"]')->length===1,'Se perdió otra acción.');
editCheck($xp->query('//tbody//a[starts-with(text(),"Editar")]')->length===1,'Se duplicó Editar.');
[, $xp]=editFixture('<table class="contact-table"><thead><tr><th>Nombre</th><th>Empresa</th></tr></thead><tbody><tr><td><a class="contact-name" href="index.php?id=1">Uno</a></td><td>A</td></tr><tr><td><a class="contact-name" href="index.php?id=2">Dos</a></td><td>B</td></tr></tbody></table>','/modules/demo/index.php',$resolve,$allowed);
editCheck($xp->query('//thead/tr/th')->length===3&&$xp->query('//tbody/tr[1]/td[last()]/a[text()="Editar"]')->length===1,'No se agregó la acción faltante.');
editCheck($xp->query('//tbody/tr[2]/td[last()]/a')->length===0,'Se ofreció Editar sin permiso.');
[, $xp]=editFixture('<table class="contact-table"><thead><tr><th>Nombre</th></tr></thead><tbody><tr><td colspan="2">Sin registros</td></tr></tbody></table>','/modules/demo/index.php',$resolve,$allowed);
editCheck($xp->query('//thead/tr/th[last()][text()="Acciones"]')->length===1&&$xp->query('//tbody/tr/td[@colspan="2"]')->length===1,'La lista vacía perdió su estructura.');
[, $xp]=editFixture('<form method="post"><table class="contact-table"><thead><tr><th>Producto</th><th>Acción</th></tr></thead><tbody><tr><td><input name="quantity"></td><td><button>Quitar</button></td></tr></tbody></table></form>','/modules/demo/index.php?new=1',$resolve,$allowed);
editCheck($xp->query('//*[@data-record-edit-actions]')->length===0,'Se modificó una tabla de líneas editables.');
[, $xp]=editFixture('<table class="contact-table"><thead><tr><th>Referencia</th></tr></thead><tbody><tr><td><a class="contact-name" href="index.php?id=1">Uno</a></td></tr></tbody></table>','/modules/demo/report.php',$resolve,$allowed);
editCheck($xp->query('//*[@data-record-edit-actions]')->length===0,'Se agregó Editar a un informe calculado.');
[, $xp]=editFixture('<form><table class="contact-table"><thead><tr><th>Variante</th><th>Acción</th></tr></thead><tbody><tr><td><a class="contact-name" href="index.php?id=1">Variante</a></td><td><a href="index.php?id=1">Editar variante</a></td></tr></tbody></table></form>','/modules/demo/index.php?id=3',$resolve,$allowed);
editCheck($xp->query('//tbody/tr/td[last()]/a[text()="Editar"]')->length===1,'No se normalizó la tabla de variantes dentro de una ficha.');
echo "PASS: última columna, empresa, acciones existentes, sin duplicados, permisos, listas vacías, variantes e informes.\n";

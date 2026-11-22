<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
function renderPublicDocuments(): void {
    header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);exit;}
    $token=$_GET['token']??'';$share=false;$db=null;
    try{if(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)){$db=createDatabaseConnection(true);$q=$db->prepare('SELECT id,company_id,company_name,expires_at FROM document_share_links WHERE token_hash=? AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP');$q->execute([hash('sha256',$token)]);$share=$q->fetch();}}
    catch(Throwable){http_response_code(503);echo 'Los documentos no están disponibles. Intenta nuevamente más tarde.';return;}
    if(!$share){http_response_code(404);echo 'El enlace no está disponible, venció o fue revocado.';return;}
    if(isset($_GET['file'])){
        $file=filter_var($_GET['file'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$file){http_response_code(404);return;}
        $q=$db->prepare('SELECT filename,pdf_data FROM document_share_files WHERE id=? AND share_id=? AND company_id=?');$q->execute([$file,$share['id'],$share['company_id']]);$row=$q->fetch();if(!$row){http_response_code(404);echo 'Documento no disponible.';return;}
        $ascii=preg_replace('/[^A-Za-z0-9._-]/','-',$row['filename']);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($row['filename']));header('Content-Length: '.strlen($row['pdf_data']));if($_SERVER['REQUEST_METHOD']!=='HEAD')echo $row['pdf_data'];return;
    }
    $q=$db->prepare('SELECT id,label FROM document_share_files WHERE share_id=? AND company_id=? ORDER BY id');$q->execute([$share['id'],$share['company_id']]);$files=$q->fetchAll();header('Content-Type: text/html; charset=UTF-8');if($_SERVER['REQUEST_METHOD']==='HEAD')return;require dirname(__DIR__).'/html/public-documents.php';
}

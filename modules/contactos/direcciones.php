<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/php/auth-bootstrap.php';
require_once dirname(__DIR__,2).'/php/contacts-repository.php';
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');authStop(405,'Esta consulta solo admite lectura.',true);}
if(!is_string($_GET['scope']??null)||!hash_equals(companyScopeToken(),$_GET['scope']))authStop(409,'La selección de empresas cambió. Recarga el documento.',true);
$id=contactId($_GET['id']??null);if(!$id)authStop(404,'El contacto no existe.',true);
$db=createDatabaseConnection();$q=$db->prepare('SELECT id FROM contacts WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())authStop(404,'El contacto no está disponible.',true);
header('Content-Type: application/json; charset=utf-8');echo json_encode(['addresses'=>loadContactAddresses($db,$id),'scope'=>companyScopeToken()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);

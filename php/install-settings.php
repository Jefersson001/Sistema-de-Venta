<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/connection.php';
require_once __DIR__.'/settings-schema.php';
require_once __DIR__.'/access-schema.php';
require_once __DIR__.'/auth-schema.php';
try { $db=createDatabaseConnection();installBusinessSettings($db);installAccessGroups($db);installAuthentication($db);echo "Ajustes, Empresa, grupos y autenticación instalados.\n"; }
catch (Throwable $exception) { fwrite(STDERR,$exception->getMessage()."\n"); exit(1); }

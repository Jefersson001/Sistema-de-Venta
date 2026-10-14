<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/connection.php';
require_once __DIR__.'/settings-schema.php';
try { installBusinessSettings(createDatabaseConnection()); echo "Ajustes y ficha de Empresa instalados.\n"; }
catch (Throwable $exception) { fwrite(STDERR,$exception->getMessage()."\n"); exit(1); }

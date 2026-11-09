<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/upload-storage.php';
try {
    if ($argc > 2) throw new RuntimeException('Uso: php prepare-upload-storage.php [php-fpm.conf]');
    prepareUploadStorage($argv[1] ?? '/assets/php-fpm.conf');
    echo "Almacenamiento privado de adjuntos preparado para PHP-FPM.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Adjuntos: '.$error->getMessage().PHP_EOL);
    exit(1);
}

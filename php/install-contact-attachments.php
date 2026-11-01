<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/connection.php';
require_once __DIR__.'/contact-attachments-schema.php';
try {
    installContactAttachments(createDatabaseConnection(true));
    echo "Fotos y documentos de contactos instalados.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }

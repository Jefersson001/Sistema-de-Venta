<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/commerce-schema.php';
try { installCommerceDocuments(createDatabaseConnection()); echo "Compras, recepciones y facturas relacionadas instaladas.\n"; }
catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }

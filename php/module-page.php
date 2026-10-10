<?php

declare(strict_types=1);

require_once __DIR__ . '/database-status.php';

function renderModulePage(string $moduleKey, bool $isConfiguration = false, ?array $contactPage = null): void
{
    $modules = require __DIR__ . '/config/modules.php';
    if (! isset($modules[$moduleKey])) {
        http_response_code(404);
        echo 'Módulo no encontrado.';

        return;
    }

    $module = $modules[$moduleKey];
    $databaseStatus = $contactPage['databaseStatus'] ?? getDatabaseStatus();

    require dirname(__DIR__) . '/html/module-placeholder.php';
}

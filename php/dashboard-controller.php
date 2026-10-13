<?php
declare(strict_types=1);

require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/dashboard-repository.php';

function renderDashboard(): void
{
    $databaseStatus = getDatabaseStatus();
    $dashboard = ['available' => false];
    if ($databaseStatus['connected']) {
        try {
            $dashboard = loadDashboard(createDatabaseConnection());
        } catch (PDOException $exception) {
            error_log('Dashboard: ' . $exception->getMessage());
        }
    }

    renderModulePage('dashboard', false, [
        'area' => 'dashboard',
        'databaseStatus' => $databaseStatus,
        'dashboard' => $dashboard,
    ]);
}

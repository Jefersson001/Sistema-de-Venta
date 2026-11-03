<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$originalUrl = getenv('DATABASE_URL');
$originalName = getenv('NEXO_DATABASE_NAME');
$load = static fn(): array => require dirname(__DIR__).'/php/config/database.php';
$check = static function (bool $condition): void {
    if (!$condition) throw new RuntimeException('Falló la configuración de base de datos.');
};
try {
    putenv('DATABASE_URL');
    putenv('NEXO_DATABASE_NAME');
    $local = $load();
    $check($local['host'] === '127.0.0.1' && $local['database'] === 'data' && $local['create_database']);

    putenv('DATABASE_URL=mysql://app:p%40ss%3Aword%2F%23@database-internal:3307/production');
    $remote = $load();
    $check($remote['host'] === 'database-internal' && $remote['port'] === 3307
        && $remote['username'] === 'app' && $remote['password'] === 'p@ss:word/#'
        && $remote['database'] === 'production' && !$remote['create_database']);

    putenv('NEXO_DATABASE_NAME=nexo_isolated_test');
    $check($load()['database'] === 'nexo_isolated_test');
    putenv('NEXO_DATABASE_NAME');

    putenv('DATABASE_URL=mariadb://app:secret@database-internal/production');
    $check($load()['port'] === 3306);
    foreach ([
        'postgres://app:secret@database/production',
        'mysql://app:secret@database/',
        'mysql://app:secret@database/invalid-name',
        'mysql://app:secret@database;dbname=other/production',
        'mysql://app:secret@database/production?charset=latin1',
        'mysql://app:secret@database:99999/production',
    ] as $invalid) {
        putenv('DATABASE_URL='.$invalid);
        $rejected = false;
        try { $load(); } catch (RuntimeException $exception) {
            $rejected = true;
            $check(!str_contains($exception->getMessage(), 'secret'));
        }
        $check($rejected);
    }
    echo "PASS: configuración local, URL remota, contraseña codificada, puerto, aislamiento y rechazo de URLs inválidas sin mostrar credenciales.\n";
} finally {
    putenv($originalUrl === false ? 'DATABASE_URL' : 'DATABASE_URL='.$originalUrl);
    putenv($originalName === false ? 'NEXO_DATABASE_NAME' : 'NEXO_DATABASE_NAME='.$originalName);
}

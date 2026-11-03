<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/auth.php';
$original = getenv('NEXO_BASE_PATH');
function basePathCheck(bool $condition): void {
    if (!$condition) throw new RuntimeException('Falló la configuración de ruta base.');
}
try {
    putenv('NEXO_BASE_PATH');
    basePathCheck(authBasePath() === '/project');
    foreach (['/' => '', '/project/' => '/project', '/ventas' => '/ventas'] as $configured => $expected) {
        putenv('NEXO_BASE_PATH='.$configured);
        basePathCheck(authBasePath() === $expected);
        basePathCheck(authSafeNext('https://example.com/') === $expected.'/index.php');
        basePathCheck(authSafeNext('//example.com/') === $expected.'/index.php');
        basePathCheck(authSafeNext($expected.'/modules/ventas/index.php') === $expected.'/modules/ventas/index.php');
        basePathCheck(authSafeNext($expected.'/login.php') === $expected.'/index.php');
    }
    foreach (['//example.com', '/path/../other', 'https://example.com', 'project'] as $invalid) {
        putenv('NEXO_BASE_PATH='.$invalid);
        $rejected = false;
        try { authBasePath(); } catch (RuntimeException) { $rejected = true; }
        basePathCheck($rejected);
    }
    echo "PASS: ruta local, dominio raíz, subcarpeta, redirecciones y rutas inválidas.\n";
} finally {
    putenv($original === false ? 'NEXO_BASE_PATH' : 'NEXO_BASE_PATH='.$original);
}

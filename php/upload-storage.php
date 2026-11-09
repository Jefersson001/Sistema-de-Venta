<?php
declare(strict_types=1);

/** Shared by the web process and the container startup; no database connection. */
function privateUploadDirectory(string $kind): string
{
    $environment = match ($kind) {
        'contacts' => 'NEXO_CONTACT_UPLOAD_DIR',
        'payment-proofs' => 'NEXO_PAYMENT_PROOF_DIR',
        default => throw new InvalidArgumentException('Almacenamiento desconocido.'),
    };
    return getenv($environment) ?: __DIR__.'/uploads/'.$kind;
}

function ensurePrivateUploadDirectory(string $directory): void
{
    // A missing/read-only volume must produce a controlled error, not HTML warnings.
    if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) || !is_writable($directory)) {
        error_log('Almacenamiento de adjuntos no disponible: '.$directory);
        throw new RuntimeException('No se pudo preparar el almacenamiento de archivos. Revisa los permisos del servidor.');
    }
}

function uploadStorageWorker(string $config): array
{
    $settings = @parse_ini_file($config, true, INI_SCANNER_RAW);
    $pool = is_array($settings) ? ($settings['www'] ?? []) : [];
    $user = trim((string)($pool['user'] ?? ''));
    $group = trim((string)($pool['group'] ?? ''));
    foreach ([$user, $group] as $identity) {
        if ($identity !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/D', $identity)) {
            throw new RuntimeException('Usuario o grupo de PHP-FPM no válido. Revisa el pool [www].');
        }
    }
    if ($user === '') throw new RuntimeException('No se pudo determinar el usuario de PHP-FPM en '.$config.'.');
    return [ctype_digit($user) ? (int)$user : $user, $group === '' ? null : (ctype_digit($group) ? (int)$group : $group)];
}

function prepareUploadStorage(string $config): void
{
    [$user, $group] = uploadStorageWorker($config);
    foreach (['contacts', 'payment-proofs'] as $kind) {
        $directory = privateUploadDirectory($kind);
        // Ancestors created by root must be traversable by the FPM worker.
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado: '.$directory);
        }
        $resolved = realpath($directory);
        $app = dirname(__DIR__);
        if ($resolved === false || is_link($directory) || $resolved === DIRECTORY_SEPARATOR
            || $resolved === $app || str_starts_with($app, $resolved.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('La ruta de adjuntos debe ser una carpeta dedicada: '.$directory);
        }
        $defaultParent = __DIR__.'/uploads';
        if (dirname($directory) === $defaultParent && !@chmod($defaultParent, 0755)) {
            throw new RuntimeException('No se pudo habilitar el acceso a la carpeta de adjuntos.');
        }
        if (!@chown($directory, $user) || ($group !== null && !@chgrp($directory, $group)) || !@chmod($directory, 0700)) {
            throw new RuntimeException('No se pudieron preparar los permisos de '.$directory.' para PHP-FPM. Revisa el usuario y el volumen.');
        }
    }
}

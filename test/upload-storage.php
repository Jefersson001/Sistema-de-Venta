<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/upload-storage.php';
function storageExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function storageReject(callable $work, string $message): void {
    try { $work(); } catch (RuntimeException $error) {
        storageExpect(str_contains($error->getMessage(), $message), $error->getMessage()); return;
    }
    throw new RuntimeException('Se aceptó un almacenamiento inválido.');
}
$root = sys_get_temp_dir().'/vent-as-storage-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$original = ['NEXO_CONTACT_UPLOAD_DIR'=>getenv('NEXO_CONTACT_UPLOAD_DIR'), 'NEXO_PAYMENT_PROOF_DIR'=>getenv('NEXO_PAYMENT_PROOF_DIR')];
$log = ini_get('error_log'); ini_set('error_log', $root.'/errors.log');
try {
    foreach (array_keys($original) as $key) putenv($key);
    storageExpect(str_replace('\\', '/', privateUploadDirectory('contacts')) === str_replace('\\', '/', dirname(__DIR__)).'/php/uploads/contacts', 'Cambió la ruta existente.');
    storageExpect(str_replace('\\', '/', privateUploadDirectory('payment-proofs')) === str_replace('\\', '/', dirname(__DIR__)).'/php/uploads/payment-proofs', 'Cambió la ruta de comprobantes.');
    putenv('NEXO_CONTACT_UPLOAD_DIR='.$root.'/contacts');
    putenv('NEXO_PAYMENT_PROOF_DIR='.$root.'/proofs');
    storageExpect(privateUploadDirectory('contacts') === $root.'/contacts', 'No se respetó el volumen configurado.');
    ensurePrivateUploadDirectory($root.'/contacts');
    file_put_contents($root.'/contacts/existing.bin', 'contenido anterior');
    ensurePrivateUploadDirectory($root.'/contacts');
    storageExpect(file_get_contents($root.'/contacts/existing.bin') === 'contenido anterior', 'Se modificó un archivo existente.');
    file_put_contents($root.'/blocked', 'no es carpeta');
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (error_reporting() & $severity) $warnings[] = $message;
        return true;
    });
    try { storageReject(static fn()=>ensurePrivateUploadDirectory($root.'/blocked/subdir'), 'permisos del servidor'); }
    finally { restore_error_handler(); }
    storageExpect(!$warnings, 'Se filtró un warning de mkdir a la respuesta.');
    file_put_contents($root.'/fpm.conf', "[www]\nlisten=127.0.0.1:9000\nuser=nobody\n");
    storageExpect(uploadStorageWorker($root.'/fpm.conf') === ['nobody', null], 'No se detectó el usuario Nixpacks.');
    file_put_contents($root.'/fpm.conf', "[www]\nuser=1001\ngroup=1002\n");
    storageExpect(uploadStorageWorker($root.'/fpm.conf') === [1001, 1002], 'No se detectaron UID/GID.');
    storageReject(static fn()=>uploadStorageWorker($root.'/missing.conf'), 'usuario de PHP-FPM');
    file_put_contents($root.'/fpm.conf', "[www]\nuser=\"\${USER}\"\n");
    storageReject(static fn()=>uploadStorageWorker($root.'/fpm.conf'), 'no válido');
    file_put_contents($root.'/fpm.conf', "[www]\nuser=nobody\n");
    putenv('NEXO_CONTACT_UPLOAD_DIR='.dirname(__DIR__));
    storageReject(static fn()=>prepareUploadStorage($root.'/fpm.conf'), 'carpeta dedicada');
    putenv('NEXO_CONTACT_UPLOAD_DIR='.$root.'/contacts');
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid')) {
        file_put_contents($root.'/fpm.conf', "[www]\nuser=".posix_geteuid()."\ngroup=".posix_getegid()."\n");
        prepareUploadStorage($root.'/fpm.conf'); prepareUploadStorage($root.'/fpm.conf');
        clearstatcache();
        storageExpect(fileowner($root.'/contacts') === posix_geteuid(), 'Propietario incorrecto.');
        storageExpect((fileperms($root.'/contacts') & 0777) === 0700, 'Permisos demasiado abiertos.');
        storageExpect(file_get_contents($root.'/contacts/existing.bin') === 'contenido anterior', 'El arranque alteró archivos.');
    }
    echo "PASS: rutas compartidas, creación repetida conserva archivos, error controlado, usuario FPM y rechazo de rutas no dedicadas.\n";
} finally {
    foreach ($original as $key=>$value) putenv($value === false ? $key : $key.'='.$value);
    ini_set('error_log', $log);
    foreach (glob($root.'/contacts/*') ?: [] as $file) unlink($file);
    foreach (['contacts', 'proofs'] as $folder) if (is_dir($root.'/'.$folder)) rmdir($root.'/'.$folder);
    foreach (glob($root.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($root);
}

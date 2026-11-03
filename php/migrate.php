<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/migration-runner.php';

try {
    $options=getopt('',['status','make:','help']);
    if (isset($options['help'])) {
        echo "php php/migrate.php                  Aplica migraciones pendientes.\n";
        echo "php php/migrate.php --status         Consulta el estado sin modificar tablas.\n";
        echo "php php/migrate.php --make=nombre    Crea una plantilla para un cambio nuevo.\n";
        exit;
    }
    if (isset($options['make'])) {
        echo 'Migración creada: '.makeVersionedMigration((string)$options['make']).PHP_EOL;
        echo 'Completa el archivo y súbelo junto con el código del módulo.'.PHP_EOL;
        exit;
    }
    $db=migrationConnection();
    migrationAssertApplicationReady($db);
    if (isset($options['status'])) {
        $files=migrationFiles();$records=migrationRecords($db);migrationCheckHistory($files,$records);
        foreach ($files as $version=>$file) echo ($records[$version]['status']??'pending').' '.$version.PHP_EOL;
    } else {
        runVersionedMigrations($db,static fn(string $message)=>print($message.PHP_EOL));
    }
} catch (Throwable $exception) {
    $message=$exception->getMessage();
    foreach (['DATABASE_URL','MIGRATION_DATABASE_URL'] as $key) {
        $url=getenv($key);
        if ($url!==false && $url!=='') {
            $message=str_replace($url,'[URL privada]',$message);
            $password=parse_url($url,PHP_URL_PASS);
            if (is_string($password) && $password!=='') $message=str_replace([$password,rawurldecode($password)],'[contraseña privada]',$message);
        }
    }
    fwrite(STDERR,'Migración detenida: '.$message.PHP_EOL);exit(1);
}

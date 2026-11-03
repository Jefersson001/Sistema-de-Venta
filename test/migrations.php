<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/migration-runner.php';

function migrationExpect(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function migrationReject(callable $work,string $message): void {
    $rejected=false;
    try { $work(); } catch (Throwable $e) {
        if (!str_contains($e->getMessage(),$message)) throw $e;
        $rejected=true;
    }
    migrationExpect($rejected,'No se rechazó el caso esperado: '.$message);
}

$originalUrl=getenv('DATABASE_URL');$originalMigration=getenv('MIGRATION_DATABASE_URL');$originalName=getenv('NEXO_DATABASE_NAME');
try {
    putenv('NEXO_DATABASE_NAME');
    putenv('DATABASE_URL=mysql://application:app_secret@database-internal:3306/app');
    putenv('MIGRATION_DATABASE_URL=mysql://installer:admin%40secret@database-internal:3306/app');
    $config=migrationConfiguration();
    migrationExpect($config['username']==='installer' && $config['password']==='admin@secret','No se seleccionaron las credenciales de migración.');
    foreach (['mysql://installer:secret@other-host:3306/app','mysql://installer:secret@database-internal:3307/app','mysql://installer:secret@database-internal:3306/other_db'] as $url) {
        putenv('MIGRATION_DATABASE_URL='.$url);
        migrationReject(static fn()=>migrationConfiguration(),'mismo host, puerto y base');
    }
    putenv('MIGRATION_DATABASE_URL');
    migrationExpect(migrationConfiguration()['username']==='application','No funciona la conexión de respaldo.');
} finally {
    putenv($originalUrl===false?'DATABASE_URL':'DATABASE_URL='.$originalUrl);
    putenv($originalMigration===false?'MIGRATION_DATABASE_URL':'MIGRATION_DATABASE_URL='.$originalMigration);
    putenv($originalName===false?'NEXO_DATABASE_NAME':'NEXO_DATABASE_NAME='.$originalName);
}

$db=createDatabaseConnection(true);$other=null;
$name='nexo_migration_test_'.bin2hex(random_bytes(8));
if (!preg_match('/^nexo_migration_test_[a-f0-9]{16}$/D',$name)) throw new RuntimeException('Base temporal inválida.');
$directory=sys_get_temp_dir().'/vent-as-migrations-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$first='20261103010000_create_catalog';$second='20261103010100_add_column';
$third='20261103010200_retry_ddl';$fourth='20261103010300_after_retry';
$initial=<<<'PHP'
<?php
return static function(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS migration_items(id INT PRIMARY KEY,note VARCHAR(100))');
    $db->exec("INSERT IGNORE INTO migration_items VALUES(1,'Original QA')");
};
PHP;
file_put_contents($directory.'/'.$first.'.php',$initial);
file_put_contents($directory.'/'.$second.'.php', <<<'PHP'
<?php
return static function(PDO $db): void {
    if (!$db->query("SHOW COLUMNS FROM migration_items LIKE 'label'")->fetch()) $db->exec('ALTER TABLE migration_items ADD label VARCHAR(100) NULL');
};
PHP);
try {
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->exec('USE `'.$name.'`');
    migrationReject(static fn()=>migrationAssertApplicationReady($db),'La base no está instalada');
    migrationExpect($db->query('SHOW TABLES')->fetchAll()===[],'La consulta inicial modificó una base vacía.');
    migrationExpect(migrationRecords($db)===[],'El estado inicial no está vacío.');
    migrationExpect($db->query('SHOW TABLES')->fetchAll()===[],'Consultar estado creó tablas.');
    migrationExpect(runVersionedMigrations($db,null,$directory)===2,'No se aplicaron las dos migraciones iniciales.');
    $db->exec("UPDATE migration_items SET note='Personalizado QA' WHERE id=1");
    migrationExpect(runVersionedMigrations($db,null,$directory)===0,'Se repitieron migraciones aplicadas.');
    migrationExpect($db->query('SELECT note FROM migration_items WHERE id=1')->fetchColumn()==='Personalizado QA','Se sobrescribieron datos.');
    file_put_contents($directory.'/'.$first.'.php',str_replace(["\r\n","\n"],["\n","\r\n"],$initial));
    migrationExpect(runVersionedMigrations($db,null,$directory)===0,'Los saltos de línea Windows/Linux alteraron el historial.');
    file_put_contents($directory.'/'.$first.'.php',$initial);
    file_put_contents($directory.'/'.$third.'.php', <<<'PHP'
<?php
return static function(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS migration_partial(id INT PRIMARY KEY)');
    $db->exec('INSERT IGNORE INTO migration_partial VALUES(1)');
    if (!$db->query("SHOW TABLES LIKE 'release_retry'")->fetch()) throw new RuntimeException('Fallo después de DDL QA');
};
PHP);
    file_put_contents($directory.'/'.$fourth.'.php', <<<'PHP'
<?php
return static function(PDO $db): void { $db->exec('CREATE TABLE IF NOT EXISTS after_retry(id INT PRIMARY KEY)'); };
PHP);
    file_put_contents($directory.'/'.$first.'.php',$initial."\n// Cambio de un archivo aplicado\n");
    migrationReject(static fn()=>runVersionedMigrations($db,null,$directory),'ya aplicada');
    migrationExpect(!$db->query("SHOW TABLES LIKE 'migration_partial'")->fetch(),'Se ejecutaron cambios antes de validar el historial.');
    file_put_contents($directory.'/'.$first.'.php',$initial);
    migrationReject(static fn()=>runVersionedMigrations($db,null,$directory),'Fallo después de DDL QA');
    $records=migrationRecords($db);
    migrationExpect($records[$third]['status']==='failed' && !isset($records[$fourth]),'No se registró el fallo o se continuó después de él.');
    migrationExpect((int)$db->query('SELECT COUNT(*) FROM migration_partial')->fetchColumn()===1,'No se conservó la ejecución parcial de DDL.');
    $db->exec('CREATE TABLE release_retry(id INT PRIMARY KEY)');
    migrationExpect(runVersionedMigrations($db,null,$directory)===2,'No se reanudó desde la migración fallida.');
    migrationExpect((int)$db->query('SELECT COUNT(*) FROM migration_partial')->fetchColumn()===1,'El reintento duplicó datos.');
    $db->prepare("UPDATE application_migrations SET status='running' WHERE version=?")->execute([$third]);
    migrationExpect(runVersionedMigrations($db,null,$directory)===1,'No se recuperó una ejecución interrumpida.');
    rename($directory.'/'.$first.'.php',$directory.'/'.$first.'.bak');
    migrationReject(static fn()=>runVersionedMigrations($db,null,$directory),'Falta el archivo');
    rename($directory.'/'.$first.'.bak',$directory.'/'.$first.'.php');

    $lock='vent_as_install_'.substr(hash('sha256',$name),0,40);
    $query=$db->prepare('SELECT GET_LOCK(?,0)');$query->execute([$lock]);
    $other=createDatabaseConnection(true);$other->exec('USE `'.$name.'`');
    try { migrationReject(static fn()=>runVersionedMigrations($other,null,$directory,0),'en curso'); }
    finally { $query=$db->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$lock]); }

    mkdir($directory.'/generated',0700);
    $generated=makeVersionedMigration('crear_tabla_qa',$directory.'/generated');
    migrationExpect(is_file($generated) && is_callable(require $generated),'No se creó una plantilla válida.');
    migrationReject(static fn()=>makeVersionedMigration('../escape',$directory.'/generated'),'Usa un nombre');
    unlink($generated);rmdir($directory.'/generated');
    echo "PASS: conexión separada, destino validado, base sin instalar, estado de consulta, aplicación una vez, conservación de datos, historial, fallo tras DDL, reanudación, bloqueo concurrente y generación de migraciones.\n";
} finally {
    $other=null;
    $db->exec('USE information_schema');$db->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    foreach (glob($directory.'/*') as $file) if (is_file($file)) unlink($file);
    if (is_dir($directory.'/generated')) {
        foreach (glob($directory.'/generated/*') as $file) if (is_file($file)) unlink($file);
        rmdir($directory.'/generated');
    }
    rmdir($directory);
}

<?php
declare(strict_types=1);
require_once __DIR__.'/database-url.php';
require_once __DIR__.'/company-schema.php';
require_once __DIR__.'/workflow-schema.php';

function migrationConfiguration(): array
{
    $runtime=require __DIR__.'/config/database.php';
    $url=getenv('MIGRATION_DATABASE_URL');
    if ($url===false || trim($url)==='') return $runtime;
    $migration=databaseUrlConfiguration($url,'MIGRATION_DATABASE_URL');
    if ($name=getenv('NEXO_DATABASE_NAME')) $migration['database']=$name;
    foreach (['host','port','database'] as $field) {
        if (strtolower((string)$migration[$field])!==strtolower((string)$runtime[$field])) {
            throw new RuntimeException('MIGRATION_DATABASE_URL debe apuntar al mismo host, puerto y base que DATABASE_URL.');
        }
    }
    return $migration;
}

function migrationConnection(): PDO
{
    $config=migrationConfiguration();
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$config['database'])) throw new RuntimeException('Nombre de base de datos inválido.');
    $db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config['host'],$config['port'],$config['database']),
        $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='-04:00'");
    return $db;
}

/** Check the existing installation before writing a migration ledger into a blank database. */
function migrationAssertApplicationReady(PDO $db): void
{
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['auth_state','auth_users','business_company','company_schema_state','company_schema_tables'] as $table) {
        if (!in_array($table,$tables,true)) throw new RuntimeException('La base no está instalada: falta '.$table.'. Ejecuta primero php/install-fresh.php.');
    }
    if ((int)$db->query('SELECT version FROM company_schema_state WHERE id=1')->fetchColumn()!==1) {
        throw new RuntimeException('El esquema multiempresa está incompleto. Completa la instalación antes de migrar.');
    }
    if (in_array('application_installation',$tables,true)
        && !$db->query('SELECT completed_at FROM application_installation WHERE id=1')->fetchColumn()) {
        throw new RuntimeException('La instalación inicial está incompleta. Reanuda php/install-fresh.php.');
    }
}

function migrationFiles(?string $directory=null): array
{
    $directory??=__DIR__.'/migrations/versioned';$files=[];
    if (!is_dir($directory)) throw new RuntimeException('No existe el directorio de migraciones versionadas.');
    $paths=glob($directory.'/*.php');
    if ($paths===false) throw new RuntimeException('No se pudo listar el directorio de migraciones.');
    foreach ($paths as $path) {
        $name=basename($path,'.php');
        if (!preg_match('/^[0-9]{14}_[a-z][a-z0-9_]{0,99}$/D',$name)) throw new RuntimeException('Nombre de migración inválido: '.basename($path));
        $contents=file_get_contents($path);
        if ($contents===false) throw new RuntimeException('No se pudo leer la migración '.$name);
        // Windows development and Linux deployments must share the same history.
        $checksum=hash('sha256',str_replace("\r\n","\n",$contents));
        $files[$name]=['path'=>$path,'checksum'=>$checksum];
    }
    ksort($files,SORT_STRING);
    return $files;
}

function migrationRecords(PDO $db): array
{
    if (!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='application_migrations'")->fetchColumn()) return [];
    $records=[];
    foreach ($db->query('SELECT * FROM application_migrations ORDER BY version')->fetchAll() as $record) $records[$record['version']]=$record;
    return $records;
}

/** Applied files are immutable. Detect edits or missing history before executing any pending work. */
function migrationCheckHistory(array $files,array $records): void
{
    foreach ($records as $version=>$record) {
        if (!isset($files[$version])) throw new RuntimeException('Falta el archivo de una migración registrada: '.$version.'. Conserva el historial en el repositorio.');
        if ($record['status']==='applied' && !hash_equals($record['checksum'],$files[$version]['checksum'])) {
            throw new RuntimeException('Se modificó una migración ya aplicada: '.$version.'. Restaura el archivo y crea una migración nueva.');
        }
    }
}

/** MySQL DDL commits implicitly. Every migration must tolerate retry after partial execution. */
function runVersionedMigrations(PDO $db,?callable $progress=null,?string $directory=null,int $lockTimeout=60): int
{
    if ($db->inTransaction()) throw new RuntimeException('Las migraciones requieren una conexión sin transacción activa.');
    if ($lockTimeout<0 || $lockTimeout>300) throw new InvalidArgumentException('Tiempo de bloqueo inválido.');
    $files=migrationFiles($directory);
    // Share the lock with the initial installer, including parallel Dokploy replicas.
    $lock='vent_as_install_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
    $query=$db->prepare('SELECT GET_LOCK(?,?)');$query->execute([$lock,$lockTimeout]);
    if ((int)$query->fetchColumn()!==1) throw new RuntimeException('Otra instalación o migración está en curso. No se aplicaron cambios.');
    try {
        $records=migrationRecords($db);migrationCheckHistory($files,$records);
        $db->exec("CREATE TABLE IF NOT EXISTS application_migrations(
            version VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            checksum CHAR(64) CHARACTER SET ascii NOT NULL,
            status ENUM('running','failed','applied') NOT NULL,
            started_at DATETIME NOT NULL,completed_at DATETIME NULL,error_message TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $count=0;
        foreach ($files as $version=>$file) {
            if (($records[$version]['status']??null)==='applied') continue;
            if ($progress) $progress('Aplicando: '.$version);
            $query=$db->prepare("INSERT INTO application_migrations(version,checksum,status,started_at) VALUES(?,?,'running',CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),status='running',started_at=CURRENT_TIMESTAMP,completed_at=NULL,error_message=NULL");
            $query->execute([$version,$file['checksum']]);
            try {
                $migration=require $file['path'];
                if (!is_callable($migration)) throw new RuntimeException('El archivo debe devolver una función que reciba PDO.');
                $migration($db);
                if ($db->inTransaction()) throw new RuntimeException('La migración dejó una transacción abierta; debe confirmarla antes de terminar.');
                $query=$db->prepare("UPDATE application_migrations SET status='applied',completed_at=CURRENT_TIMESTAMP,error_message=NULL WHERE version=?");$query->execute([$version]);
                $count++;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                $query=$db->prepare("UPDATE application_migrations SET status='failed',error_message=? WHERE version=?");
                $query->execute([mb_substr($exception->getMessage(),0,4000),$version]);
                throw new RuntimeException('Falló '.$version.': '.$exception->getMessage().'. No se ejecutaron las migraciones siguientes.',0,$exception);
            }
        }
        if ($progress) $progress($count===0?'Base de datos al día; no hay migraciones pendientes.':'Migraciones aplicadas: '.$count.'.');
        return $count;
    } finally {
        $query=$db->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$lock]);
    }
}

function makeVersionedMigration(string $name,?string $directory=null): string
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$name)) throw new InvalidArgumentException('Usa un nombre como crear_tabla_reservas, con letras minúsculas, números y guiones bajos.');
    $directory??=__DIR__.'/migrations/versioned';
    if (!is_dir($directory)) throw new RuntimeException('No existe el directorio de migraciones.');
    $path=$directory.'/'.gmdate('YmdHis').'_'.$name.'.php';
    $template=<<<'PHP'
<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Implementar cambios idempotentes: MySQL puede haber confirmado DDL antes de un fallo.
    // Para nuevas tablas de empresa, terminar con registerCompanyTable($db, 'nombre_tabla').
    // Una vez aplicada, conservar esta migración sin editarla; agregar otra para cambios nuevos.
    throw new LogicException('Completa esta migración antes de desplegar.');
};

PHP;
    $handle=@fopen($path,'x');
    if ($handle===false) throw new RuntimeException('No se pudo crear el archivo; puede existir una migración con ese nombre en este segundo.');
    try {
        if (fwrite($handle,$template)!==strlen($template)) throw new RuntimeException('No se pudo guardar la plantilla completa.');
    } finally { fclose($handle); }
    return $path;
}

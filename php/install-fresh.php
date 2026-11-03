<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/fresh-installation.php';

/** Keep the administrative password out of arguments, logs and the application's configuration. */
function freshReadPassword(): string
{
    if (!stream_isatty(STDIN) || PHP_OS_FAMILY==='Windows') {
        throw new RuntimeException('Ejecuta --db-user en la terminal interactiva Linux del contenedor de la aplicación.');
    }
    $mode=trim((string)shell_exec('stty -g 2>/dev/null'));
    if ($mode==='' || !preg_match('/^[a-zA-Z0-9:;= -]+$/D',$mode)) throw new RuntimeException('No se pudo proteger la entrada de contraseña.');
    fwrite(STDOUT,'Contraseña MySQL del usuario de instalación (no se muestra): ');
    exec('stty -echo',$unused,$status);
    if ($status!==0) throw new RuntimeException('No se pudo ocultar la contraseña.');
    try {
        $password=fgets(STDIN);
        if ($password===false) throw new RuntimeException('No se recibió la contraseña.');
        return rtrim($password,"\r\n");
    } finally {
        exec('stty '.escapeshellarg($mode));fwrite(STDOUT,PHP_EOL);
    }
}

try {
    $options=getopt('',['db-user:','url:','discard-partial-import','help']);
    if (isset($options['help'])) {
        echo "Instalación nueva: php php/install-fresh.php --url=https://tu-dominio --db-user=root\n";
        echo "Solo para la importación fallida con una única tabla access_groups: agregar --discard-partial-import.\n";
        echo "No modifica DATABASE_URL, no borra instalaciones existentes y permite reanudar etapas incompletas.\n";
        exit;
    }
    $url=rtrim((string)($options['url']??''),'/');$parts=parse_url($url);
    if (!$parts || !in_array($parts['scheme']??'', ['http','https'],true) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || ($parts['path']??'')!==authBasePath()) {
        throw new RuntimeException('Indica --url con la URL pública completa de la aplicación, acorde a NEXO_BASE_PATH.');
    }
    $config=require __DIR__.'/config/database.php';
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$config['database'])) throw new RuntimeException('Nombre de base inválido.');
    $username=isset($options['db-user'])?(string)$options['db-user']:$config['username'];
    $password=isset($options['db-user'])?freshReadPassword():$config['password'];
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config['host'],$config['port'],$config['database']);
    $db=new PDO($dsn,$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    unset($password);
    $db->exec("SET time_zone='-04:00'");
    echo 'Destino: '.$config['database'].' en '.$config['host'].PHP_EOL;
    installFreshApplication($db,static fn(string $message)=>print($message.PHP_EOL),isset($options['discard-partial-import']));
    if ($db->query('SELECT 1 FROM auth_users LIMIT 1')->fetchColumn()) {
        echo 'Ya existen usuarios. Accede en '.$url.'/login.php'.PHP_EOL;
    } else {
        $token=prepareFirstAdministrator($db);
        echo "Abre este enlace privado en los próximos 30 minutos para crear el administrador:\n";
        echo $url.'/activar.php?token='.$token.PHP_EOL;
    }
} catch (Throwable $exception) {
    // PDO errors can reveal account names but never include the connection URL/password.
    fwrite(STDERR,'Instalación detenida: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

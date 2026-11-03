<?php
declare(strict_types=1);

/** Parse connection secrets without ever including them in validation errors. */
function databaseUrlConfiguration(string $url, string $label = 'DATABASE_URL'): array
{
    $parts=parse_url(trim($url));
    if ($parts===false || !in_array(strtolower($parts['scheme']??''),['mysql','mariadb'],true)
        || empty($parts['host']) || !isset($parts['user'])
        || !preg_match('/^[a-zA-Z0-9_.:\[\]-]+$/D',$parts['host'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException($label.' debe ser una URL mysql://usuario:clave@host:puerto/base sin parámetros adicionales.');
    }
    $database=rawurldecode(ltrim($parts['path']??'','/'));
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$database)) {
        throw new RuntimeException($label.' debe indicar un nombre de base de datos válido.');
    }
    return [
        'host'=>$parts['host'],'port'=>$parts['port']??3306,'database'=>$database,
        'username'=>rawurldecode($parts['user']),'password'=>rawurldecode($parts['pass']??''),
        'charset'=>'utf8mb4','create_database'=>false,
    ];
}

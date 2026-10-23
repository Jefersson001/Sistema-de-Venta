<?php
declare(strict_types=1);

function loadBusinessCompany(PDO $db, ?int $companyId = null): array
{
    $companyId ??= function_exists('companyActiveId') ? companyActiveId() : 1;
    $query = $db->prepare('SELECT id,name,legal_name,tax_id,address,phone,email,website,version,updated_at,
        (logo_data IS NOT NULL) AS has_logo FROM business_company WHERE id=?');
    $query->execute([$companyId]); $record=$query->fetch();
    if (!$record) throw new RuntimeException('La ficha de Empresa no está instalada.');
    return $record;
}

function validateBusinessCompany(array $input): array
{
    $data = [];
    foreach (['name'=>160,'legal_name'=>180,'tax_id'=>40,'address'=>1000,'phone'=>40,'email'=>160,'website'=>200] as $key=>$length) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Los datos de Empresa no son válidos.');
        $data[$key] = trim($input[$key]??'');
        if (mb_strlen($data[$key],'UTF-8')>$length) throw new InvalidArgumentException('Uno de los campos supera el tamaño permitido.');
    }
    if ($data['name']==='') throw new InvalidArgumentException('Escribe el nombre comercial de la empresa.');
    if ($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    if ($data['website']!=='' && (!filter_var($data['website'],FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)parse_url($data['website'],PHP_URL_SCHEME)),['https','http'],true))) {
        throw new InvalidArgumentException('El sitio web debe ser una dirección válida que comience por https:// o http://.');
    }
    return $data;
}

function validateCompanyLogo(string $bytes): array
{
    if ($bytes==='' || strlen($bytes)>2*1024*1024) throw new InvalidArgumentException('El logo debe ocupar como máximo 2 MB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true)
        || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096) {
        throw new InvalidArgumentException('Selecciona una imagen PNG, JPEG o WebP de hasta 4096 × 4096 píxeles.');
    }
    return ['data'=>$bytes,'mime'=>image_type_to_mime_type($info[2])];
}

function companyLogoUpload(array $files): ?array
{
    if (!isset($files['logo'])) return null;
    $file = $files['logo'];
    if (!is_array($file) || !is_int($file['error']??null)) throw new InvalidArgumentException('La carga del logo no es válida.');
    if ($file['error']===UPLOAD_ERR_NO_FILE) return null;
    if ($file['error']!==UPLOAD_ERR_OK) throw new InvalidArgumentException('No se pudo cargar el logo. Selecciona una imagen de hasta 2 MB.');
    if (!is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('La carga del logo no es válida.');
    $bytes = file_get_contents($file['tmp_name'],false,null,0,2*1024*1024+1);
    if (!is_string($bytes)) throw new InvalidArgumentException('No se pudo leer el logo.');
    return validateCompanyLogo($bytes);
}

function saveBusinessCompany(PDO $db, array $input, int $version, ?array $logo = null, bool $removeLogo = false, ?int $companyId = null): void
{
    $data = validateBusinessCompany($input);
    if ($version<1) throw new InvalidArgumentException('Recarga la ficha de Empresa antes de guardar.');
    if ($logo!==null) {
        if (!is_string($logo['data']??null)) throw new InvalidArgumentException('El logo no es válido.');
        $logo = validateCompanyLogo($logo['data']);
        $data += ['logo_data'=>$logo['data'],'logo_mime'=>$logo['mime']];
    } elseif ($removeLogo) $data += ['logo_data'=>null,'logo_mime'=>null];
    $companyId ??= function_exists('companyActiveId') ? companyActiveId() : 1;
    $query = $db->prepare('UPDATE business_company SET '.implode(',',array_map(static fn($key)=>$key.'=:'.$key,array_keys($data))).',version=version+1 WHERE id=:company AND version=:version');
    $query->execute($data+['version'=>$version,'company'=>$companyId]);
    if ($query->rowCount()!==1) throw new InvalidArgumentException('La ficha de Empresa cambió en otra ventana. Recarga para revisar los datos antes de guardar.',409);
}

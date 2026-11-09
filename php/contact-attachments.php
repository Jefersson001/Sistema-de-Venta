<?php
declare(strict_types=1);
require_once __DIR__.'/contacts-repository.php';
require_once __DIR__.'/upload-storage.php';

function contactAttachmentDirectory(): string
{
    return privateUploadDirectory('contacts');
}

function contactAttachmentPath(string $key): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Referencia de archivo inválida.');
    return contactAttachmentDirectory().'/'.$key.'.bin';
}

function loadContactAttachments(PDO $db, int $id): array
{
    $q = $db->prepare('SELECT id, filename, description, mime_type, byte_size FROM contact_attachments WHERE contact_id=? ORDER BY id');
    $q->execute([$id]); return $q->fetchAll();
}

/** Validate every line before moving files or modifying the contact. */
function validateContactAttachments(array $input, array $files): array
{
    $uploads = []; $errors = []; $total = 0;
    $raw = $files['contact_files'] ?? [];
    $descriptions = $input['attachment_descriptions'] ?? [];
    $remove = $input['remove_attachments'] ?? [];
    if (!is_array($descriptions) || !is_array($remove) || count($remove)>500) return [[], [], ['Las líneas de archivos no son válidas.']];
    $removeIds = [];
    foreach ($remove as $value) {
        $id = contactId($value);
        if (!$id) $errors[] = 'El archivo que deseas quitar no es válido.'; else $removeIds[$id] = $id;
    }
    if (!$raw) return [$uploads, array_values($removeIds), $errors];
    foreach (['name','tmp_name','error','size'] as $field) if (!isset($raw[$field]) || !is_array($raw[$field])) return [[], [], ['Los archivos recibidos no son válidos.']];
    if (count($raw['name'])>20) return [[], [], ['Puedes adjuntar hasta 20 archivos por guardado.']];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($raw['name'] as $index => $filename) {
        $error = $raw['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) { $errors[] = 'No se pudo recibir un archivo. Vuelve a seleccionarlo (máximo 10 MB por archivo).'; continue; }
        $tmp = $raw['tmp_name'][$index] ?? null;
        if (!is_string($filename) || !is_string($tmp) || !is_uploaded_file($tmp)) { $errors[] = 'El archivo recibido no es válido.'; continue; }
        $size = filesize($tmp);
        if (!$size || $size>10*1024*1024) { $errors[] = 'Cada archivo debe tener contenido y pesar como máximo 10 MB.'; continue; }
        $total += $size;
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1f\x7f]/u', '', $filename);
        if (!$filename || mb_strlen($filename)>200) { $errors[] = 'El nombre del archivo debe tener entre 1 y 200 caracteres.'; continue; }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = $finfo->file($tmp);
        $allowed = [
            'jpg'=>['image/jpeg'], 'jpeg'=>['image/jpeg'], 'png'=>['image/png'], 'webp'=>['image/webp'], 'gif'=>['image/gif'],
            'pdf'=>['application/pdf'], 'txt'=>['text/plain'],
            'doc'=>['application/msword','application/x-ole-storage','application/CDFV2'],
            'xls'=>['application/vnd.ms-excel','application/x-ole-storage','application/CDFV2'],
            'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ];
        if (!in_array($mime, $allowed[$extension] ?? [], true)) { $errors[] = $filename.': formato no admitido o contenido diferente de su extensión.'; continue; }
        if (in_array($extension, ['jpg','jpeg','png','gif','webp'], true) && !@getimagesize($tmp)) { $errors[] = $filename.': la imagen no es válida.'; continue; }
        if (in_array($extension, ['docx','xlsx'], true)) {
            $zip = new ZipArchive(); $valid = $zip->open($tmp) === true;
            if ($valid) {
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($extension==='docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false;
                $zip->close();
            }
            if (!$valid) { $errors[] = $filename.': el documento de Office no es válido.'; continue; }
            $mime = $extension==='docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        $description = $descriptions[$index] ?? '';
        if (!is_string($description) || mb_strlen(trim($description))>200) { $errors[] = 'La descripción admite hasta 200 caracteres.'; continue; }
        $uploads[] = ['tmp'=>$tmp, 'filename'=>$filename, 'mime_type'=>$mime, 'byte_size'=>$size, 'description'=>trim($description)];
    }
    if ($total>20*1024*1024) $errors[] = 'El total de archivos nuevos admite hasta 20 MB por guardado.';
    return [$uploads, array_values($removeIds), $errors];
}

function saveContactWithAttachments(PDO $db, array $data, ?int $id, array $uploads, array $remove, ?array $addressChanges=null, ?string $addressRevision=null): int
{
    $newPaths = []; $oldPaths = [];
    $db->beginTransaction();
    try {
        if ($id) {
            $q = $db->prepare('SELECT id FROM contacts WHERE id=? FOR UPDATE'); $q->execute([$id]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('El contacto ya no está disponible.');
            if($addressRevision!==null&&!hash_equals(contactAddressesRevision(loadContactAddresses($db,$id)),$addressRevision))throw new InvalidArgumentException('Las direcciones cambiaron en otra ventana. Recarga antes de guardar.');
        } elseif ($remove) throw new InvalidArgumentException('Un contacto nuevo no tiene archivos para quitar.');
        foreach ($remove as $attachment) {
            $q = $db->prepare('SELECT storage_key FROM contact_attachments WHERE id=? AND contact_id=? FOR UPDATE'); $q->execute([$attachment,$id]);
            $key = $q->fetchColumn();
            if (!$key) throw new InvalidArgumentException('Uno de los archivos ya no existe o pertenece a otro contacto. Recarga la ficha.');
            $oldPaths[] = contactAttachmentPath($key);
        }
        $saved = saveContact($db, $data, $id);
        saveContactAddressLines($db,$saved,$addressChanges);
        if ($uploads) {
            $directory = contactAttachmentDirectory();
            ensurePrivateUploadDirectory($directory);
            $q = $db->prepare('INSERT INTO contact_attachments(contact_id, storage_key, filename, description, mime_type, byte_size) VALUES(?,?,?,?,?,?)');
            foreach ($uploads as $upload) {
                $key = bin2hex(random_bytes(32)); $path = contactAttachmentPath($key);
                if (!move_uploaded_file($upload['tmp'], $path)) throw new RuntimeException('No se pudo guardar el archivo adjunto.');
                $newPaths[] = $path;
                $q->execute([$saved,$key,$upload['filename'],$upload['description'],$upload['mime_type'],$upload['byte_size']]);
            }
        }
        $delete = $db->prepare('DELETE FROM contact_attachments WHERE id=? AND contact_id=?');
        foreach ($remove as $attachment) $delete->execute([$attachment,$saved]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($newPaths as $path) if (is_file($path)) unlink($path);
        throw $e;
    }
    foreach ($oldPaths as $path) if (is_file($path) && !@unlink($path)) error_log('No se pudo retirar un archivo privado de contactos.');
    return $saved;
}

function renderContactAttachment(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); authStop(405,'Esta dirección solo permite consultar archivos.'); }
    $contact = contactId($_GET['id'] ?? null); $file = contactId($_GET['file'] ?? null);
    if (!$contact || !$file) authStop(404,'El archivo solicitado no existe.');
    $db = createDatabaseConnection();
    $q = $db->prepare('SELECT storage_key, filename, mime_type, byte_size FROM contact_attachments WHERE id=? AND contact_id=?'); $q->execute([$file,$contact]); $row=$q->fetch();
    if (!$row || !is_file($path=contactAttachmentPath($row['storage_key']))) authStop(404,'El archivo solicitado no está disponible.');
    $inline = ($_GET['download'] ?? '') !== '1' && (str_starts_with($row['mime_type'],'image/') || $row['mime_type']==='application/pdf');
    header('Content-Type: '.$row['mime_type']); header('Content-Length: '.filesize($path));
    header("Content-Disposition: ".($inline?'inline':'attachment')."; filename=\"archivo\"; filename*=UTF-8''".rawurlencode($row['filename']));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    readfile($path);
}

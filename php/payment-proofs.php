<?php
declare(strict_types=1);
require_once __DIR__.'/contact-attachments.php';

function paymentProofDirectory(): string {return getenv('NEXO_PAYMENT_PROOF_DIR') ?: __DIR__.'/uploads/payment-proofs';}
function paymentProofPath(string $key): string
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Referencia de comprobante inválida.');
    return paymentProofDirectory().'/'.$key.'.bin';
}
function paymentProofTarget(string $kind): array
{
    return match($kind){'payment'=>['accounting_payments','payment_id'],'treasury'=>['accounting_treasury_documents','treasury_id'],default=>throw new LogicException('Tipo de comprobante inválido.')};
}

/** Files are moved only inside the money transaction; rollback removes new files. */
final class PaymentProofUpload
{
    private array $groups=[], $paths=[];
    private bool $committed=false;
    public function __construct(array $files,bool $grouped=false)
    {
        $raw=$files['proof_files']??null;if(!$raw)return;
        if(!is_array($raw)||!is_array($raw['name']??null))throw new InvalidArgumentException('Los comprobantes recibidos no son válidos.');
        $groups=$grouped?array_keys($raw['name']):['default'];$total=0;$count=0;
        foreach($groups as $group){
            if($grouped&&(!ctype_digit((string)$group)||(int)$group>19))throw new InvalidArgumentException('El comprobante no corresponde a un pago.');
            $normalized=[];
            foreach(['name','tmp_name','error','size','type'] as $field)$normalized[$field]=$grouped?($raw[$field][$group]??[]):($raw[$field]??[]);
            [$uploads,,$errors]=validateContactAttachments([],['contact_files'=>$normalized]);
            if($errors)throw new InvalidArgumentException(implode(' ',$errors));
            foreach($uploads as $upload){
                if(!str_starts_with($upload['mime_type'],'image/')&&$upload['mime_type']!=='application/pdf')throw new InvalidArgumentException('El comprobante debe ser una foto JPG, PNG, WEBP o GIF, o un PDF.');
                $total+=$upload['byte_size'];$count++;
            }
            if($uploads)$this->groups[(string)$group]=$uploads;
        }
        if($total>20*1024*1024||$count>20)throw new InvalidArgumentException('Adjunta hasta 20 comprobantes y 20 MB en total por guardado.');
    }
    public function validatePaymentCount(int $count): void
    {
        foreach(array_keys($this->groups) as $group)if(!ctype_digit((string)$group)||(int)$group>=$count)throw new InvalidArgumentException('Hay un comprobante sin pago relacionado.');
    }
    public function attach(PDO $db,string $kind,int $id,string $group='default'): void
    {
        if(empty($this->groups[$group]))return;
        if(!$db->inTransaction())throw new LogicException('Los comprobantes requieren una transacción.');
        [, $field]=paymentProofTarget($kind);$directory=paymentProofDirectory();
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new InvalidArgumentException('No se pudo preparar el almacenamiento del comprobante.');
        foreach($this->groups[$group] as $upload){
            $hash=hash_file('sha256',$upload['tmp']);
            $q=$db->prepare('SELECT id FROM payment_proofs WHERE '.$field.'=? AND content_hash=?');$q->execute([$id,$hash]);if($q->fetchColumn())continue;
            $key=bin2hex(random_bytes(32));$path=paymentProofPath($key);
            if(!move_uploaded_file($upload['tmp'],$path))throw new InvalidArgumentException('No se pudo guardar el comprobante. Vuelve a seleccionarlo.');
            $this->paths[]=$path;
            $db->prepare('INSERT INTO payment_proofs('.$field.',storage_key,filename,mime_type,byte_size,content_hash) VALUES(?,?,?,?,?,?)')->execute([$id,$key,$upload['filename'],$upload['mime_type'],$upload['byte_size'],$hash]);
        }
    }
    public function commit(): void {$this->committed=true;}
    public function __destruct(){if(!$this->committed)foreach($this->paths as $path)if(is_file($path))@unlink($path);}
}
function paymentProofInput(array $input,array $files,bool $grouped=false): array
{
    $input['_proofs']=new PaymentProofUpload($files,$grouped);return $input;
}
function paymentProofBatch(array $input): ?PaymentProofUpload {return ($input['_proofs']??null) instanceof PaymentProofUpload?$input['_proofs']:null;}
function loadPaymentProofs(PDO $db,string $kind,int $id): array
{
    [, $field]=paymentProofTarget($kind);$q=$db->prepare('SELECT id,filename,mime_type,byte_size,created_at FROM payment_proofs WHERE '.$field.'=? ORDER BY id');$q->execute([$id]);return $q->fetchAll();
}
function addPaymentProofs(PDO $db,string $kind,int $id,array $files): void
{
    $batch=new PaymentProofUpload($files);[$table]=paymentProofTarget($kind);$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('El pago ya no está disponible.');
        $batch->attach($db,$kind,$id);$db->commit();$batch->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function renderPaymentProof(PDO $db,string $kind,int $id,int $proof): never
{
    if($_SERVER['REQUEST_METHOD']!=='GET')authStop(405,'Esta dirección solo permite consultar comprobantes.');
    [, $field]=paymentProofTarget($kind);$q=$db->prepare('SELECT * FROM payment_proofs WHERE id=? AND '.$field.'=?');$q->execute([$proof,$id]);$row=$q->fetch();
    if(!$row||!is_file($path=paymentProofPath($row['storage_key'])))authStop(404,'El comprobante no está disponible.');
    header('Content-Type: '.$row['mime_type']);header('Content-Length: '.filesize($path));
    header("Content-Disposition: ".(($_GET['download']??'')==='1'?'attachment':'inline')."; filename=\"comprobante\"; filename*=UTF-8''".rawurlencode($row['filename']));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();readfile($path);exit;
}

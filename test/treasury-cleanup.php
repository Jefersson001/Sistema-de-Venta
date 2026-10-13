<?php
declare(strict_types=1);
function cleanupFixtureTreasury(PDO $db,array $contacts,string $prefix): void
{
    if(!str_starts_with($prefix,'Test ')||!preg_match('/[a-f0-9]{16}$/',$prefix))throw new RuntimeException('Prefijo de limpieza inválido.');
    foreach($contacts as $contact){
        $q=$db->prepare('SELECT name FROM contacts WHERE id=?');$q->execute([$contact]);$name=$q->fetchColumn();
        if(!$name||!str_starts_with($name,$prefix))throw new RuntimeException('La cuenta de contacto no pertenece a esta prueba.');
        $q=$db->prepare('SELECT id FROM accounting_payments WHERE contact_id=?');$q->execute([$contact]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $payment){
            $q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE payment_id=?');$q->execute([$payment]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $document){$db->prepare('DELETE FROM accounting_treasury_entries WHERE document_id=?')->execute([$document]);$db->prepare('DELETE FROM accounting_treasury_documents WHERE id=?')->execute([$document]);}
            $db->prepare('DELETE FROM accounting_treasury_entries WHERE payment_id=?')->execute([$payment]);
        }
    }
}

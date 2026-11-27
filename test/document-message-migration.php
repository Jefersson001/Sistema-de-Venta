<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/migration-runner.php';
require_once dirname(__DIR__).'/php/company-mail.php';
$db=migrationConnection();$name='nexo_message_test_'.bin2hex(random_bytes(8));
try{
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->exec('USE `'.$name.'`');
    foreach(['company_mail_settings','company_whatsapp_settings'] as $table){
        $db->exec('CREATE TABLE '.$table.' (company_id INT PRIMARY KEY,message_template TEXT NOT NULL,password_cipher TEXT NULL)');
        $db->prepare('INSERT INTO '.$table.' VALUES(?,?,?),(?,?,?)')->execute([1,'Texto empresa uno {cliente}','credencial-cifrada-uno',2,'Texto empresa dos {documento}','credencial-cifrada-dos']);
    }
    // Simulate a prior attempt that stopped after its first DDL statement.
    $db->exec('ALTER TABLE company_mail_settings ADD message_template_order TEXT NULL');
    $migration=require dirname(__DIR__).'/php/migrations/versioned/20261127033500_plantillas_mensajes_por_documento_y_ticket.php';
    $migration($db);$migration($db);
    foreach(['company_mail_settings','company_whatsapp_settings'] as $table){
        foreach($db->query('SELECT * FROM '.$table.' ORDER BY company_id')->fetchAll() as $row){
            $loaded=companyDocumentMessageTemplates($row);
            foreach(documentMessageKinds() as $kind=>$label)if($loaded['message_template_'.$kind]!==$row['message_template'])throw new RuntimeException('Existing company message was not inherited.');
            if($row['password_cipher']!==((int)$row['company_id']===1?'credencial-cifrada-uno':'credencial-cifrada-dos'))throw new RuntimeException('Credential changed.');
        }
        $db->exec("UPDATE $table SET message_template_order='Venta personalizada',message_template_delivery='Entrega personalizada',message_template_invoice='Factura personalizada' WHERE company_id=1");
        $before=$db->query('SELECT * FROM '.$table.' ORDER BY company_id')->fetchAll();$migration($db);
        if($before!==$db->query('SELECT * FROM '.$table.' ORDER BY company_id')->fetchAll())throw new RuntimeException('Repeated migration overwrote prior data.');
    }
    echo "PASS: migración parcial reintentada, mensajes previos por empresa conservados, credenciales intactas y personalizaciones preservadas.\n";
}finally{$db->exec('DROP DATABASE IF EXISTS `'.$name.'`');}

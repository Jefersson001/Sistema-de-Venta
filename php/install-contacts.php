<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/connection.php';
require_once __DIR__.'/company-maintenance.php';
companyGuardLegacyInstaller();
require_once __DIR__ . '/storage-schema.php';

try {
    $database = createDatabaseConnection();
    $documentsOnly = in_array('--documents-only', $argv, true);
    $salespersonOnly = in_array('--salesperson-only', $argv, true);
    $files = $salespersonOnly ? [] : ($documentsOnly
        ? ['002_document_types.sql'] : ['001_contacts.sql', '002_document_types.sql']);
    foreach ($files as $file) {
        $migration = file_get_contents(__DIR__ . '/migrations/' . $file);
        if ($migration === false) {
            throw new RuntimeException('No se pudo leer la migración de Contactos.');
        }
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') {
                $database->exec($statement);
            }
        }
    }
    if (! $database->query("SHOW COLUMNS FROM contacts LIKE 'document_type_id'")->fetch()) {
        $database->exec('ALTER TABLE contacts ADD document_type_id INT UNSIGNED NULL AFTER tax_id,
            ADD CONSTRAINT contact_document_type FOREIGN KEY (document_type_id) REFERENCES contact_document_types(id)');
    }
    if (! $documentsOnly) {
        if (! $database->query("SHOW COLUMNS FROM contact_types LIKE 'system_key'")->fetch()) {
            $database->exec('ALTER TABLE contact_types ADD system_key VARCHAR(32) NULL, ADD UNIQUE KEY contact_type_system_key (system_key)');
        }
        $migration = file_get_contents(__DIR__ . '/migrations/003_contact_salesperson.sql');
        if ($migration === false) {
            throw new RuntimeException('No se pudo leer la migración de vendedores.');
        }
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') {
                $database->exec($statement);
            }
        }
        if (! $database->query("SHOW COLUMNS FROM contacts LIKE 'salesperson_id'")->fetch()) {
            $database->exec('ALTER TABLE contacts ADD salesperson_id INT UNSIGNED NULL,
                ADD CONSTRAINT contact_salesperson FOREIGN KEY (salesperson_id) REFERENCES contacts(id)');
        }
        $supplierMigration = file_get_contents(__DIR__ . '/migrations/009_contact_supplier.sql');
        if ($supplierMigration === false) {
            throw new RuntimeException('No se pudo leer la migración de proveedores.');
        }
        foreach (explode(';', $supplierMigration) as $statement) {
            if (trim($statement) !== '') {
                $database->exec($statement);
            }
        }
    }
    if (! $documentsOnly) { installContactStorageRelations($database); }
    echo "Módulo de Contactos instalado.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "No se pudo instalar Contactos: " . $exception->getMessage() . "\n");
    exit(1);
}

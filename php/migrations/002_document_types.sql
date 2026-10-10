CREATE TABLE IF NOT EXISTS contact_document_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code CHAR(1) NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY document_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO contact_document_types (code, name)
SELECT 'V', 'Venezolano' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'V');
INSERT INTO contact_document_types (code, name)
SELECT 'E', 'Extranjero' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'E');
INSERT INTO contact_document_types (code, name)
SELECT 'J', 'Jurídico' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'J');
INSERT INTO contact_document_types (code, name)
SELECT 'P', 'Pasaporte' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'P');
INSERT INTO contact_document_types (code, name)
SELECT 'G', 'Gubernamental' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'G');
INSERT INTO contact_document_types (code, name)
SELECT 'C', 'Comuna o consejo comunal' WHERE NOT EXISTS (SELECT 1 FROM contact_document_types WHERE code = 'C');

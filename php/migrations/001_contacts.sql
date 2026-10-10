CREATE TABLE IF NOT EXISTS contact_countries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(3) NULL,
    UNIQUE KEY country_name (name),
    UNIQUE KEY country_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_states (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY state_name (country_id, name),
    CONSTRAINT contact_state_country FOREIGN KEY (country_id) REFERENCES contact_countries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_cities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY city_name (state_id, name),
    CONSTRAINT contact_city_state FOREIGN KEY (state_id) REFERENCES contact_states(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    UNIQUE KEY type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO contact_types (name)
SELECT 'Administrador' WHERE NOT EXISTS (SELECT 1 FROM contact_types WHERE name = 'Administrador');
INSERT INTO contact_types (name)
SELECT 'Empleado' WHERE NOT EXISTS (SELECT 1 FROM contact_types WHERE name = 'Empleado');
INSERT INTO contact_types (name)
SELECT 'Cliente' WHERE NOT EXISTS (SELECT 1 FROM contact_types WHERE name = 'Cliente');
INSERT INTO contact_types (name)
SELECT 'Vendedor' WHERE NOT EXISTS (SELECT 1 FROM contact_types WHERE name = 'Vendedor');

CREATE TABLE IF NOT EXISTS contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    entity_kind ENUM('person', 'company') NOT NULL DEFAULT 'person',
    type_id INT UNSIGNED NOT NULL,
    tax_id VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    mobile VARCHAR(40) NULL,
    website VARCHAR(200) NULL,
    address VARCHAR(255) NULL,
    postal_code VARCHAR(20) NULL,
    country_id INT UNSIGNED NULL,
    state_id INT UNSIGNED NULL,
    city_id INT UNSIGNED NULL,
    notes TEXT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX contact_name (name),
    INDEX contact_active_type (active, type_id),
    CONSTRAINT contact_type FOREIGN KEY (type_id) REFERENCES contact_types(id),
    CONSTRAINT contact_country FOREIGN KEY (country_id) REFERENCES contact_countries(id),
    CONSTRAINT contact_state FOREIGN KEY (state_id) REFERENCES contact_states(id),
    CONSTRAINT contact_city FOREIGN KEY (city_id) REFERENCES contact_cities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

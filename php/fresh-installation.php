<?php
declare(strict_types=1);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/storage-schema.php';
require_once __DIR__.'/sales-schema.php';
require_once __DIR__.'/sales-delivery-schema.php';
require_once __DIR__.'/commerce-schema.php';
require_once __DIR__.'/company-schema.php';
require_once __DIR__.'/phase15-schema.php';
require_once __DIR__.'/geolocation-schema.php';
require_once __DIR__.'/product-details-schema.php';
require_once __DIR__.'/inventory-tracking-schema.php';
require_once __DIR__.'/product-variants-schema.php';
require_once __DIR__.'/restaurant-schema.php';
require_once __DIR__.'/record-actions-schema.php';
require_once __DIR__.'/contact-attachments-schema.php';
require_once __DIR__.'/payment-proofs-schema.php';
require_once __DIR__.'/two-decimals-schema.php';

/** Only the old, simple SQL migrations use semicolon splitting; no trigger bodies. */
function freshInstallMigration(PDO $db, string $file): void
{
    $sql = file_get_contents(__DIR__.'/migrations/'.$file);
    if ($sql === false) throw new RuntimeException('No se pudo leer la migración '.$file);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
}

function freshInstallationSteps(): array
{
    $steps = [];
    foreach (['001_contacts.sql','002_document_types.sql','003_contact_salesperson.sql',
        '009_contact_supplier.sql','004_inventory_categories.sql','005_inventory_products.sql',
        '006_business_taxes.sql','007_payment_terms.sql','008_sales_quotations.sql',
        '010_inventory_warehouses.sql','012_inventory_operations.sql','013_inventory_stock.sql',
        '014_purchase_orders.sql'] as $file) {
        if ($file==='003_contact_salesperson.sql') {
            $steps['contact_type_keys'] = static function (PDO $db): void {
                if (workflowColumn($db,'contact_types','system_key','VARCHAR(32) NULL')) {
                    $db->exec('ALTER TABLE contact_types ADD UNIQUE KEY contact_type_system_key(system_key)');
                }
            };
        }
        $steps['sql_'.$file] = static fn(PDO $db) => freshInstallMigration($db, $file);
    }
    $steps['relations'] = static function (PDO $db): void {
        workflowColumn($db, 'contacts', 'document_type_id', 'INT UNSIGNED NULL');
        $find = $db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='contacts' AND CONSTRAINT_NAME='contact_document_type'");
        if (!$find->fetchColumn()) $db->exec('ALTER TABLE contacts ADD CONSTRAINT contact_document_type FOREIGN KEY(document_type_id) REFERENCES contact_document_types(id)');
        workflowColumn($db, 'contacts', 'salesperson_id', 'INT UNSIGNED NULL');
        $find = $db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='contacts' AND CONSTRAINT_NAME='contact_salesperson'");
        if (!$find->fetchColumn()) $db->exec('ALTER TABLE contacts ADD CONSTRAINT contact_salesperson FOREIGN KEY(salesperson_id) REFERENCES contacts(id)');
        workflowColumn($db, 'inventory_products', 'sale_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        workflowColumn($db, 'inventory_operations', 'status', "ENUM('draft','done') NOT NULL DEFAULT 'draft'");
        foreach (['category_id'=>['inventory_categories','inventory_product_category'],
            'purchase_tax_id'=>['business_taxes','inventory_product_purchase_tax'],
            'sale_tax_id'=>['business_taxes','inventory_product_sale_tax']] as $column=>[$parent,$constraint]) {
            workflowColumn($db, 'inventory_products', $column, 'INT UNSIGNED NULL');
            $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND CONSTRAINT_NAME=?");
            $q->execute([$constraint]);
            if (!$q->fetchColumn()) $db->exec("ALTER TABLE inventory_products ADD CONSTRAINT $constraint FOREIGN KEY($column) REFERENCES $parent(id)");
        }
        installContactStorageRelations($db);
        installQuotationStorageRelations($db);
        installSalesOrderType($db);
        installSalesDeliveryRelation($db);
    };
    $steps['commerce_authentication'] = static fn(PDO $db) => installCommerceDocuments($db);
    $steps['multi_company'] = static fn(PDO $db) => installMultiCompany($db);
    $steps['addresses'] = static fn(PDO $db) => installPhase15Schema($db);
    $steps['geolocation'] = static fn(PDO $db) => installGeolocation($db);
    $steps['product_details'] = static fn(PDO $db) => installProductDetails($db);
    $steps['inventory_tracking'] = static fn(PDO $db) => installInventoryTracking($db);
    $steps['product_variants'] = static fn(PDO $db) => installProductVariants($db);
    $steps['restaurant'] = static fn(PDO $db) => installRestaurant($db);
    $steps['record_actions'] = static fn(PDO $db) => installRecordActions($db);
    $steps['attachments'] = static fn(PDO $db) => installContactAttachments($db);
    $steps['payment_proofs'] = static fn(PDO $db) => installPaymentProofs($db);
    $steps['two_decimals'] = static fn(PDO $db) => installTwoDecimals($db);
    $steps['defaults'] = static function (PDO $db): void {
        $db->exec("SET @nexo_company_id=1, @nexo_actor_id=NULL");
        $db->exec("UPDATE business_company SET name='Mi empresa' WHERE id=1 AND name=''");
        $db->exec("INSERT INTO accounting_payment_terms(name,days) SELECT 'Contado',0 WHERE NOT EXISTS(SELECT 1 FROM accounting_payment_terms WHERE company_id=1)");
        $db->exec("UPDATE accounting_payment_accounts SET currency_code='USD' WHERE company_id=1 AND system_key='default_cash' AND currency_code IS NULL");
    };
    return $steps;
}

/** Check privileges before installing anything, including the common MySQL 1419 case. */
function freshInstallationPreflight(PDO $db): void
{
    foreach (['pdo_mysql','bcmath','mbstring','dom','curl','fileinfo','zip'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('Falta la extensión PHP '.$extension.'.');
    }
    $version=(string)$db->query('SELECT VERSION()')->fetchColumn();
    if (!str_contains(strtolower($version),'mariadb') && version_compare($version,'8.0.16','<')) {
        throw new RuntimeException('Se requiere MySQL 8.0.16 o posterior, o MariaDB.');
    }
    $grants=implode("\n",$db->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN));
    if ((int)$db->query('SELECT @@log_bin')->fetchColumn()
        && !(int)$db->query('SELECT @@log_bin_trust_function_creators')->fetchColumn()
        && !preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bSUPER\b[^\n]*) ON \*\.\*/i',$grants)) {
        throw new RuntimeException('MySQL exige privilegios administrativos para crear triggers. Repite con --db-user=root; la aplicación conserva DATABASE_URL.');
    }
    // Actually exercise CREATE/ALTER/INDEX/INSERT/UPDATE/DELETE/TRIGGER before business DDL.
    $probe='fresh_probe_'.bin2hex(random_bytes(6));
    try {
        $db->exec("CREATE TABLE `$probe`(id INT PRIMARY KEY)");
        $db->exec("ALTER TABLE `$probe` ADD value INT NULL, ADD INDEX probe_value(value)");
        $db->exec("CREATE TRIGGER `$probe` BEFORE INSERT ON `$probe` FOR EACH ROW SET NEW.value=1");
        $db->exec("INSERT INTO `$probe`(id) VALUES(1)");
        $db->exec("UPDATE `$probe` SET value=2 WHERE id=1");
        $db->exec("DELETE FROM `$probe` WHERE id=1");
    } finally {
        $db->exec("DROP TABLE IF EXISTS `$probe`");
    }
}

/** A failed backup never authorizes removal. Only the known single-table failed import is accepted. */
function freshDiscardPartialImport(PDO $db, array $tables, callable $progress): void
{
    if ($tables !== ['access_groups']) throw new RuntimeException('Solo se admite retirar una importación parcial que contenga únicamente access_groups.');
    $create=$db->query('SHOW CREATE TABLE access_groups')->fetch(PDO::FETCH_NUM)[1];
    $rows=$db->query('SELECT * FROM access_groups')->fetchAll();
    $q=$db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='access_groups'");
    if ($q->fetchColumn()) throw new RuntimeException('access_groups tiene triggers: revisa la base existente antes de reinstalar.');
    $file=tempnam(sys_get_temp_dir(),'vent-as-partial-');
    if ($file===false) throw new RuntimeException('No se pudo preparar la copia de la importación parcial.');
    chmod($file,0600);
    $payload=json_encode(['database'=>$db->query('SELECT DATABASE()')->fetchColumn(),'create'=>$create,'rows'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (file_put_contents($file,$payload)!==strlen($payload)) throw new RuntimeException('Falló la copia; no se retiró la tabla parcial.');
    $progress('Copia de la tabla parcial: '.$file.' (conservar hasta completar la instalación).');
    $db->exec('DROP TABLE access_groups');
}

/** New installations only. Completed steps never run again against live business data. */
function installFreshApplication(PDO $db, callable $progress, bool $discardPartial=false): void
{
    $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $lock='vent_as_install_'.substr(hash('sha256',$database),0,40);
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Ya hay una instalación en curso para esta base.');
    try {
        $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);
        $hasState=in_array('application_installation',$tables,true);
        if ($hasState) {
            $signature=$db->query("SELECT installer FROM application_installation WHERE id=1")->fetchColumn();
            if ($signature!=='vent-as-fresh-v1') throw new RuntimeException('La base no pertenece a este instalador.');
        } elseif ($tables && !($discardPartial && $tables===['access_groups'])) {
            throw new RuntimeException('La base contiene tablas. Este instalador no borra una instalación existente. Si solo existe access_groups de la importación fallida, utiliza --discard-partial-import.');
        }
        $pending=[];
        if ($hasState) {
            if (in_array('application_installation_steps',$tables,true)) {
                $pending=$db->query('SELECT step_key FROM application_installation_steps')->fetchAll(PDO::FETCH_COLUMN);
            }
            $complete=(bool)$db->query('SELECT completed_at IS NOT NULL FROM application_installation WHERE id=1')->fetchColumn();
            if ($complete) { $progress('La instalación ya está completa; se conservan los datos y usuarios.');return; }
            if (in_array('auth_users',$tables,true) && $db->query('SELECT 1 FROM auth_users LIMIT 1')->fetchColumn()) {
                throw new RuntimeException('Hay usuarios en una instalación incompleta. Se requiere revisión antes de reanudar.');
            }
        }
        freshInstallationPreflight($db);
        if (!$hasState) {
            if ($tables) freshDiscardPartialImport($db,$tables,$progress);
            $db->exec("CREATE TABLE application_installation(id TINYINT UNSIGNED PRIMARY KEY,installer VARCHAR(40) NOT NULL,completed_at DATETIME NULL)");
            $db->exec("INSERT INTO application_installation VALUES(1,'vent-as-fresh-v1',NULL)");
        }
        $db->exec('CREATE TABLE IF NOT EXISTS application_installation_steps(step_key VARCHAR(100) PRIMARY KEY,completed_at DATETIME NOT NULL)');
        $db->exec('SET @nexo_company_id=1, @nexo_actor_id=NULL');
        foreach (freshInstallationSteps() as $name=>$step) {
            if (in_array($name,$pending,true)) continue;
            $progress('Instalando: '.$name);
            $step($db);
            $q=$db->prepare('INSERT INTO application_installation_steps VALUES(?,CURRENT_TIMESTAMP)');$q->execute([$name]);
        }
        $db->exec('UPDATE application_installation SET completed_at=CURRENT_TIMESTAMP WHERE id=1');
        $progress('Instalación completa. No se crearon productos, contactos, ventas ni usuarios de ejemplo.');
    } finally {
        $q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);
    }
}

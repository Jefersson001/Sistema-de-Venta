<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/bcv-company-rates.php';
    $publications=bcvStoredPublications($db);
    foreach($db->query('SELECT id FROM business_company ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $company){
        bcvSeedCompanyRates($db,(int)$company,$publications);
    }
};

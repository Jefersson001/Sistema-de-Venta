<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/php/auth-bootstrap.php';

require_once dirname(__DIR__, 2) . '/php/module-page.php';

renderModulePage('dashboard', true);

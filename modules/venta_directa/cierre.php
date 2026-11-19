<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/php/auth-bootstrap.php';
// Retired workflow: old bookmarks return to the seller's sales history.
header('Location: index.php?view=history', true, 303);
exit;

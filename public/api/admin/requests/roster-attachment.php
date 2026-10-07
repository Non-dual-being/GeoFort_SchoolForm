<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardRosterSchoolDocumentAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';
$container['controllers'][DashboardRosterSchoolDocumentAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''), $_GET, false, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
);

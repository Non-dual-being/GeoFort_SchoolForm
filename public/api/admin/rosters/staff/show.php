<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardRosterStaffShowAction;

$container = require dirname(__DIR__, 5) . '/bootstrap.php';
$container['controllers'][DashboardRosterStaffShowAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''), $_GET, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
);

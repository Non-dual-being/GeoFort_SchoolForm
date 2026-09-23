<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardRosterStaffListAction;

$container = require dirname(__DIR__, 5) . '/bootstrap.php';

$container['controllers'][DashboardRosterStaffListAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
);
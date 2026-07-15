<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardBookingListAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';

$container['controllers'][DashboardBookingListAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    $_GET,
);

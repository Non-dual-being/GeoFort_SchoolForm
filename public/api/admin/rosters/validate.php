<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardRosterValidateAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';
$container['controllers'][DashboardRosterValidateAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    $_GET,
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
);

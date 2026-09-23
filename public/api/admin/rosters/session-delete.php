<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardRosterSessionDeleteAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';

$container['controllers'][DashboardRosterSessionDeleteAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null,
    (string) file_get_contents('php://input'),
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
);
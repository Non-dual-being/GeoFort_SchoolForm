<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardSessionAction;

$container = require dirname(__DIR__, 3) . '/bootstrap.php';
$container['controllers'][DashboardSessionAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null,
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
);

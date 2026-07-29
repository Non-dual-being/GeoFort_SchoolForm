<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardCalendarDateManagementPreviewAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';
$container['controllers'][DashboardCalendarDateManagementPreviewAction::class]->send(
    $_SERVER['REQUEST_METHOD'] ?? '',
    $_SERVER['CONTENT_TYPE'] ?? '',
    $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
    file_get_contents('php://input') ?: '',
    $_SERVER['HTTP_USER_AGENT'] ?? '',
);

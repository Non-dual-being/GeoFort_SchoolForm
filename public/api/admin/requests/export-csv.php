<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardBookingCsvExportAction;

$container = require dirname(__DIR__, 4) . '/bootstrap.php';

$container['controllers'][DashboardBookingCsvExportAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    $_GET,
);

<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardOverviewAction;

$container = require dirname(__DIR__, 3) . '/bootstrap.php';
$container['controllers'][DashboardOverviewAction::class]->send((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

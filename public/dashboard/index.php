<?php
declare(strict_types=1);

use GeoFort\Controllers\Dashboard\DashboardAppController;

$container = require dirname(__DIR__, 2) . '/bootstrap.php';

$container['controllers'][DashboardAppController::class]->render();

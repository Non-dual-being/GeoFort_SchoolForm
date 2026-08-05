<?php
declare(strict_types=1);
use GeoFort\Services\Http\Api\Admin\DashboardBookingRevenueCsvExportAction;
require dirname(__DIR__,4).'/bootstrap.php';
$container['controllers'][DashboardBookingRevenueCsvExportAction::class]->send($_SERVER['REQUEST_METHOD']??'GET',$_GET);

<?php
declare(strict_types=1);
use GeoFort\Services\Http\Api\Admin\DashboardBookingRevenueListAction;
require dirname(__DIR__,4).'/bootstrap.php';
$container['controllers'][DashboardBookingRevenueListAction::class]->send($_SERVER['REQUEST_METHOD']??'GET',$_GET);

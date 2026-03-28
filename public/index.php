<?php 
declare(strict_types=1);
use GeoFort\Controllers\indexController;
use GeoFort\Services\ViteService;

$container = require_once dirname(__DIR__) . '/bootstrap.php';

$env = $container['config']['app_env'];
$vite = new ViteService($env);

$controller = new indexController($vite);
$controller->render();

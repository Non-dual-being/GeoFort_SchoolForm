<?php 
declare(strict_types=1);
use GeoFort\Controllers\indexController;
use GeoFort\Services\ViteService;

$container = require_once dirname(__DIR__) . '/bootstrap.php';

$env = $container['config']['app_env'];
$vite = new ViteService(
    envState: $env,
    buildPath: $container['config']['vite_build_path'],
    devServerUrl: $container['config']['vite_dev_server_url'],
);

$controller = new indexController($vite);
$controller->render();

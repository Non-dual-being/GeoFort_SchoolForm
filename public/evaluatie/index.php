<?php
declare(strict_types=1);
$container = require dirname(__DIR__, 2) . '/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$vite = $container['http'][\GeoFort\Services\ViteService::class];
require TEMPLATE_PATH . '/visit-survey.php';

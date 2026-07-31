<?php

declare(strict_types=1);

use GeoFort\Security\AuthMiddleware;
use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Mail\Templates\MailLinks;

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
$_ENV['BASE_URL'] = $_SERVER['BASE_URL'] = 'https://onderwijsboeking.geofort.nl';
putenv('APP_ENV=production');
putenv('BASE_URL=https://onderwijsboeking.geofort.nl');

$container = require dirname(__DIR__, 2) . '/bootstrap.php';

if (($container['config']['base_url'] ?? null) !== 'https://onderwijsboeking.geofort.nl') {
    throw new RuntimeException('Bootstrap did not activate the production BASE_URL.');
}
if (!($container['http'][EnvironmentBaseUrlProvider::class] ?? null) instanceof EnvironmentBaseUrlProvider) {
    throw new RuntimeException('Validated base URL provider was not constructed.');
}
if (!($container['http'][HeaderRedirector::class] ?? null) instanceof HeaderRedirector) {
    throw new RuntimeException('Redirect service was not constructed.');
}
if (!($container['auth'][AuthMiddleware::class] ?? null) instanceof AuthMiddleware) {
    throw new RuntimeException('Auth middleware was not constructed.');
}

$mailLinks = $container['mail'][MailLinks::class] ?? null;
if (!$mailLinks instanceof MailLinks) {
    throw new RuntimeException('Mail link service was not constructed.');
}
if ($mailLinks->bookingFormUrl() !== 'https://onderwijsboeking.geofort.nl/') {
    throw new RuntimeException('Generated mail link does not use the active production BASE_URL.');
}
if ($mailLinks->voorwaardenUrl !== 'https://onderwijsboeking.geofort.nl/booking/voorwaarden.php') {
    throw new RuntimeException('Generated terms link does not use the active production BASE_URL.');
}

echo "ProductionDomainConfigurationTest OK\n";

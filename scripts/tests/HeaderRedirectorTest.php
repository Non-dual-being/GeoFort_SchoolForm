<?php

declare(strict_types=1);

use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$redirector = new HeaderRedirector(
    new EnvironmentBaseUrlProvider('production', 'https://onderwijsboeking.geofort.nl'),
);

$location = $redirector->buildLocation('/auth/login-page.php', ['expired' => '1', 'next' => null]);
if ($location !== 'https://onderwijsboeking.geofort.nl/auth/login-page.php?expired=1') {
    throw new RuntimeException('Internal redirect did not use the active validated BASE_URL.');
}

foreach (['', 'dashboard', '//evil.example/path', 'https://evil.example/path', "/ok\r\nX-Test: bad"] as $path) {
    try {
        $redirector->buildLocation($path);
    } catch (InvalidArgumentException) {
        continue;
    }

    throw new RuntimeException("Unsafe redirect path should have been rejected: {$path}");
}

echo "HeaderRedirectorTest OK\n";

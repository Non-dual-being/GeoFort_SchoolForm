<?php

declare(strict_types=1);

use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertSame = static function (string $expected, string $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . " Expected {$expected}, got {$actual}.");
    }
};
$assertRejected = static function (string $environment, string $baseUrl): void {
    try {
        new EnvironmentBaseUrlProvider($environment, $baseUrl);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException("BASE_URL should have been rejected: {$baseUrl}");
};

$assertSame(
    'https://onderwijsboeking.geofort.nl',
    (new EnvironmentBaseUrlProvider('production', 'https://onderwijsboeking.geofort.nl'))->getBaseUrl(),
    'Production domain was not accepted.',
);
$assertSame(
    'https://onderwijsboeking.geofort.nl',
    (new EnvironmentBaseUrlProvider('production', ' HTTPS://ONDERWIJSBOEKING.GEOFORT.NL:443/ '))->getBaseUrl(),
    'Equivalent HTTPS origin was not normalized.',
);
$assertSame(
    'https://onderwijsboeking.test.ignorelist.com',
    (new EnvironmentBaseUrlProvider('production', 'https://onderwijsboeking.test.ignorelist.com/'))->getBaseUrl(),
    'Acceptance domain was not retained.',
);

foreach ([
    'http://onderwijsboeking.geofort.nl',
    'onderwijsboeking.geofort.nl',
    'https://unknown.example',
    'https://onderwijsboeking.geofort.nl/path',
    'https://onderwijsboeking.geofort.nl//',
    'https://onderwijsboeking.geofort.nl?source=test',
    'https://onderwijsboeking.geofort.nl#fragment',
] as $baseUrl) {
    $assertRejected('production', $baseUrl);
}

echo "EnvironmentBaseUrlProviderTest OK\n";

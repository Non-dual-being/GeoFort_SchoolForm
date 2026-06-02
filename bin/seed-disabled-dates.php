<?php

declare(strict_types=1);

use GeoFort\Database\Connector;
use GeoFort\Services\Booking\Availability\DisabledDateGenerator;
use GeoFort\Services\Booking\Seed\DisabledDatesSeedService;
use GeoFort\Services\Sql\DisabledDatesSqlService;

$container = require __DIR__ . '/../bootstrap.php';

/** @var PDO $pdo */
$pdo = $container['db'][Connector::class];

$seedService = new DisabledDatesSeedService(
    new DisabledDateGenerator(),
    new DisabledDatesSqlService($pdo)
);

$seedService->seedUntilEndSchoolYear2027();

echo "Disabled dates seeded.\n";
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;

$config = new RosterPlanningConfig();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert($config->minimumGeoFortStaff('Voedsel-Innovatie') === 1, 'Voedsel Innovatie moet minimaal GeoFort-bemensing vragen.');
$assert($config->schoolSupervisionAllowed('Voedsel-Innovatie') === false, 'Voedsel Innovatie mag niet automatisch als schoolbegeleiding tellen.');
$assert($config->minimumGeoFortStaff('Klimaat-Experience') === 0, 'Klimaat Experience moet zonder GeoFort-docent planbaar zijn.');
$assert($config->schoolSupervisionAllowed('Klimaat-Experience') === true, 'Klimaat Experience moet schoolbegeleiding toestaan.');
$assert($config->minimumGeoFortStaff('Klimparcours') === 0, 'Klimparcours moet zonder GeoFort-docent planbaar zijn.');
$assert($config->schoolSupervisionAllowed('Klimparcours') === true, 'Klimparcours moet schoolbegeleiding toestaan.');
$assert($config->maxParallel('Dynamische-Globe') === 2, 'Standaard parallelgrens moet twee zijn.');
$assert($config->maxParallel('Klimaat-Experience') === 3, 'Klimaat Experience moet drie parallelsessies toestaan.');

fwrite(STDOUT, "OK: roster planning config domain test passed.\n");
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

$assert($config->maxParallel('Dynamische-Globe') === 2, 'DG mag maximaal twee groepen tegelijk hebben.');
$assert($config->maxParallel('Klimaat-Experience') === 3, 'KE mag uitzonderlijk maximaal drie groepen tegelijk hebben.');
$assert($config->maxParallel('Klimparcours') === 3, 'VS mag uitzonderlijk maximaal drie groepen tegelijk hebben.');

foreach ([2, 3, 4, 5] as $count) {
    foreach (['Klimaat-Experience', 'Klimparcours'] as $module) {
        $assert($config->schoolSupervisionAllowedForModuleCount($module, $count), 'KE/VS moet bij ieder moduleaantal School toestaan.');
    }
    foreach (['Earth-Watch', 'Dynamische-Globe', 'Voedsel-Innovatie', 'Minecraft-Klimaatspeurtocht', 'Minecraft-Programmeren'] as $module) {
        $assert(!$config->schoolSupervisionAllowedForModuleCount($module, $count), 'Schoolbeleid van andere modules is gewijzigd.');
    }
}
$assert(
    $config->requiredGeoFortGuidesForSession('Dynamische-Globe', 2, false) === 2,
    'DG met twee groepen moet twee begeleiders vragen.',
);
$assert(
    $config->requiredGeoFortGuidesForSession('Voedsel-Innovatie', 2, false) === 1,
    'VI met twee groepen moet Ã©Ã©n begeleider naast de kok vragen.',
);
fwrite(STDOUT, "OK: roster planning config domain test passed.\n");

<?php

declare(strict_types=1);

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Validation\StoredBookingStudentCountValidator;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\StudentCountValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (FieldValidationException $exception) {
        $assert($exception->getField() === 'aantalLeerlingen', $message . ' Verkeerd foutveld.');
    }
};
$stored = new StoredBookingStudentCountValidator();
$public = new StudentCountValidator();
$selections = [
    ['primairOnderwijs', 'ochtend', 80],
    ['primairOnderwijs', 'dag', 160],
    ['voortgezetOnderbouw', 'dag', 160],
    ['voortgezetBovenbouw', 'dag', 160],
];

foreach ($selections as [$sector, $program, $maximum]) {
    foreach ([1, 28, 39, 40, PHP_INT_MAX] as $count) {
        try {
            $assert($stored->validate($count, $sector, $program) === $count, "Dashboard {$sector}/{$program}: {$count} is gewijzigd.");
        } catch (FieldValidationException $exception) {
            $assert(false, "Dashboard {$sector}/{$program}: {$count} afgewezen: " . $exception->getMessage());
        }
    }
    foreach ([null, 0, -1] as $count) {
        $reject(static fn () => $stored->validate($count, $sector, $program), "Dashboard {$sector}/{$program}: ongeldige waarde geaccepteerd.");
    }

    $assert(BookingProgramConfig::getMinStudentsForSelection($sector, $program) === 40, "Publiek minimum gewijzigd voor {$sector}/{$program}.");
    foreach ([1, 28, 39] as $count) {
        $reject(static fn () => $public->validate($count, $sector, $program), "Publiek {$sector}/{$program}: {$count} onder minimum geaccepteerd.");
    }
    foreach ([40, $maximum] as $count) {
        $assert($public->validate($count, $sector, $program) === $count, "Publieke grens {$sector}/{$program}: {$count} gewijzigd.");
    }
    $reject(static fn () => $public->validate($maximum + 1, $sector, $program), "Publiek maximum gewijzigd voor {$sector}/{$program}.");
}

$expectedLimits = [
    'min' => ['ochtend' => ['basis' => 40], 'dag' => ['basis' => 40, 'voortgezet' => 40]],
    'max' => ['ochtend' => 80, 'dag' => 160],
];
$assert(BookingProgramConfig::STUDENT_LIMITS === $expectedLimits, 'Centrale publieke leerlinglimieten gewijzigd.');
$assert(BookingProgramConfig::forFrontend()['studentLimits'] === $expectedLimits, 'Publieke frontendconfiguratie gewijzigd.');

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, count($failures) . " van {$checks} controles mislukt.\n");
    exit(1);
}
fwrite(STDOUT, "OK: {$checks} controles voor dashboardaantallen en ongewijzigde publieke grenzen geslaagd.\n");

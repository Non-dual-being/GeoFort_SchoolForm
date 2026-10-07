<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\BookingProgramConfig;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    (BookingProgramConfig::MODULE_LABELS['Klimparcours'] ?? null)
        === 'Vleermuizen Speurtuin',
    'De canonieke zichtbare naam moet Vleermuizen Speurtuin zijn.',
);

$assert(
    (BookingProgramConfig::MODULE_ABBREVIATIONS['Klimparcours'] ?? null)
        === 'VS',
    'De vaste afkorting voor Vleermuizen Speurtuin moet VS zijn.',
);

$assert(
    in_array(
        'Klimparcours',
        BookingProgramConfig::MODULES['primairOnderwijs']['dag']['standaard'] ?? [],
        true,
    ),
    'De historische technische key Klimparcours moet behouden blijven.',
);

fwrite(STDOUT, "OK: canonical roster module names domain test passed.\n");
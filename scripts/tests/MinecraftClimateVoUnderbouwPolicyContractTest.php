<?php

declare(strict_types=1);

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Validation\ChoiceModuleSelectionValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$module = 'Minecraft-Klimaatspeurtocht';

$assert(
    in_array(
        $module,
        BookingProgramConfig::getChoiceModulesForSelection(
            'voortgezetOnderbouw',
            'dag',
        ),
        true,
    ),
    'Minecraft Klimaat is not configured for VO underbouw.',
);

$assert(
    !in_array(
        $module,
        BookingProgramConfig::getChoiceModulesForSelection(
            'voortgezetBovenbouw',
            'dag',
        ),
        true,
    ),
    'Minecraft Klimaat must remain unavailable for VO bovenbouw.',
);

$assert(
    !isset(
        BookingProgramConfig::MODULE_FILTERS[$module]['voortgezetOnderbouw'],
    ),
    'Minecraft Klimaat must not have a VO-underbouw year filter.',
);

$selections = [
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vmboBasisKader'],
        ['vmboBasisKader' => ['vmbo1']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vmboBasisKader'],
        ['vmboBasisKader' => ['vmbo2']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vmboBasisKader'],
        ['vmboBasisKader' => ['vmbo3']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vmboGemengdTheoretisch'],
        ['vmboGemengdTheoretisch' => ['vmbo3']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['havo'],
        ['havo' => ['havo3']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vwo'],
        ['vwo' => ['atheneum3']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['vwo'],
        ['vwo' => ['gymnasium3']],
    ),
    new EducationSelectionData(
        'voortgezetOnderbouw',
        ['praktijkOnderwijs'],
        ['praktijkOnderwijs' => ['praktijk3']],
    ),
];

$validator = new ChoiceModuleSelectionValidator();

foreach ($selections as $selection) {
    $assert(
        BookingProgramConfig::isChoiceModuleAllowedForEducationSelection(
            $module,
            'voortgezetOnderbouw',
            $selection,
        ),
        'Minecraft Klimaat is blocked inside VO underbouw.',
    );

    $assert(
        $validator->validate(
            $module,
            'voortgezetOnderbouw',
            'dag',
            $selection,
        ) === $module,
        'Authoritative validator rejects Minecraft Klimaat inside VO underbouw.',
    );
}

$frontend = BookingProgramConfig::forFrontend();

$assert(
    in_array(
        $module,
        $frontend['modules']['voortgezetOnderbouw']['dag']['keuze'] ?? [],
        true,
    ),
    'Frontend config does not expose Minecraft Klimaat for VO underbouw.',
);

$assert(
    !in_array(
        $module,
        $frontend['modules']['voortgezetBovenbouw']['dag']['keuze'] ?? [],
        true,
    ),
    'Frontend config exposes Minecraft Klimaat for VO bovenbouw.',
);

$assert(
    !isset($frontend['moduleFilters'][$module]['voortgezetOnderbouw']),
    'Frontend unexpectedly filters Minecraft Klimaat inside VO underbouw.',
);

fwrite(
    STDOUT,
    "OK: Minecraft Klimaat all-VO-underbouw policy contract passed.\n",
);
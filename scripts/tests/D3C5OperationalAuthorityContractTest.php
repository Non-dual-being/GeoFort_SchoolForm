<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$plan = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterPlanService.php',
);
$generator = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterAutoGenerator.php',
);
$panel = (string) file_get_contents(
    $root . '/resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$planNeedles = [
    '$moduleCount = count($modules);',
    '$planning[\'roundsSource\'] = \'booking_standard\';',
    'schoolSupervisionAllowedForModuleCount',
];

foreach ($planNeedles as $needle) {
    $assert(
        str_contains($plan, $needle),
        'PlanService contract mist: ' . $needle,
    );
}

$generatorNeedles = [
    '\'planningMode\' => \'custom\'',
    'manualContextForPlan($planId)',
    'decodeContextModuleKeys',
    '$context[\'modules\']',
    '$context[\'program\']',
    '$hasKeInputModule',
    '$preferGeoFortKe = false;',
    '$proposal[\'staffing\'][\'preferGeoFortKe\']',
];

foreach ($generatorNeedles as $needle) {
    $assert(
        str_contains($generator, $needle),
        'Generator contract mist: ' . $needle,
    );
}

$assert(
    !str_contains($generator, '$booking = $context[\'booking\'];'),
    'Generator gebruikt nog rechtstreeks de booking als operationele previewbron.',
);

$panelNeedles = [
    'const hasSchoolFallback = computed',
    'props.plan.planning.rounds ?? []',
    'block.type === "round"',
    'plan.planningMode === "custom"',
    'preferGeoFortKe: props.plan.planning.staffingSettings.preferGeoFortKe ?? true',
    'Eerst GeoFort, anders automatisch School',
];

foreach ($panelNeedles as $needle) {
    $assert(
        str_contains($panel, $needle),
        'Frontend contract mist: ' . $needle,
    );
}

$assert(!str_contains($panel, 'v-model="preferGeoFortKe"'), 'Deprecated KE-keuze staat nog in de actieve planner-UI.');
$assert(!str_contains($panel, 'schoolbegeleiding nog bevestigen'), 'Automatische School-toewijzing wordt nog als OPEN getoond.');

$assert(
    !str_contains($panel, 'wizardMode'),
    'Generator heeft nog een tweede Standard/Aangepast authority-switch.',
);

$assert(
    !str_contains($panel, 'key: "round-5"'),
    'Generator bevat nog een hardgecodeerde vijfde ronde.',
);

fwrite(STDOUT, "OK: D3C5A1 operational authority contract passed.\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$api = (string) file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);
$types = (string) file_get_contents(
    $root . '/resources/js/admin/types/roster.ts',
);

$assertContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Missing: {$needle}");
    }
};

$assertNotContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Unexpected: {$needle}");
    }
};

foreach ([
    'manualExpectedTimelineTypes',
    'manualTimeBlockTypeLabel',
    'block.type !== expectedTypes[index]',
    'rounds: manualRounds.value.map',
    'Dagindeling opslaan en rooster openen',
] as $needle) {
    $assertContains(
        $view,
        $needle,
        'V8B2C4 locked timeline frontend contract failed.',
    );
}

foreach ([
    'Tijdvak toevoegen',
    '<select v-model="block.type"',
    'moveManualTimeBlock(',
    'removeManualTimeBlock(',
    'addManualTimeBlock(',
] as $needle) {
    $assertNotContains(
        $view,
        $needle,
        'Timeline structure must not be manually mutable.',
    );
}

$assertContains(
    $api,
    '/api/admin/rosters/manual-program-save.php',
    'Timeline must reuse the existing manual save endpoint.',
);

$assertContains(
    $types,
    'RosterManualTimeBlock',
    'Timeline type contract is missing.',
);

$assertNotContains(
    $view,
    'block.moduleKey',
    'A time block must not contain a module binding.',
);

fwrite(
    STDOUT,
    "OK: manual roster V8B2C4 locked timeline frontend contract passed.\n",
);
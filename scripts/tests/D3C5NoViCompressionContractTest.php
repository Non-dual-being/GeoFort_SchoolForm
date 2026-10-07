<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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

foreach ([
    'Programmas zonder VI gebruiken dezelfde compressieregels',
    'effectiveMaxGroupsForCompressionLevel(',
    "array_chunk(\$bucket['groups'], \$maximumGroups)",
] as $needle) {
    $assert(
        str_contains($generator, $needle),
        'No-VI generator contract mist: ' . $needle,
    );
}

foreach ([
    'minimumGuidesForModuleGroups',
    'preferredGuidesForModuleGroups',
    'sharedGuideModuleKeys',
    'Math.ceil(groupCount / 2)',
] as $needle) {
    $assert(
        str_contains($panel, $needle),
        'Staffing-advice contract mist: ' . $needle,
    );
}

$assert(
    !str_contains(
        $generator,
        '// Generieke fallback voor programma\'s zonder VI.',
    ),
    'Oude no-VI bypass staat nog in de generator.',
);

fwrite(STDOUT, "OK: D3C5 no-VI compression contract passed.\n");
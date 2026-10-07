<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'label: "Basis"',
    'label: "Programma"',
    'label: "Dagindeling"',
    'De startwizard volgt: Basis &rarr; Programma &rarr; Dagindeling.',
    'Na stap 3 opent het rooster.',
    'Personeel instellen en het rooster genereren doe je daarna in Beheren.',
] as $needle) {
    $assert(
        str_contains($source, $needle),
        'Maatwerk startwizard contract mist: ' . $needle,
    );
}

$matched = preg_match(
    '/const\s+manualSteps\s*=\s*computed<readonly\s+AdminWizardStep\[\]>\(\(\)\s*=>\s*\[(.*?)\]\);/s',
    $source,
    $matches,
);

$assert($matched === 1, 'manualSteps blok kon niet worden afgebakend.');

$manualStepsBlock = $matches[1];

$assert(
    !str_contains($manualStepsBlock, 'label: "Personeel"')
    && !str_contains($manualStepsBlock, 'label: "Genereren"'),
    'De startwizard toont nog onterecht Personeel of Genereren als eigen stap.',
);

fwrite(STDOUT, "OK: D3C5 maatwerk startwizard frontend contract passed.\n");
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$overview = (string) file_get_contents($root . '/resources/js/admin/views/DashboardRostersView.vue');
$detail = (string) file_get_contents($root . '/resources/js/admin/views/DashboardRosterDetailView.vue');
$panel = (string) file_get_contents($root . '/resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue');
$css = (string) file_get_contents($root . '/resources/css/admin/rosters.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'function planningModeLabel(item: DashboardRosterPlanSummary): string',
    '<th scope="col">Type</th>',
    '{{ planningModeLabel(item) }}',
    'admin-roster-mode-badge',
] as $needle) {
    $assert(str_contains($overview, $needle), 'Overview mode contract mist: ' . $needle);
}

foreach ([
    'customProgramEditBlocked',
    'Maatwerkrooster',
    'Standaardrooster',
    'Programma en dagindeling wijzigen',
    '<summary>Roosterconfiguratie</summary>',
    'De opgeslagen roosterconfiguratie bepaalt programma, modules en dagindeling.',
] as $needle) {
    $assert(str_contains($detail, $needle), 'Detail mode contract mist: ' . $needle);
}

foreach ([
    'Maatwerkwizard',
    'Standaardwizard',
    'Programma en dagindeling wijzigen',
    'Kies per geselecteerde begeleider maximaal drie voorkeursmodules.',
] as $needle) {
    $assert(str_contains($panel, $needle), 'Wizard identity contract mist: ' . $needle);
}

foreach ([
    '<p>{{ proposal.staffing.message }}</p>',
    'Geen Voedsel Innovatie geselecteerd.',
    'kandidaatindelingen onderzocht.',
    'Werkuren en vrije uren aan het begin/einde van de dag worden voor betaalde krachten',
] as $needle) {
    $assert(!str_contains($panel, $needle), 'Niet-actionele wizardtekst staat nog in de wizard: ' . $needle);
}

foreach ([
    '.admin-roster-mode-badge--standard',
    '.admin-roster-mode-badge--custom',
    '.admin-roster-wizard-identity--standard',
    '.admin-roster-wizard-identity--custom',
] as $needle) {
    $assert(str_contains($css, $needle), 'Mode CSS contract mist: ' . $needle);
}

fwrite(STDOUT, "OK: D3C5 UI identity contract passed.\n");
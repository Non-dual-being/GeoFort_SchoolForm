<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue',
    'resources/js/admin/components/rosters/RosterDaySchedule.vue',
    'resources/js/admin/components/rosters/RosterTeacherSchedule.vue',
    'resources/js/admin/components/rosters/RosterManualGrid.vue',
    'resources/js/admin/components/rosters/RosterTotalOverview.vue',
    'resources/js/admin/views/DashboardRosterDetailView.vue',
    'resources/js/admin/views/DashboardRosterStaffView.vue',
    'resources/js/admin/types/roster.ts',
    'resources/js/admin/types/rosterStaff.ts',
];

$content = '';
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) throw new RuntimeException("{$file} ontbreekt.");
    $content .= file_get_contents($path);
}

foreach ([
    'Alleen lesrooster',
    'Lesrooster + personeel',
    'GeoFort-docent bij voorkeur',
    'Nelleke de With',
    'cookStaffId',
    'employmentType',
    'canGuide',
    'canCook',
    'Totaaloverzicht',
    'Lesrooster handmatig corrigeren',
    'Tijd — alleen-lezen',
    '🗑 Verwijderen',
    'Toevoegen',
    'Vrij',
    'OPEN KOK',
    'Schoolverantwoordelijke',
] as $needle) {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException("Finale roosterworkspace mist {$needle}.");
    }
}

fwrite(STDOUT, "OK: roster final workspace frontend contract passed.\n");

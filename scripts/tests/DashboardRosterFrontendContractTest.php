<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/router/index.ts',
    'resources/js/admin/components/layout/DashboardNavigation.vue',
    'resources/js/admin/components/bookings/BookingRosterPanel.vue',
    'resources/js/admin/components/rosters/RosterSessionEditor.vue',
    'resources/js/admin/components/rosters/RosterDaySchedule.vue',
    'resources/js/admin/components/rosters/RosterStaffingDemand.vue',
    'resources/js/admin/components/rosters/RosterValidationSummary.vue',
    'resources/js/admin/components/rosters/RosterScheduleMatrix.vue',
    'resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue',
    'resources/js/admin/views/DashboardRostersView.vue',
    'resources/js/admin/views/DashboardRosterDetailView.vue',
    'resources/js/admin/services/dashboardRosterApi.ts',
    'resources/js/admin/services/dashboardRosterGenerationApi.ts',
    'resources/js/admin/types/roster.ts',
    'resources/js/admin/types/admin.ts',
];
$content = '';

foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: {$file} ontbreekt.\n");
        exit(1);
    }
    $content .= file_get_contents($path);
}

foreach ([
    'name: "rosters"',
    'name: "roster-detail"',
    'to="/roosters"',
    'Rooster maken / openen',
    '/api/admin/rosters/create.php',
    '/api/admin/rosters/index.php',
    '/api/admin/rosters/show.php',
    '/api/admin/rosters/session-save.php',
    '/api/admin/rosters/session-delete.php',
    'rosterPlanCsrfToken',
    'rosterSessionCsrfToken',
    'Programmadekking',
    'Sessie toevoegen',
    'maxParallel',
    'planning.sessions',
    'RosterAutoGeneratorPanel',
    'RosterDaySchedule',
    'Dagrooster',
    'Programmadekking per groep',
    'Personeelsbehoefte per tijdvak',
    'Conceptrooster-wizard',
    'Bekijken',
    'Beheren',
    'Handmatige correcties',
    'Roosterstatus',
    'Dagindeling',
    'Aankomst & welkom',
    'Afscheid & vertrek',
    'Dagrooster',
    'Personeel',
    'Controle',
    'minimumGeoFortStaff',
    'schoolSupervisionAllowed',
    '/api/admin/rosters/generation-preview.php',
    '/api/admin/rosters/generation-apply.php',
    'Automatisch voorstel maken',
    'minimumSimultaneousGeoFortStaff',
] as $needle) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "FAIL: roosterfrontendcontract mist {$needle}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: roster frontend contract passed.\n");
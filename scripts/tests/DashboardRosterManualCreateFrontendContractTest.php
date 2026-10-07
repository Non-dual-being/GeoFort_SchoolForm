<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$createView = file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$api = file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);
$types = file_get_contents(
    $root . '/resources/js/admin/types/roster.ts',
);

foreach ([
    'AdminWizardStepper',
    'Basis → Programma → Dagindeling → Personeel → Genereren',
    'fetchBookingProgramConfigValues',
    'manualSectorOptions',
    'manualProgramOptions',
    'suggestedGroupCount',
    'Gebruik advies',
    'Verder naar Programma',
    'createDashboardManualRoster',
    'source: "manual"',
    'DashboardRosterManualCreateRequest',
] as $needle) {
    if (
        !str_contains($createView, $needle)
        && !str_contains($api, $needle)
        && !str_contains($types, $needle)
    ) {
        fwrite(STDERR, "FAIL: V8B2A basiswizard mist {$needle}.\n");
        exit(1);
    }
}

foreach ([
    'schoolName',
    'city',
    'visitDate',
    'sector',
    'program',
    'studentCount',
    'groupCount',
] as $field) {
    if (!str_contains($types, $field)) {
        fwrite(STDERR, "FAIL: handmatige create request mist {$field}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: manual roster V8B2A basis wizard frontend contract passed.\n");

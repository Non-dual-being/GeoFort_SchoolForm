<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardRosterCreateView.vue');
$api = (string) file_get_contents($root . '/resources/js/admin/services/dashboardRosterApi.ts');
$types = (string) file_get_contents($root . '/resources/js/admin/types/roster.ts');

$assertContains = static function (string $haystack, string $needle, string $message): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

foreach ([
    'manualStep = ref<ManualWizardStep>("basis")',
    'Lesprogramma samenstellen',
    'return config.modules[sector]?.[program] ?? null;',
    'manualStandardModuleKeys',
    'manualChoiceModuleKeys',
    'moveManualModule(index, -1)',
    'removeManualModule(moduleKey)',
    'MANUAL_MIN_MODULE_COUNT = 2',
    'MANUAL_MAX_MODULE_COUNT = 5',
    'MANUAL_GROUPS_PER_MODULE = 2',
    'manualModuleCapacity',
    'manualRequiredModuleCount',
    'Verder naar Programma',
    'Programma opslaan en verder',
    '@select="selectManualStep"',
] as $needle) {
    $assertContains($view, $needle, 'V8B2B Programma-wizard mist frontendgedrag.');
}

$assertContains(
    $api,
    '/api/admin/rosters/manual-program-save.php',
    'Programma-save API-client ontbreekt.',
);
$assertContains(
    $types,
    'DashboardRosterManualProgramSaveRequest',
    'Programma-save requesttype ontbreekt.',
);
$assertContains(
    $types,
    'moduleKeys: string[];',
    'Programma-save request moet de geordende modulekeys dragen.',
);

fwrite(STDOUT, "OK: manual roster V8B2B Programma frontend contract passed.\n");

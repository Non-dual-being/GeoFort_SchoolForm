<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$detail = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterDetailView.vue',
);
$create = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$api = (string) file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'convertDashboardRosterToCustom',
    'async function convertToCustom(): Promise<void>',
    'canConvertToCustom',
    'canEditCustomProgram',
    'Omzetten naar maatwerk',
    'query: { plan: String(result.plan.id) }',
    'Programma en dagindeling wijzigen',
] as $needle) {
    $assert(
        str_contains($detail, $needle),
        "D3C3 detail flow missing: {$needle}",
    );
}

foreach ([
    'useRoute',
    'fetchDashboardRoster',
    'const editingPlanId = computed<number | null>',
    'function hydrateExistingCustomPlan(plan: DashboardRosterPlan): void',
    'async function loadExistingCustomPlan(): Promise<void>',
    'plan.planningMode !== "custom"',
    'manualPlan.value = plan',
    'manualModuleKeys.value = plan.planning.modules.map',
    'manualRounds.value = cloneManualRounds(plan.planning.rounds)',
    'manualProgramSaved.value = false',
    'manualStep.value = "program"',
    'if (step === 1 && !editingExistingPlan.value)',
    '() => route.query.plan',
    'Maatwerkrooster bewerken',
    'er wordt geen tweede rooster aangemaakt',
] as $needle) {
    $assert(
        str_contains($create, $needle),
        "D3C4 wizard flow missing: {$needle}",
    );
}

$assert(
    !str_contains(
        $api,
        'DashboardRosterConvertToCustomRequest,   DashboardRosterConvertToCustomResponse,',
    ),
    'D3C2 conversion type imports are still collapsed on one line.',
);

fwrite(
    STDOUT,
    "OK: D3C3-D3C4 conversion wizard frontend contract passed.\n",
);
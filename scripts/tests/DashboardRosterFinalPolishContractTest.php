<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$create = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$generator = (string) file_get_contents(
    $root . '/resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue',
);
$manual = (string) file_get_contents(
    $root . '/resources/js/admin/components/rosters/RosterManualGrid.vue',
);
$teacher = (string) file_get_contents(
    $root . '/resources/js/admin/components/rosters/RosterTeacherSchedule.vue',
);
$backend = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterAutoGenerator.php',
);

foreach ([
    'aligned source cards and yellow maatwerk',
    'grid-template-rows: auto auto minmax(0, 1fr) auto;',
    'background: #fff8d9;',
    'color: #574300;',
] as $needle) {
    if (!str_contains($create, $needle)) {
        throw new RuntimeException("Final card polish missing: {$needle}");
    }
}

if (str_contains($create, "\u{00C2}\u{00B7}")) {
    throw new RuntimeException('Mojibake middle dot is still present in create view.');
}

foreach ([
    'v-if="staffingAdvice.hasVi"',
    'Geen Voedsel Innovatie geselecteerd. Kokplanning is niet van toepassing',
    'if (!staffingAdvice.value.hasVi) return null;',
    'if (hasVi && cookStaffId.value !== null)',
] as $needle) {
    if (!str_contains($generator, $needle)) {
        throw new RuntimeException("VI/cook wizard UX missing: {$needle}");
    }
}

foreach ([
    'const staffGroupId = ref<number | null>(null);',
    'groupStaffAssignments',
    'staffAssignmentsForGroup',
    'const hasVi = computed(() =>',
    'const cookCatalog = computed(() =>',
    'const cookLegend = computed(() =>',
    'if (!hasVi.value) return null;',
    "cellEditorMode === 'full' && hasVi",
    'cookStaffId: hasVi.value ? cookStaffId.value : null',
    'function openExisting(',
    'function openNew(',
    'function closeEditor(',
    'async function save()',
    '<aside v-if="selectedSlot"',
    '<Pencil',
] as $needle) {
    if (!str_contains($manual, $needle)) {
        throw new RuntimeException("Manual roster restore/polish missing: {$needle}");
    }
}

foreach ([
    'const hasViSessions = computed(() =>',
    'if (!hasViSessions.value && props.staffingSettings.cookStaffId !== null)',
    'wanted.delete(staleCook.id);',
    'if (hasViSessions.value && props.staffingSettings.cookStaffId !== null)',
] as $needle) {
    if (!str_contains($teacher, $needle)) {
        throw new RuntimeException("Teacher no-VI behavior missing: {$needle}");
    }
}

foreach ([
    '$hasViInputModule = in_array(',
    'if (!$hasViInputModule)',
    'if (($proposal[\'staffing\'][\'cookRequired\'] ?? false) !== true)',
] as $needle) {
    if (!str_contains($backend, $needle)) {
        throw new RuntimeException("Backend no-VI normalization missing: {$needle}");
    }
}

fwrite(STDOUT, "OK: D3B final visual + no-VI cook polish contract passed.\n");
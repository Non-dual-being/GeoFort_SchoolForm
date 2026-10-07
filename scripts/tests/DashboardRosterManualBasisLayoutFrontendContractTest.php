<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);

$assertContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

$assertNotContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Onverwacht aanwezig: {$needle}");
    }
};

$assertNotContains(
    $view,
    '<p class="admin-eyebrow">Stap 1</p>',
    'Stap 1 mag niet dubbel onder de wizard worden herhaald.',
);
$assertNotContains(
    $view,
    '<h3>Basis</h3>',
    'Basis mag niet dubbel onder de wizard worden herhaald.',
);
$assertContains(
    $view,
    'admin-roster-create__manual-meta',
    'De subtiele handmatig-statusrij ontbreekt.',
);
$assertContains(
    $view,
    'admin-roster-create__manual-field admin-roster-create__manual-wide',
    'Schoolnaam moet als een samenhangend veldblok worden opgebouwd.',
);
$assertContains(
    $view,
    'admin-roster-create__manual-grid :deep(.admin-field)',
    'Alle handmatige velden moeten dezelfde flex-veldstructuur krijgen.',
);
$assertContains(
    $view,
    'align-items: start;',
    'De twee kolommen moeten aan de bovenzijde worden uitgelijnd.',
);
$assertContains(
    $view,
    'min-height: 3.25rem;',
    'De controls moeten in de handmatige basis dezelfde hoogte krijgen.',
);
$assertContains(
    $view,
    'min-height: 1.35rem;',
    'De labels moeten een vaste uitlijnhoogte krijgen.',
);
$assertContains(
    $view,
    'manualDisabledDateReasons',
    'De bestaande datumhelperlogica moet onaangeraakt blijven.',
);
$assertContains(
    $view,
    'createDashboardManualRoster',
    'De bestaande V8B2A create-flow moet onaangeraakt blijven.',
);

fwrite(
    STDOUT,
    "OK: manual roster V8B2A3 basis grid polish frontend contract passed.\n",
);

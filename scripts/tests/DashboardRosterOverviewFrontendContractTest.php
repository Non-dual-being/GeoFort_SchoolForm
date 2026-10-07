<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'resources/js/admin/router/index.ts',
    'resources/js/admin/views/DashboardRostersView.vue',
    'resources/js/admin/views/DashboardRosterCreateView.vue',
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
    'name: "roster-create"',
    'path: "/roosters/nieuw"',
    'Rooster maken',
    'Personeel & vaardigheden',
    'aria-label="Roosters filteren"',
    'label="Bron"',
    'Vanuit aanvraag',
    'Handmatig',
    'admin-bookings-table__row-link',
    '@click="openRoster(item)"',
    '@keydown="handleRosterKeydown($event, item)"',
    'Aanvraag kiezen',
    'Handmatig rooster',
    'Rooster maken / openen',
    'Basis → Programma → Dagindeling → Personeel → Genereren',
] as $needle) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "FAIL: V8A rooster-overzichtcontract mist {$needle}.\n");
        exit(1);
    }
}

if (str_contains(
    file_get_contents($root . '/resources/js/admin/views/DashboardRostersView.vue'),
    'Naar aanvragen',
)) {
    fwrite(STDERR, "FAIL: rooster-overzicht bevat nog de oude navigatieknop Naar aanvragen.\n");
    exit(1);
}

fwrite(STDOUT, "OK: roster V8A overview + create navigation frontend contract passed.\n");

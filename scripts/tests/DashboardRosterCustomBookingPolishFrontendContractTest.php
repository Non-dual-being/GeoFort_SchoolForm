<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$rosters = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRostersView.vue',
);
$baseCss = (string) file_get_contents(
    $root . '/resources/css/admin/base.css',
);
$rostersCss = (string) file_get_contents(
    $root . '/resources/css/admin/rosters.css',
);

foreach ([
    'withoutRoster: bookingPickerPurpose.value === "custom"',
    'admin-roster-create__link-toolbar',
    'admin-roster-create__link-toolbar-actions',
    'admin-roster-create__link-notice',
    'admin-roster-create__booking-search',
    'admin-status--roster-custom',
    'Koppelen <span aria-hidden="true">&rarr;</span>',
    'Rooster op maat maken',
] as $needle) {
    if (!str_contains($view, $needle)) {
        throw new RuntimeException("D3B polish view missing: {$needle}");
    }
}

if (str_contains($view, 'Gebruiken als startpunt ->')) {
    throw new RuntimeException('Old custom picker action label is still present.');
}

if (substr_count($rosters, 'admin-button admin-button--primary') < 2) {
    throw new RuntimeException('Roster create links are not explicit primary buttons.');
}

foreach ([
    'never use browser default visited purple',
    ':where(#admin-app) a:visited',
] as $needle) {
    if (!str_contains($baseCss, $needle)) {
        throw new RuntimeException("Global admin link guard missing: {$needle}");
    }
}

if (!str_contains(
    $rostersCss,
    'roster button links keep their button text colors',
)) {
    throw new RuntimeException('Roster button link color guard missing.');
}

fwrite(STDOUT, "OK: D3B polish frontend contract passed.\n");
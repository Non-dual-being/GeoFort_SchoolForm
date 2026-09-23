<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'resources/js/admin/router/index.ts',
    'resources/js/admin/views/DashboardRostersView.vue',
    'resources/js/admin/views/DashboardRosterStaffView.vue',
    'resources/js/admin/services/dashboardRosterStaffApi.ts',
    'resources/js/admin/types/rosterStaff.ts',
    'public/api/admin/rosters/staff/index.php',
    'src/Services/Http/Api/Admin/DashboardRosterStaffListAction.php',
];

$content = '';
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) throw new RuntimeException("{$file} ontbreekt.");
    $content .= file_get_contents($path);
}

foreach ([
    'roster-staff',
    'Personeel & vaardigheden',
    '/api/admin/rosters/staff/index.php',
    'RosterStaffMember',
    'DashboardRosterStaffListAction',
    'PrivatePageBootstrapper',
    'Kosttarieven',
] as $needle) {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException("Personeelsfrontendcontract mist {$needle}.");
    }
}

fwrite(STDOUT, "OK: roster staff frontend contract passed.\n");
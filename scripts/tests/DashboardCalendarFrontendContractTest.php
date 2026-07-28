<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/router/index.ts',
    'resources/js/admin/views/DashboardCalendarView.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDayCell.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDayDetail.vue',
    'resources/js/admin/services/dashboardCalendarApi.ts',
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
    'name: "calendar"',
    '/api/admin/calendar/month.php',
    'DashboardCalendarDayCell',
    'DashboardCalendarDayDetail',
    "name: 'booking-detail'",
    'Dit overzicht is alleen-lezen.',
] as $needle) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "FAIL: frontendcontract mist {$needle}.\n");
        exit(1);
    }
}

if (preg_match('/block-date|release-date|method:\s*"POST"/', $content) === 1) {
    fwrite(STDERR, "FAIL: frontend bevat een kalender-mutatieflow.\n");
    exit(1);
}

exit(0);

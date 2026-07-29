<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/router/index.ts',
    'resources/js/admin/views/DashboardCalendarView.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDayCell.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDayDetail.vue',
    'resources/js/admin/components/calendar/DashboardCalendarViewSelector.vue',
    'resources/js/admin/components/calendar/DashboardCalendarActionCard.vue',
    'resources/js/admin/components/help/AdminCollapsibleHelp.vue',
    'resources/js/admin/composables/useDisclosure.ts',
    'resources/js/admin/services/dashboardCalendarApi.ts',
    'resources/js/admin/services/dashboardCalendarDateManagementApi.ts',
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
    'beheer handmatige blokkades.',
    'DashboardCalendarDateManagementDialog',
    'Agenda weergave',
    'type="radio"',
    'is-in-range',
    'Beschikbare datums',
    'day.bookingCount === 0 && day.canBlockManually',
    'day.disabledType === "manual" && day.canReleaseManualBlock',
    'Weekend',
    'Niet beschikbaar voor onderwijsbezoeken.',
    'Schoolvakantie',
    'if (day.disabledType === "manual") return "Niet beschikbaar"',
    'if (day.disabledType === "school_vacation") return "Vakantie"',
    'if (isWeekend(day) || day.disabledType === "manual" || day.isPast) return null',
    'Selectie wissen',
    '<p class="admin-eyebrow">Actie</p>',
    '/api/admin/calendar/manage-date.php',
] as $needle) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "FAIL: frontendcontract mist {$needle}.\n");
        exit(1);
    }
}

if (str_contains($content, '<h2 id="calendar-actions-title">Beschikbare acties</h2>')) {
    fwrite(STDERR, "FAIL: losse heading Beschikbare acties is niet verwijderd.\n");
    exit(1);
}

exit(0);

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/views/DashboardCalendarView.vue',
    'resources/js/admin/components/calendar/DashboardCalendarModeSwitch.vue',
    'resources/js/admin/components/calendar/DashboardCalendarOverview.vue',
    'resources/js/admin/components/calendar/DashboardCalendarOverviewDayCell.vue',
    'resources/js/admin/services/dashboardCalendarOverviewApi.ts',
    'resources/js/admin/types/dashboardCalendarOverview.ts',
    'resources/css/admin/calendar.css',
];
$content = '';
foreach ($files as $file) { if (!is_file($root . '/' . $file)) { fwrite(STDERR, "FAIL: {$file} ontbreekt.\n"); exit(1); } $content .= file_get_contents($root . '/' . $file); }
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
foreach (['aria-pressed','defineAsyncComponent','<KeepAlive>','route.query.mode === "management"','/api/admin/calendar/overview.php','Alle programma’s','Alle statussen','Filters herstellen','Geen boekingen','Geen resultaten','Bestaande planning','minimaal ','role="grid"','role="gridcell"','aria-busy','aria-live','Opnieuw proberen','onDeactivated','AbortController','calendarOverviewCacheKey','invalidateDashboardCalendarOverviewCache'] as $needle) $assert(str_contains($content, $needle), "Frontendcontract mist {$needle}.");
$assert(!str_contains($content, 'schoolName') && !str_contains($content, 'bookingId'), 'Overviewfrontend bevat individuele bookingvelden.');
$assert(str_contains($content, 'v-for="day in calendar.days"'), 'Kalenderdagen staan niet rechtstreeks en chronologisch in het overviewgrid.');
$assert(str_contains($content, 'grid-template-columns: repeat(7, minmax(0, 1fr));'), 'Kalendergrid bevat niet exact zeven vaste kolommen.');
$assert(!str_contains($content, 'calendarWeeks') && !str_contains($content, 'admin-calendar-overview__week'), 'Overview bevat een oude geneste weekstructuur.');
exit(0);

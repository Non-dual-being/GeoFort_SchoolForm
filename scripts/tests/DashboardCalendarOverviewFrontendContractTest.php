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
    'resources/js/admin/utils/calendarOverviewPresentation.ts',
    'resources/css/admin/calendar.css',
    'resources/css/admin/bookings.css',
];
$content = '';
foreach ($files as $file) { if (!is_file($root . '/' . $file)) { fwrite(STDERR, "FAIL: {$file} ontbreekt.\n"); exit(1); } $content .= file_get_contents($root . '/' . $file); }
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
foreach (['aria-pressed','defineAsyncComponent','<KeepAlive>','route.query.mode === "management"','/api/admin/calendar/overview.php','Alle programma’s','Alle statussen','Filters herstellen','Geen boekingen','Geen resultaten','Bekijk boeking','Bekijk boekingen','Boekingen op deze dag','aria-haspopup="dialog"','minimaal ','role="grid"','role="gridcell"','aria-busy','aria-live','Opnieuw proberen','onDeactivated','AbortController','calendarOverviewCacheKey','invalidateDashboardCalendarOverviewCache'] as $needle) $assert(str_contains($content, $needle), "Frontendcontract mist {$needle}.");
$assert(!str_contains($content, 'Bestaande planning'), 'Overbodige planningsaanduiding staat nog in de interface.');
$assert(!str_contains($content, 'schoolName') && !str_contains($content, 'bookingId'), 'Overviewfrontend bevat individuele bookingvelden.');
$assert(str_contains($content, 'v-for="day in calendar.days"'), 'Kalenderdagen staan niet rechtstreeks en chronologisch in het overviewgrid.');
$assert(str_contains($content, 'grid-template-columns: repeat(7, minmax(0, 1fr));'), 'Kalendergrid bevat niet exact zeven vaste kolommen.');
$assert(!str_contains($content, 'calendarWeeks') && !str_contains($content, 'admin-calendar-overview__week'), 'Overview bevat een oude geneste weekstructuur.');
foreach (['has-status-confirmed','has-status-option','has-status-rejected','has-single-booking','has-multiple-bookings','admin-calendar-overview-day__status-badge','admin-calendar-overview-day__student-badge','is-occupancy-empty','is-occupancy-low','is-occupancy-medium','is-occupancy-high','is-occupancy-full-or-over'] as $needle) $assert(str_contains($content, $needle), "Status- of capaciteitscontract mist {$needle}.");
$assert(str_contains($content, 'selectedStatus?.label') && str_contains($content, '{{ totalBookings }}'), 'Statusbadge gebruikt niet het centrale label en werkelijke aantal.');
$assert(str_contains($content, '{{ invalid ? "minimaal " : "" }}{{ students }} leerlingen') && str_contains($content, 'zonder geldig aantal'), 'Leerlingbadge bewaart minimum- en datakwaliteitsinformatie niet.');
$assert(str_contains($content, '!props.day.disabled') && str_contains($content, 'totalBookings.value > 0'), 'Geblokkeerde dagen of lege resultaten kunnen een accent krijgen.');
$assert(str_contains($content, 'v-if="statusFilter === \'all\'"') && str_contains($content, 'v-for="item in grouped"'), 'Alle statussen behoudt de bestaande breakdown niet.');
$assert(str_contains($content, 'ten opzichte van de geconfigureerde capaciteit'), 'Aria-informatie bevat geen veilige capaciteitssamenvatting.');
$assert(!preg_match('/(?:effectiveCapacity|capacity)[^\n]*(?:80|160)/i', $content), 'Overviewfrontend bevat een hardcoded businesscapaciteit.');
$assert(preg_match('/\.has-status-confirmed \.admin-calendar-overview-day__student-badge\s*\{[^}]*background:\s*var\(--admin-surface\);[^}]*box-shadow:\s*none;/s', $content) === 1, 'Definitief-leerlingbadges behouden niet één rustige achtergrond zonder schaduw.');
exit(0);

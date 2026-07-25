<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$panel = file_get_contents($root . '/resources/js/admin/components/bookings/BookingVisitDatePanel.vue');
$service = file_get_contents($root . '/resources/js/admin/services/dashboardBookingVisitDateCalendarApi.ts');
$sql = file_get_contents($root . '/src/Services/Sql/BookingCalendarSqlService.php');
$compact = preg_replace('/\\s+/', '', $panel);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {fwrite(STDERR, "FAIL: {$message}\n");$failures++;}
};

$assert(str_contains($panel, 'role="grid"') && str_contains($panel, 'role="gridcell"') && str_contains($panel, ':aria-label="ariaLabel(day)"'), 'Kalender mist grid- of dagsemantiek.');
$assert(str_contains($panel, '@focus="focusedDate=day.date"') && str_contains($panel, 'admin-booking-calendar__detail'), 'Focusdetail/touchdetail ontbreekt.');
$assert(str_contains($panel, 'ArrowLeft') && str_contains($panel, 'ArrowRight') && str_contains($panel, 'Enter'), 'Toetsenbordbediening ontbreekt.');
$assert(str_contains($panel, ':disabled="!day.selectable"') && str_contains($panel, 'if(day.selectable)proposed.value=day.date'), 'Geblokkeerde/selecteerbare semantiek ontbreekt.');
$assert(str_contains($panel, 'cache=new Map') && str_contains($panel, 'pending=new Map'), 'Rangecache of requestdeduplicatie ontbreekt.');
$assert(str_contains($panel, '<AdminDateField') && str_contains($panel, 'calendarError'), 'Herstelbare fallback naar bestaand datumveld ontbreekt.');
$assert(str_contains($compact, 'expected:{visitDate:props.visitDate}') && str_contains($compact, 'proposed:{visitDate:proposed.value}'), 'Expected en proposed datum zijn niet gescheiden.');
$assert(str_contains($service, 'get-booking-visit-date-calendar.php'), 'Frontend gebruikt het booking-specifieke endpoint niet.');
$assert(str_contains($sql, 'GROUP BY bezoekdatum, programma') && str_contains($sql, 'id <> :excludeBookingId'), 'Rangequery groepeert niet of sluit huidige booking niet uit.');

exit($failures === 0 ? 0 : 1);

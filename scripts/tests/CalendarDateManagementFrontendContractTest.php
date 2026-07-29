<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'resources/js/admin/views/DashboardCalendarView.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDayDetail.vue',
    'resources/js/admin/components/calendar/DashboardCalendarDateManagementDialog.vue',
    'resources/js/admin/services/dashboardCalendarDateManagementApi.ts',
    'resources/js/admin/components/feedback/AdminDialog.vue',
];
$content = '';
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} ontbreekt.\n"); exit(1); }
    $content .= (string) file_get_contents($path);
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
foreach ([
    'active-bookings', 'available-management', 'manually-blocked',
    'block_single', 'block_period', 'release_single', 'release_period',
    'date-management-preview.php', 'manage-date.php', 'calendarDateManagementCsrfToken',
    'previewSequence', 'requestSequence', 'CALENDAR_DATE_CONFLICT', 'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED',
    'close-on-escape', 'aria-live', "name: 'booking-detail'",
    'reasonMinLength.value', 'reasonMaxLength.value', 'maxPeriodDays.value', 'Europe/Amsterdam',
] as $needle) $assert(str_contains($content, $needle), "Frontendcontract mist {$needle}.");
$assert(!str_contains($content, "status !== 'Afgewezen'"), 'Frontend dupliceert de actieve-statusdefinitie.');
$assert(!str_contains($content, 'update-booking') && !str_contains($content, 'sendMail'), 'Kalenderfrontend start een booking- of mailmutatie.');
exit(0);

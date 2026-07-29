<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'public/api/admin/calendar/manage-date.php',
    'public/api/admin/calendar/date-management-preview.php',
    'src/Services/Http/Api/Admin/DashboardCalendarDateManagementAction.php',
    'src/Services/Http/Api/Admin/DashboardCalendarDateManagementPreviewAction.php',
    'src/Services/Http/Api/Admin/CalendarDateManagementRequest.php',
    'src/Services/Http/Api/Admin/CalendarDateManagementPreviewRequest.php',
    'src/Dashboard/Calendar/CalendarDateManagementPolicy.php',
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
    "CSRF_SCOPE = 'manage-calendar-date'", 'SessionGuard', "method !== 'POST'", 'application\\/json',
    'HTTP_X_CSRF_TOKEN', 'INVALID_CALENDAR_DATE_ACTION', 'CALENDAR_DATE_CONFLICT',
    'block_single', 'block_period', 'release_single', 'release_period', 'previewFingerprint', "'issues'",
] as $needle) $assert(str_contains($content, $needle), "HTTP-contract mist {$needle}.");
$assert(str_contains($content, 'exactKeys'), 'Mutatie-DTO weigert onbekende velden niet strikt.');
$assert(str_contains($content, "keys !== ['action', 'endDate', 'startDate']"), 'Preview-DTO weigert onbekende velden niet strikt.');
$assert(preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $content) !== 1, 'Endpoint/action bevat SQL.');
exit(0);

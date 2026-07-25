<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$action = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingVisitDateCalendarAction.php');
$endpoint = file_get_contents($root . '/public/api/admin/requests/get-booking-visit-date-calendar.php');
$bootstrap = file_get_contents($root . '/bootstrap.php');
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {fwrite(STDERR, "FAIL: {$message}\n");$failures++;}
};

$assert(str_contains($action, '$this->privatePageBootstrapper->init()'), 'Endpoint gebruikt private adminauth niet.');
$assert(str_contains($action, "\$method !== 'GET'") && str_contains($action, "header('Allow', 'GET')"), 'Endpoint is niet strikt read-only GET.');
$assert(!preg_match('/\\b(INSERT|UPDATE|DELETE|audit|mail|FOR UPDATE)\\b/i', $action), 'HTTP-action bevat mutatie-, audit-, mail- of lockgedrag.');
$assert(str_contains($endpoint, 'DashboardBookingVisitDateCalendarAction'), 'Publiek endpoint routeert niet naar de kalenderaction.');
$assert(str_contains($bootstrap, 'DashboardBookingVisitDateCalendarAction::class'), 'Kalenderaction is niet in bootstrap geregistreerd.');

exit($failures === 0 ? 0 : 1);

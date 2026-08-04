<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/public/api/admin/calendar/month.php');
$action = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardCalendarAction.php');
$service = file_get_contents($root . '/src/Services/Dashboard/Calendar/DashboardCalendarService.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($endpoint, 'DashboardCalendarAction'), 'Endpoint routeert niet naar de agenda-action.');
$assert(str_contains($action, "\$method !== 'GET'"), 'Agenda-action dwingt GET niet af.');
$assert(str_contains($action, 'PrivatePageBootstrapper'), 'Agenda-action vereist geen privésessie.');
$assert(!str_contains($action . $service, 'CsrfTokenService'), 'Read-endpoint vraagt onnodig een CSRF-token.');
$assert(preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $service) !== 1, 'Agenda-readservice bevat muterende SQL.');
$assert(str_contains($service, 'BookingPolicy::STATUS_OPTION'), 'Optiestatus wordt niet centraal afgeleid.');
$assert(str_contains($service, 'BookingPolicy::STATUS_CONFIRMED'), 'Definitieve status wordt niet centraal afgeleid.');
$assert(str_contains($service, 'PolicyCapacityLimitProvider'), 'Centrale capaciteitsprovider ontbreekt.');
$assert(str_contains($service, "'disabledType' =>"), 'Agenda-DTO levert het blokkadetype niet.');
$assert(str_contains($service, "'disabledReason' =>"), 'Agenda-DTO levert de blokkadereden niet.');
$assert(str_contains($service, "'canBlockManually' => !\$isPast && \$disabled === null && \$hasAvailableProgram"), 'Agenda-DTO markeert weekend, vakantie, verleden of blokkades mogelijk als beheerbaar.');
$assert(str_contains($service, "'canReleasePlannerBlock' => !\$isPast && \$plannerManaged") && str_contains($service, 'CalendarDateManagementPolicy::isReleasable'), 'Vrijgeven is niet beperkt tot toekomstige toegestane blokkades.');
$assert(str_contains($service, "'disabledSource' =>"), 'Agenda-DTO levert provenance niet.');

exit(0);

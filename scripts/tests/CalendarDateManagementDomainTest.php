<?php
declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use GeoFort\Services\Http\Api\Admin\CalendarDateManagementPreviewRequest;
use GeoFort\Services\Http\Api\Admin\CalendarDateManagementRequest;
use GeoFort\Services\Http\Api\Admin\CalendarDateManagementRequestException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); $failures++; }
};
$payload = static fn (array $overrides = []): string => json_encode(array_replace_recursive([
    'expected' => [
        'startDate' => '2026-09-24',
        'endDate' => '2026-09-24',
        'previewFingerprint' => str_repeat('a', 64),
        'activeBookingsFingerprint' => str_repeat('b', 64),
    ],
    'proposed' => [
        'action' => 'block_single',
        'disabledType' => 'manual',
        'reason' => ' Onderhoud ',
        'confirmed' => true,
        'existingBookingsAccepted' => true,
    ],
], $overrides), JSON_THROW_ON_ERROR);

$request = CalendarDateManagementRequest::fromJson($payload());
$assert($request->startDate === '2026-09-24' && $request->action === 'block_single', 'Geldige single-blockrequest wordt niet geaccepteerd.');
$preview = CalendarDateManagementPreviewRequest::fromJson('{"startDate":"2026-09-24","endDate":"2026-09-30","action":"block_period","disabledType":"school_vacation"}');
$assert($preview->action === 'block_period', 'Geldige periodepreview wordt niet geaccepteerd.');
$oneDayPreview = CalendarDateManagementPreviewRequest::fromJson('{"startDate":"2026-09-24","endDate":"2026-09-24","action":"block_period","disabledType":"manual"}');
$assert($oneDayPreview->startDate === $oneDayPreview->endDate, 'Een periodepreview van één datum wordt niet geaccepteerd.');
$assert(CalendarDateManagementPolicy::MAX_PERIOD_DAYS === 93, 'De centrale periodegrens is niet 93 dagen.');
$assert(BookingPolicy::ACTIVE_STATUSES === ['In optie', 'Definitief'], 'De centrale actieve-statusdefinitie wijkt af.');

foreach ([
    ['{', 'INVALID_REQUEST'],
    [$payload(['expected' => ['previewFingerprint' => 'wrong']]), 'INVALID_REQUEST'],
    [$payload(['proposed' => ['action' => 'unknown']]), 'INVALID_CALENDAR_DATE_ACTION'],
    [$payload(['proposed' => ['disabledType' => 'weekend']]), 'INVALID_DISABLED_DATE_TYPE'],
    [$payload(['proposed' => ['disabledType' => 'unknown']]), 'INVALID_DISABLED_DATE_TYPE'],
    [json_encode(['expected' => []], JSON_THROW_ON_ERROR), 'INVALID_REQUEST'],
] as [$json, $code]) {
    try {
        CalendarDateManagementRequest::fromJson($json);
        $assert(false, "{$code} wordt niet geweigerd.");
    } catch (CalendarDateManagementRequestException $exception) {
        $assert($exception->publicCode === $code, "Verkeerde foutcode voor {$code}.");
    }
}

$service = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Dashboard/Calendar/CalendarDateManagementService.php');
$previewService = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Dashboard/Calendar/CalendarDateManagementPreviewService.php');
$repository = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Sql/CalendarDateManagementSqlRepository.php');
$submission = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Booking/Submission/BookingSubmissionService.php');
$visitDateChange = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Booking/VisitDate/BookingVisitDateChangeService.php');
$migration = (string) file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-07-28_create_calendar_date_change_history.sql');
$provenanceMigration = (string) file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-07-30_add_disabled_date_provenance_and_audit_types.sql');
$generatedDates = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Sql/DisabledDatesSqlService.php');

foreach (['lockDates($lockDates)', 'hash_equals', 'ACTIVE_BOOKINGS_IN_PERIOD', 'existingBookingsAccepted', 'insertAudit', 'NO_CHANGE', 'NO_ELIGIBLE_DATES'] as $needle) {
    $assert(str_contains($service, $needle), "Mutatieservicecontract mist {$needle}.");
}
foreach (['weekendDates', 'existingPlannerDates', 'otherBlockedDates', 'activeBookingDates', "hash('sha256'", "'disabledType' => \$disabledType"] as $needle) {
    $assert(str_contains($previewService, $needle), "Previewcontract mist {$needle}.");
}
$assert(str_contains($repository, 'BookingPolicy::ACTIVE_STATUSES'), 'Repository gebruikt niet de centrale actieve statussen.');
$assert(str_contains($repository, 'ORDER BY bezoekdatum, id') && str_contains($repository, 'FOR UPDATE'), 'Boekingen worden niet deterministisch vergrendeld.');
$assert(str_contains($repository, 'AND source = :source'), 'Vrijgave is niet beperkt tot plannerrecords.');
$assert(str_contains($repository, 'type_before') && str_contains($repository, 'type_after'), 'Audit legt typen vóór en na niet vast.');
$assert(!str_contains($previewService, 'Gebruik een single-actie voor één datum en een periodeactie voor meerdere datums.'), 'Verouderde single/period-validatie is nog aanwezig.');
$assert(preg_match('/\b(UPDATE|DELETE)\s+aanvragen\b/i', $service . $repository) !== 1, 'Kalendermutatie wijzigt aanvragen.');
$assert(!str_contains($service . $repository, 'status_history') && !str_contains($service . $repository, 'sendMail'), 'Kalendermutatie raakt mail of status-history.');
$assert(str_contains($submission, 'lockDate($request->bezoekdatum)'), 'Publieke aanvraag gebruikt de datumlock niet.');
$assert(str_contains($visitDateChange, 'lockDates([$snapshot->visitDate,$command->proposedVisitDate])'), 'Bezoekdatummutatie lockt bron en doel niet vóór de boeking.');
$assert(str_contains($migration, 'calendar_date_change_history_dates') && str_contains($migration, "scope ENUM('single', 'period')"), 'Auditmigratie ondersteunt geen operatieheader met children.');
$assert(str_contains($provenanceMigration, "ENUM('generated', 'planner')") && str_contains($provenanceMigration, 'type_before') && str_contains($provenanceMigration, 'type_after'), 'Provenance- of typed-auditmigratie ontbreekt.');
$assert(str_contains($generatedDates, "IF(source = 'planner'"), 'Generatiesynchronisatie beschermt plannerrecords niet.');

exit($failures === 0 ? 0 : 1);

<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Calendar;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Dashboard\Calendar\CalendarDateManagementIssue;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPreview;
use GeoFort\Dashboard\Calendar\CalendarDateManagementResult;
use GeoFort\Services\Sql\CalendarDateManagementSqlRepository;
use JsonException;

final readonly class CalendarDateManagementPreviewService
{
    private const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(private CalendarDateManagementSqlRepository $dates) {}

    public function preview(
        string $action,
        string $startDate,
        string $endDate,
        ?string $disabledType = null,
        ?DateTimeImmutable $today = null,
        bool $forUpdate = false,
    ): CalendarDateManagementResult {
        $validation = $this->validate($action, $startDate, $endDate, $disabledType, $today);
        if ($validation !== null) return $validation;

        $dateObjects = $this->datesInRange($startDate, $endDate);
        $disabled = $this->dates->findDisabledDates($startDate, $endDate, $forUpdate);
        $active = $this->dates->findActiveBookings($startDate, $endDate, $forUpdate);
        $weekends = [];
        $planner = [];
        $other = [];
        $eligible = [];
        $activeDates = [];
        foreach ($active['bookings'] as $booking) $activeDates[$booking['date']] = true;

        foreach ($dateObjects as $date) {
            $ymd = $date->format('Y-m-d');
            if ((int) $date->format('N') >= 6) {
                $weekends[] = $ymd;
                continue;
            }
            $stored = $disabled[$ymd] ?? null;
            if ($stored !== null && CalendarDateManagementPolicy::isReleasable($stored['type'], $stored['source'])) {
                $planner[] = ['date' => $ymd, 'type' => $stored['type'], 'reason' => $stored['reason'], 'source' => $stored['source']];
                continue;
            }
            if ($stored !== null) {
                $other[] = ['date' => $ymd, 'type' => $stored['type'], 'reason' => $stored['reason']];
                continue;
            }
            if (str_starts_with($action, 'block_')) $eligible[] = $ymd;
        }

        $relevantBookings = array_values(array_filter(
            $active['bookings'],
            static fn (array $booking): bool => (int) (new DateTimeImmutable($booking['date']))->format('N') < 6,
        ));
        $activeDateList = array_values(array_unique(array_column($relevantBookings, 'date')));
        sort($activeDateList, SORT_STRING);
        $affected = str_starts_with($action, 'release_')
            ? array_column($planner, 'date')
            : $eligible;
        $categories = [
            'affectedDates' => $affected,
            'affectedTypeCounts' => [
                CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE => count(array_filter($planner, static fn (array $item): bool => $item['type'] === CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE)),
                CalendarDateManagementPolicy::SCHOOL_VACATION_BLOCK_TYPE => count(array_filter($planner, static fn (array $item): bool => $item['type'] === CalendarDateManagementPolicy::SCHOOL_VACATION_BLOCK_TYPE)),
            ],
            'weekendDates' => $weekends,
            'existingPlannerDates' => $planner,
            'otherBlockedDates' => $other,
            'activeBookingDates' => $activeDateList,
            'activeBookings' => $relevantBookings,
        ];
        $bookingCount = count($relevantBookings);
        $studentCount = array_sum(array_map(static fn (array $booking): int => $booking['studentCount'] ?? 0, $relevantBookings));
        $fingerprintCategories = $categories;
        $fingerprintCategories['activeBookingDates'] = [];
        $fingerprintCategories['activeBookings'] = [];
        $snapshot = [
            'version' => 1,
            'action' => $action,
            'disabledType' => $disabledType,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'categories' => $fingerprintCategories,
            'bookingCount' => 0,
            'studentCount' => 0,
        ];
        try {
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $activeBookingsFingerprint = hash('sha256', json_encode([
                'version' => 1,
                'activeBookings' => $relevantBookings,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return $this->failure('DATABASE_ERROR', $startDate, $endDate, 'request', 'Preview mislukt', 'De kalenderpreview kon niet worden opgebouwd.');
        }
        $preview = new CalendarDateManagementPreview(
            $action,
            $startDate,
            $endDate,
            count($dateObjects),
            $categories,
            $bookingCount,
            $studentCount,
            $fingerprint,
            $activeBookingsFingerprint,
        );
        return new CalendarDateManagementResult('SUCCESS', true, $startDate, $endDate, count($affected), $preview);
    }

    private function validate(
        string $action,
        string $startDate,
        string $endDate,
        ?string $disabledType,
        ?DateTimeImmutable $today,
    ): ?CalendarDateManagementResult
    {
        if (!in_array($action, CalendarDateManagementPolicy::ACTIONS, true)) {
            return $this->failure('INVALID_CALENDAR_DATE_ACTION', $startDate, $endDate, 'action', 'Ongeldige actie', 'Kies een ondersteunde kalenderbeheeractie.');
        }
        if ((str_starts_with($action, 'block_')
                && !in_array($disabledType, CalendarDateManagementPolicy::MANAGEABLE_TYPES, true))
            || (str_starts_with($action, 'release_') && $disabledType !== null)) {
            return $this->failure('INVALID_DISABLED_DATE_TYPE', $startDate, $endDate, 'disabledType', 'Ongeldig blokkadetype', 'Kies Vakantie of Anders.');
        }
        $zone = new DateTimeZone(self::TIMEZONE);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $zone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $zone);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $end < $start) {
            return $this->failure('INVALID_CALENDAR_DATE', $startDate, $endDate, 'dateRange', 'Ongeldige periode', 'Gebruik een geldige begin- en einddatum in oplopende volgorde.');
        }
        $today = ($today ?? new DateTimeImmutable('today', $zone))->setTime(0, 0);
        if ($start < $today) {
            return $this->failure('CALENDAR_DATE_IN_PAST', $startDate, $endDate, 'startDate', 'Datum ligt in het verleden', 'Datums in het verleden kunnen niet via dit beheer worden gewijzigd.');
        }
        $dayCount = (int) $start->diff($end)->days + 1;
        if ($dayCount > CalendarDateManagementPolicy::MAX_PERIOD_DAYS) {
            return $this->failure('CALENDAR_DATE_PERIOD_TOO_LONG', $startDate, $endDate, 'endDate', 'Periode is te lang', 'Een periode mag maximaal ' . CalendarDateManagementPolicy::MAX_PERIOD_DAYS . ' kalenderdagen bevatten.');
        }
        $periodAction = str_ends_with($action, '_period');
        if (!$periodAction && $startDate !== $endDate) {
            return $this->failure('INVALID_CALENDAR_DATE_ACTION', $startDate, $endDate, 'action', 'Actie past niet bij selectie', 'Een actie voor één datum vereist gelijke begin- en einddatums.');
        }
        return null;
    }

    /** @return list<DateTimeImmutable> */
    private function datesInRange(string $startDate, string $endDate): array
    {
        $start = new DateTimeImmutable($startDate);
        $endExclusive = (new DateTimeImmutable($endDate))->add(new DateInterval('P1D'));
        return iterator_to_array(new DatePeriod($start, new DateInterval('P1D'), $endExclusive), false);
    }

    private function failure(string $code, string $startDate, string $endDate, string $field, string $title, string $description): CalendarDateManagementResult
    {
        return new CalendarDateManagementResult($code, false, $startDate, $endDate, issues: [
            new CalendarDateManagementIssue($code, $field, $title, $description),
        ]);
    }
}

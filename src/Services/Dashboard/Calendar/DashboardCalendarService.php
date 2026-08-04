<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Calendar;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\EffectiveDayCapacityResolver;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\DashboardCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;

final readonly class DashboardCalendarService
{
    public function __construct(
        private DashboardCalendarSqlService $bookings,
        private BookingCalendarSqlService $capacity,
        private BookingDaySettingsSqlRepository $daySettings,
        private DisabledDatesSqlService $disabledDates,
        private PolicyCapacityLimitProvider $capacityLimits = new PolicyCapacityLimitProvider(),
        private EffectiveDayCapacityResolver $capacityResolver = new EffectiveDayCapacityResolver(),
    ) {}

    /** @return array{startDate: string, endDate: string, days: list<array<string, mixed>>} */
    public function get(string $startDate, string $endDate, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $bookingsByDate = $this->bookings->findBookingsByDateForRange($startDate, $endDate);
        $capacityByDate = $this->capacity->getBookingStatsByDateForRange($startDate, $endDate);
        $settingsByDate = $this->daySettings->findForRange($startDate, $endDate);
        $disabledByDate = [];

        foreach ($this->disabledDates->getDisabledDatesDetailed($startDate, $endDate) as $disabledDate) {
            $disabledByDate[(string) $disabledDate['datum']] = $disabledDate;
        }

        $days = [];
        $endExclusive = (new DateTimeImmutable($endDate))->add(new DateInterval('P1D'));

        foreach (new DatePeriod(new DateTimeImmutable($startDate), new DateInterval('P1D'), $endExclusive) as $date) {
            $ymd = $date->format('Y-m-d');
            $bookings = $bookingsByDate[$ymd] ?? [];
            $capacity = $capacityByDate[$ymd] ?? ['bookedSchools' => 0, 'bookedStudents' => 0, 'programStudents' => []];
            $standard = $this->capacityLimits->forDate($ymd);
            $setting = $settingsByDate[$ymd] ?? null;
            $limits = $this->capacityResolver->resolve($standard, $setting);
            $maximumCapacity = $limits->effectiveMaxStudents;
            $maximumSchools = $limits->effectiveMaxSchools;
            $disabled = $disabledByDate[$ymd] ?? null;
            $isPast = $date < $today;
            $hasAvailableProgram = $this->hasAvailableProgramOnWeekday((int) $date->format('N'));
            $manuallyBlocked = is_array($disabled) && (string) $disabled['type'] === 'manual';
            $plannerManaged = is_array($disabled) && CalendarDateManagementPolicy::isReleasable(
                (string) $disabled['type'],
                (string) $disabled['source'],
            );

            $optionCount = 0;
            $confirmedCount = 0;
            $otherCount = 0;
            $studentCount = 0;
            $programs = [];
            $bookingItems = [];

            foreach ($bookings as $booking) {
                if ($booking['status'] === BookingPolicy::STATUS_OPTION) {
                    $optionCount++;
                } elseif ($booking['status'] === BookingPolicy::STATUS_CONFIRMED) {
                    $confirmedCount++;
                } else {
                    $otherCount++;
                }

                if (BookingPolicy::isActiveStatus($booking['status'])) {
                    $studentCount += $booking['studentCount'] ?? 0;
                    if (BookingPolicy::isAllowedProgram($booking['program'])) {
                        $programs[$booking['program']] = BookingPolicy::getProgramLabel($booking['program']);
                    }
                }

                $bookingItems[] = [
                    ...$booking,
                    'active' => BookingPolicy::isActiveStatus($booking['status']),
                    'programLabel' => BookingPolicy::isAllowedProgram($booking['program'])
                        ? BookingPolicy::getProgramLabel($booking['program'])
                        : $booking['program'],
                ];
            }

            $warnings = [];
            if ($optionCount > 0) {
                $warnings[] = $this->warning('OPTION_BOOKINGS', 'Voorlopige boekingen', "{$optionCount} boeking(en) in optie reserveren nog geen capaciteit.");
            }
            if ($otherCount > 0) {
                $warnings[] = $this->warning('OTHER_BOOKINGS', 'Overige boekingen', "{$otherCount} afgewezen boeking(en) zijn alleen ter informatie zichtbaar.");
            }
            if ($capacity['bookedSchools'] > $maximumSchools) {
                $warnings[] = $this->warning('SCHOOL_LIMIT_EXCEEDED', 'Schoolcapaciteit overschreden', 'Er staan meer definitieve scholen dan de geldende daglimiet.');
            }
            if ($capacity['bookedStudents'] > $maximumCapacity) {
                $warnings[] = $this->warning('STUDENT_LIMIT_EXCEEDED', 'Leerlingcapaciteit overschreden', 'Het definitieve leerlingtotaal ligt boven de geldende daglimiet.');
            }
            foreach ($capacity['programStudents'] as $program => $programStudents) {
                if (BookingPolicy::isAllowedProgram($program) && $programStudents > BookingPolicy::getMaxStudentsOfProgram($program)) {
                    $warnings[] = $this->warning('PROGRAM_STUDENT_LIMIT_EXCEEDED', 'Programmacapaciteit overschreden', BookingPolicy::getProgramLabel($program) . ' ligt boven de centrale programmagrens.');
                }
            }

            $remainingCapacity = max(0, $maximumCapacity - $capacity['bookedStudents']);
            $state = 'available';
            if ($isPast) {
                $state = 'past';
            } elseif ($disabled !== null || !$hasAvailableProgram) {
                $state = 'blocked';
            } elseif ($remainingCapacity === 0 || $capacity['bookedSchools'] >= $maximumSchools) {
                $state = 'full';
            } elseif ($capacity['bookedStudents'] > 0 || $optionCount > 0) {
                $state = 'limited';
            }

            $days[] = [
                'date' => $ymd,
                'weekday' => (int) $date->format('N'),
                'isPast' => $isPast,
                'manuallyBlocked' => $manuallyBlocked,
                'manualBlockReason' => $manuallyBlocked ? ($disabled['reden'] ?? null) : null,
                'disabledType' => is_array($disabled) ? (string) $disabled['type'] : null,
                'disabledSource' => is_array($disabled) ? (string) $disabled['source'] : null,
                'canBlockManually' => !$isPast && $disabled === null && $hasAvailableProgram,
                'canReleaseManualBlock' => !$isPast && $manuallyBlocked,
                'canReleasePlannerBlock' => !$isPast && $plannerManaged,
                'bookingCount' => $optionCount + $confirmedCount,
                'optionBookingCount' => $optionCount,
                'confirmedBookingCount' => $confirmedCount,
                'otherBookingCount' => $otherCount,
                'studentCount' => $studentCount,
                'confirmedStudentCount' => $capacity['bookedStudents'],
                'maximumCapacity' => $maximumCapacity,
                'remainingCapacity' => $remainingCapacity,
                'programs' => array_values($programs),
                'state' => $state,
                'warnings' => $warnings,
                'bookings' => $bookingItems,
                'disabledReason' => is_array($disabled) ? ($disabled['reden'] ?? null) : null,
            ];
        }

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'managementPolicy' => [
                'reasonMinLength' => CalendarDateManagementPolicy::MIN_REASON_LENGTH,
                'reasonMaxLength' => CalendarDateManagementPolicy::MAX_REASON_LENGTH,
                'maxPeriodDays' => CalendarDateManagementPolicy::MAX_PERIOD_DAYS,
            ],
            'days' => $days,
        ];
    }

    /** @return array{code: string, title: string, description: string} */
    private function warning(string $code, string $title, string $description): array
    {
        return compact('code', 'title', 'description');
    }

    private function hasAvailableProgramOnWeekday(int $weekday): bool
    {
        foreach (BookingProgramConfig::PROGRAMS as $program) {
            if (in_array($weekday, $program['allowedWeekdays'], true)) {
                return true;
            }
        }

        return false;
    }
}

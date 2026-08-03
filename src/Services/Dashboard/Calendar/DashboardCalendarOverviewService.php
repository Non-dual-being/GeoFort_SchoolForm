<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Calendar;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Dashboard\Calendar\CalendarOverviewAggregate;
use GeoFort\Dashboard\Calendar\CalendarOverviewDay;
use GeoFort\Dashboard\Calendar\CalendarOverviewDisabledDate;
use GeoFort\Dashboard\Calendar\CalendarOverviewResponse;
use GeoFort\Services\Sql\DashboardCalendarOverviewSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

final readonly class DashboardCalendarOverviewService
{
    public const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(
        private DashboardCalendarOverviewSqlRepository $bookings,
        private DisabledDatesSqlService $disabledDates,
    ) {}

    public function get(int $year, int $month, ?DateTimeImmutable $now = null): CalendarOverviewResponse
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone);
        $monthEnd = $monthStart->modify('last day of this month');
        $gridStart = $monthStart->modify('monday this week');
        $gridEnd = $gridStart->add(new DateInterval('P41D'));
        $aggregatesByDate = $this->bookings->aggregateForRange($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
        $disabledByDate = [];
        foreach ($this->disabledDates->getDisabledDatesDetailed($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')) as $disabled) {
            $disabledByDate[(string) $disabled['datum']] = $disabled;
        }

        $days = [];
        $endExclusive = $gridEnd->add(new DateInterval('P1D'));
        foreach (new DatePeriod($gridStart, new DateInterval('P1D'), $endExclusive) as $date) {
            $ymd = $date->format('Y-m-d');
            $rawAggregates = $aggregatesByDate[$ymd] ?? [];
            $aggregates = array_map(static fn (array $aggregate): CalendarOverviewAggregate => new CalendarOverviewAggregate(
                $aggregate['program'], $aggregate['status'], $aggregate['bookingCount'], $aggregate['studentCount'],
                $aggregate['unknownStudentCount'], $aggregate['invalidStudentCount'],
            ), $rawAggregates);
            $disabled = $disabledByDate[$ymd] ?? null;
            $days[] = new CalendarOverviewDay(
                date: $ymd,
                weekday: (int) $date->format('N'),
                inSelectedMonth: $date >= $monthStart && $date <= $monthEnd,
                isPast: $ymd < $now->format('Y-m-d'),
                isToday: $ymd === $now->format('Y-m-d'),
                isBookableWeekday: $this->isBookableWeekday((int) $date->format('N')),
                disabled: is_array($disabled) ? new CalendarOverviewDisabledDate(
                    (string) $disabled['type'],
                    (string) $disabled['source'],
                    $this->disabledLabel((string) $disabled['type']),
                ) : null,
                hasExcludedBookingsOnBlockedDate: $disabled !== null && $rawAggregates !== [],
                aggregates: $aggregates,
            );
        }

        return new CalendarOverviewResponse(
            period: [
                'year' => $year, 'month' => $month,
                'monthStart' => $monthStart->format('Y-m-d'), 'monthEnd' => $monthEnd->format('Y-m-d'),
                'gridStart' => $gridStart->format('Y-m-d'), 'gridEnd' => $gridEnd->format('Y-m-d'),
            ],
            generatedAt: $now->format(DATE_ATOM),
            timezone: self::TIMEZONE,
            filters: [
                'programs' => array_map(static fn (string $value, array $config): array => ['value' => $value, 'label' => $config['label']], array_keys(BookingProgramConfig::PROGRAMS), array_values(BookingProgramConfig::PROGRAMS)),
                'statuses' => [
                    ['value' => BookingPolicy::STATUS_OPTION, 'label' => BookingPolicy::STATUS_OPTION, 'shortLabel' => 'Optie', 'presentation' => 'option'],
                    ['value' => BookingPolicy::STATUS_CONFIRMED, 'label' => BookingPolicy::STATUS_CONFIRMED, 'shortLabel' => 'Def.', 'presentation' => 'confirmed'],
                    ['value' => BookingPolicy::STATUS_REJECTED, 'label' => BookingPolicy::STATUS_REJECTED, 'shortLabel' => 'Afgew.', 'presentation' => 'rejected'],
                ],
            ],
            capacity: [
                'totalDaily' => BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY,
                'programs' => BookingPolicy::MAX_STUDENTS_PER_DAY_PROGRAM,
            ],
            days: $days,
        );
    }

    private function isBookableWeekday(int $weekday): bool
    {
        foreach (BookingProgramConfig::PROGRAMS as $program) {
            if (in_array($weekday, $program['allowedWeekdays'], true)) return true;
        }
        return false;
    }

    private function disabledLabel(string $type): string
    {
        return match ($type) {
            'school_vacation' => 'Vakantie',
            'weekend' => 'Weekend',
            default => 'Geblokkeerd',
        };
    }
}

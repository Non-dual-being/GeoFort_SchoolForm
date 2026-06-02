<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Availability;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\SchoolVacationConfig;

final class DisabledDateGenerator
{
    private DateTimeZone $timezone;

    public function __construct()
    {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    /**
     * @return array<int, array{datum: string, type: string, reden: string|null}>
     */
    public function generateUntilEndSchoolYear2027(): array
    {
        $today = new DateTimeImmutable('today', $this->timezone);
        $end = new DateTimeImmutable('2027-08-29', $this->timezone);

        $dates = [];

        foreach ($this->generateWeekends($today, $end) as $date) {
            $dates[$date] = [
                'datum' => $date,
                'type' => 'weekend',
                'reden' => 'Weekend',
            ];
        }

        foreach (SchoolVacationConfig::rangesUntilEndSchoolYear2027() as $range) {
            foreach ($this->expandRange($range['start'], $range['end']) as $date) {
                $dates[$date] = [
                    'datum' => $date,
                    'type' => 'school_vacation',
                    'reden' => $range['reason'],
                ];
            }
        }

        ksort($dates);

        return array_values($dates);
    }

    /**
     * @return array<int, string>
     */
    private function generateWeekends(
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $dates = [];

        foreach ($this->period($start, $end) as $date) {
            $dayNumber = (int) $date->format('N');

            if ($dayNumber === 6 || $dayNumber === 7) {
                $dates[] = $date->format('Y-m-d'); // important to format, cuz the array key needs a string as index not a datetime object
            }
        }

        return $dates;
    }

    /**
     * @return array<int, string>
     */
    private function expandRange(string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start, $this->timezone);
        $endDate = new DateTimeImmutable($end, $this->timezone);

        $dates = [];

        foreach ($this->period($startDate, $endDate) as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @return DatePeriod<DateTimeImmutable>
     */
    private function period(
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): DatePeriod {
        return new DatePeriod(
            $start,
            new DateInterval('P1D'),
            $end->modify('+1 day')
        );
    }

    /**
     * returns a DatePeriode object containing the dates in format 2026-05-01
     * This Object is itearabel, but not a array
     */
}
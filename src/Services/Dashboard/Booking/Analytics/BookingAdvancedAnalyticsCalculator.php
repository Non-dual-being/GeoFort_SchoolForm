<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\EffectiveDayCapacityResolver;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

final readonly class BookingAdvancedAnalyticsCalculator
{
    private const TIMEZONE = 'Europe/Amsterdam';
    private const MONTH_LABELS = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

    public function __construct(
        private DisabledDatesSqlService $disabledDates,
        private BookingDaySettingsSqlRepository $daySettings,
        private EffectiveDayCapacityResolver $capacityResolver = new EffectiveDayCapacityResolver(),
        private PolicyCapacityLimitProvider $capacityProvider = new PolicyCapacityLimitProvider(),
    ) {}

    /** @param list<array<string, mixed>> $bookings @return array{newSchoolsByMonth:list<array<string,mixed>>,capacityByMonth:list<array<string,mixed>>} */
    public function calculate(array $bookings, BookingAnalyticsCriteria $criteria): array
    {
        $months = $this->months($criteria->requestedStartDate, $criteria->requestedEndDate);
        $newSchools = array_fill_keys(array_keys($months), 0);
        $seen = [];
        foreach ($bookings as $booking) {
            $key = $this->schoolKey($booking);
            if ($key === null || isset($seen[$key])) continue;
            $seen[$key] = true;
            $newSchools[substr((string) $booking['visit_date'], 0, 7)]++;
        }

        $actual = [];
        foreach ($bookings as $booking) {
            $month = substr((string) $booking['visit_date'], 0, 7);
            $actual[$month]['students'] = ($actual[$month]['students'] ?? 0) + max(0, is_int($booking['students']) ? $booking['students'] : 0);
            $actual[$month]['bookings'] = ($actual[$month]['bookings'] ?? 0) + 1;
        }
        $disabled = array_fill_keys($this->disabledDates->getDisabledDateStrings($criteria->requestedStartDate, $criteria->requestedEndDate), true);
        $settings = $this->daySettings->findForRange($criteria->requestedStartDate, $criteria->requestedEndDate);
        foreach ($this->dates($criteria->requestedStartDate, $criteria->requestedEndDate) as $date) {
            $ymd = $date->format('Y-m-d');
            if (isset($disabled[$ymd]) || !$this->programAllowedOnWeekday($criteria->program, (int) $date->format('N'))) continue;
            $month = substr($ymd, 0, 7);
            $capacity = $this->capacityResolver->resolve($this->capacityProvider->forDate($ymd), $settings[$ymd] ?? null);
            $months[$month]['availableDays']++;
            $months[$month]['studentCapacity'] += $criteria->program === BookingPolicy::PROGRAM_MORNING
                ? min($capacity->effectiveMaxStudents, BookingPolicy::getMaxStudentsOfProgram(BookingPolicy::PROGRAM_MORNING))
                : $capacity->effectiveMaxStudents;
            $months[$month]['bookingCapacity'] += $capacity->effectiveMaxSchools;
        }

        return [
            'newSchoolsByMonth' => array_map(fn (string $month): array => ['month' => $month, 'label' => $this->label($month), 'count' => $newSchools[$month]], array_keys($months)),
            'capacityByMonth' => array_map(function (string $month) use ($months, $actual): array {
                $students = $actual[$month]['students'] ?? 0;
                $bookings = $actual[$month]['bookings'] ?? 0;
                return ['month' => $month, 'label' => $this->label($month), 'availableDays' => $months[$month]['availableDays'],
                    'students' => ['actual' => $students, 'capacity' => $months[$month]['studentCapacity'], 'percentage' => $this->percentage($students, $months[$month]['studentCapacity'])],
                    'bookingSlots' => ['actual' => $bookings, 'capacity' => $months[$month]['bookingCapacity'], 'percentage' => $this->percentage($bookings, $months[$month]['bookingCapacity'])]];
            }, array_keys($months)),
        ];
    }

    /** @return array<string,array{availableDays:int,studentCapacity:int,bookingCapacity:int}> */
    private function months(string $start, string $end): array
    {
        $result = [];
        foreach ($this->dates(substr($start, 0, 7) . '-01', $end) as $date) {
            $key = $date->format('Y-m');
            $result[$key] ??= ['availableDays' => 0, 'studentCapacity' => 0, 'bookingCapacity' => 0];
        }
        return $result;
    }

    /** @return DatePeriod<DateTimeImmutable> */
    private function dates(string $start, string $end): DatePeriod
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        return new DatePeriod(new DateTimeImmutable($start, $timezone), new DateInterval('P1D'), (new DateTimeImmutable($end, $timezone))->modify('+1 day'));
    }

    private function programAllowedOnWeekday(string $program, int $weekday): bool
    {
        if ($program === 'all') {
            foreach (BookingProgramConfig::PROGRAMS as $config) if (in_array($weekday, $config['allowedWeekdays'], true)) return true;
            return false;
        }
        return in_array($weekday, BookingProgramConfig::PROGRAMS[$program]['allowedWeekdays'] ?? [], true);
    }

    /** @param array<string,mixed> $row */
    private function schoolKey(array $row): ?string
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8')) ?? '';
        $parts = [$normalize((string) $row['school']), $normalize((string) $row['address']), preg_replace('/\s+/u', '', $normalize((string) $row['postal_code'])) ?? '', $normalize((string) $row['city']), $normalize((string) $row['country'])];
        return $parts[0] === '' ? null : implode('|', $parts);
    }

    private function percentage(int $actual, int $capacity): ?float { return $capacity > 0 ? ($actual / $capacity) * 100 : null; }
    private function label(string $month): string
    {
        return self::MONTH_LABELS[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
    }
}

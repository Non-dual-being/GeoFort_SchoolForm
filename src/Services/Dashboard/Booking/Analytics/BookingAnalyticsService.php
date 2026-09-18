<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Sql\BookingAnalyticsRepository;
use GeoFort\Services\Sql\BookingExportSqlRepository;

final readonly class BookingAnalyticsService
{
    private const MONTHS = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    private const WEEKDAYS = [1 => 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];

    public function __construct(
        private BookingAnalyticsRepository $repository,
        private BookingExportSqlRepository $dateBoundsRepository,
        private BookingAnalyticsDeepAnalyzer $deepAnalyzer = new BookingAnalyticsDeepAnalyzer(),
        private ?BookingAdvancedAnalyticsCalculator $advancedCalculator = null,
        private ?CapacityTargetAnalyticsCalculator $targetCalculator = null,
    ) {}

    public function bounds(): BookingExportDateBounds
    {
        return $this->dateBoundsRepository->findDateBounds();
    }

    public function analyze(BookingAnalyticsCriteria $criteria): BookingAnalyticsResult
    {
        $periodBookings = array_values(array_filter(
            $this->repository->bookings($criteria),
            static fn (array $booking): bool => $criteria->program === 'all' || $booking['program'] === $criteria->program,
        ));
        $capacityScopeBookings = match ($criteria->population) {
            'confirmed' => array_values(array_filter($periodBookings, static fn (array $booking): bool => $booking['status'] === BookingPolicy::STATUS_CONFIRMED)),
            'all' => $periodBookings,
            default => array_values(array_filter($periodBookings, static fn (array $booking): bool => BookingPolicy::isActiveStatus($booking['status']))),
        };
        $selected = array_values(array_filter(
            $capacityScopeBookings,
            static fn (array $booking): bool => $criteria->sector === 'all' || $booking['sector'] === $criteria->sector,
        ));
        $total = count($selected);
        $confirmed = $this->countStatus($selected, BookingPolicy::STATUS_CONFIRMED);
        $option = $this->countStatus($selected, BookingPolicy::STATUS_OPTION);
        $rejected = $this->countStatus($selected, BookingPolicy::STATUS_REJECTED);
        $students = $this->sumStudents($selected);
        $schools = count(array_unique(array_map(
            static fn (array $booking): string => mb_strtolower($booking['school']),
            array_filter($selected, static fn (array $booking): bool => $booking['school'] !== ''),
        )));
        $days = count(array_unique(array_column($selected, 'visit_date')));

        $advanced = $this->advancedCalculator?->calculate($selected, $criteria, $capacityScopeBookings) ?? [
            'newSchoolsByMonth' => [], 'capacityByMonth' => [],
            'capacityDaySnapshots' => [], 'analyticsToday' => (new DateTimeImmutable())->format('Y-m-d'),
        ];
        $targetAnalytics = $this->targetCalculator?->calculate(
            $advanced['capacityByMonth'],
            $advanced['capacityDaySnapshots'],
            $advanced['analyticsToday'],
        ) ?? [
            'capacityTargetByMonth' => [],
            'capacityTargetContext' => [
                'status' => 'unavailable', 'today' => $advanced['analyticsToday'], 'timezone' => 'Europe/Amsterdam',
                'canManage' => false, 'currentOfficialTarget' => null, 'history' => [], 'daySnapshots' => [],
            ],
        ];
        return new BookingAnalyticsResult(
            new BookingAnalyticsSummary(
                count($selected),
                $confirmed,
                $option,
                $rejected,
                $students,
                $schools,
                $days,
                $this->average($students, count($selected)),
                $this->average($students, $days),
                $this->percentage($rejected, $total),
            ),
            $this->monthly($selected),
            $this->sectors($selected),
            $this->programs($selected),
            $this->modules($selected),
            $this->composition($selected, $criteria),
            $this->weekdays($selected),
            $this->busiestDays($selected),
            $this->deepAnalyzer->analyze($selected, $criteria),
            $advanced['newSchoolsByMonth'],
            $advanced['capacityByMonth'],
            $targetAnalytics['capacityTargetByMonth'],
            $targetAnalytics['capacityTargetContext'],
        );
    }

    /** @param list<array<string, mixed>> $bookings */
    private function countStatus(array $bookings, string $status): int
    {
        return count(array_filter($bookings, static fn (array $booking): bool => $booking['status'] === $status));
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function monthly(array $active): array
    {
        $groups = [];
        foreach ($active as $booking) {
            $key = substr($booking['visit_date'], 0, 7);
            $groups[$key][] = $booking;
        }
        ksort($groups);
        $rows = [];
        foreach ($groups as $key => $bookings) {
            $month = (int) substr($key, 5, 2);
            $students = $this->sumStudents($bookings);
            $rows[] = [
                'month' => self::MONTHS[$month] . ' ' . substr($key, 0, 4),
                'activeBookings' => count($bookings),
                'confirmedBookings' => $this->countStatus($bookings, BookingPolicy::STATUS_CONFIRMED),
                'plannedStudents' => $students,
                'uniqueSchools' => count(array_unique(array_column($bookings, 'school'))),
                'uniqueVisitDays' => count(array_unique(array_column($bookings, 'visit_date'))),
                'averageStudents' => $this->average($students, count($bookings)),
            ];
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function sectors(array $active): array
    {
        $rows = [];
        $totalStudents = $this->sumStudents($active);
        foreach (BookingProgramConfig::SCHOOL_TYPES_BY_KEY as $key => $config) {
            $bookings = array_values(array_filter($active, static fn (array $row): bool => $row['sector'] === $key));
            $students = $this->sumStudents($bookings);
            $rows[] = [
                'label' => $config['label'],
                'activeBookings' => count($bookings),
                'plannedStudents' => $students,
                'bookingShare' => $this->percentage(count($bookings), count($active)),
                'studentShare' => $this->percentage($students, $totalStudents),
                'averageStudents' => $this->average($students, count($bookings)),
            ];
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function programs(array $active): array
    {
        $rows = [];
        foreach (BookingPolicy::PROGRAM_LABELS as $key => $label) {
            $bookings = array_values(array_filter($active, static fn (array $row): bool => $row['program'] === $key));
            $students = $this->sumStudents($bookings);
            $rows[] = [
                'label' => $label,
                'activeBookings' => count($bookings),
                'plannedStudents' => $students,
                'bookingShare' => $this->percentage(count($bookings), count($active)),
                'averageStudents' => $this->average($students, count($bookings)),
                'capacityUtilization' => $this->percentage($students, count($bookings) * (BookingPolicy::MAX_STUDENTS_PER_DAY_PROGRAM[$key] ?? 1)),
            ];
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function modules(array $active): array
    {
        $eligible = array_values(array_filter(
            $active,
            static fn (array $row): bool => $row['program'] === BookingPolicy::PROGRAM_DAY,
        ));
        $grouped = [];
        foreach ($eligible as $booking) {
            if ($booking['module'] === null || !isset(BookingProgramConfig::MODULE_LABELS[$booking['module']])) continue;
            $grouped[$booking['module']][] = $booking;
        }
        $rows = [];
        foreach ($grouped as $key => $bookings) {
            $rows[] = [
                'label' => BookingProgramConfig::MODULE_LABELS[$key],
                'activeBookings' => count($bookings),
                'students' => $this->sumStudents($bookings),
                'populationShare' => $this->percentage(count($bookings), count($eligible)),
                'eligibleBookings' => count($eligible),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['activeBookings'] <=> $a['activeBookings'] ?: $a['label'] <=> $b['label']);
        return $rows;
    }

    /** @return array<string, mixed> */
    private function composition(array $selected, BookingAnalyticsCriteria $criteria): array
    {
        $selectedIds = array_fill_keys(array_column($selected, 'id'), true);
        $rows = array_values(array_filter($this->repository->composition($criteria), static fn (array $row): bool => isset($selectedIds[$row['booking_id']])));
        $vo = array_values(array_filter($rows, static fn (array $row): bool => $row['sector'] !== 'primairOnderwijs'));
        $groups = array_column($rows, 'group_count');
        return [
            'selectionBookings' => count($rows),
            'voBookings' => count($vo),
            'oneLevel' => count(array_filter($vo, static fn (array $row): bool => $row['level_count'] === 1)),
            'multipleLevels' => count(array_filter($vo, static fn (array $row): bool => $row['level_count'] >= 2)),
            'oneGroup' => count(array_filter($groups, static fn (int $count): bool => $count === 1)),
            'twoGroups' => count(array_filter($groups, static fn (int $count): bool => $count === 2)),
            'threeOrMoreGroups' => count(array_filter($groups, static fn (int $count): bool => $count >= 3)),
            'averageGroups' => $this->average(array_sum($groups), count($rows)),
            'levelPopulationLabel' => 'VO-aanvragen met onderwijsselecties',
        ];
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function weekdays(array $active): array
    {
        $rows = [];
        foreach (self::WEEKDAYS as $number => $label) {
            $bookings = array_values(array_filter($active, static fn (array $row): bool => (int) (new DateTimeImmutable($row['visit_date']))->format('N') === $number));
            $students = $this->sumStudents($bookings);
            $days = count(array_unique(array_column($bookings, 'visit_date')));
            $rows[] = [
                'weekday' => $label,
                'uniqueVisitDays' => $days,
                'activeBookings' => count($bookings),
                'plannedStudents' => $students,
                'averageStudentsPerVisitDay' => $this->average($students, $days),
            ];
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $active @return list<array<string, int|float|string>> */
    private function busiestDays(array $active): array
    {
        $groups = [];
        foreach ($active as $booking) $groups[$booking['visit_date']][] = $booking;
        $rows = [];
        foreach ($groups as $date => $bookings) {
            $programs = array_unique(array_map(
                static fn (array $row): string => BookingPolicy::PROGRAM_LABELS[$row['program']] ?? 'Onbekend programma',
                $bookings,
            ));
            $rows[] = [
                'date' => $date,
                'uniqueSchools' => count(array_unique(array_column($bookings, 'school'))),
                'activeBookings' => count($bookings),
                'plannedStudents' => $this->sumStudents($bookings),
                'programs' => implode(' en ', $programs),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['plannedStudents'] <=> $a['plannedStudents'] ?: $a['date'] <=> $b['date']);
        return array_slice($rows, 0, 10);
    }

    private function average(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 1) : 0.0;
    }

    private function percentage(int $numerator, int $denominator): int
    {
        return $denominator > 0 ? (int) round(($numerator / $denominator) * 100) : 0;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumStudents(array $rows): int
    {
        return array_sum(array_map(static fn (array $row): int => is_int($row['students']) ? max(0, $row['students']) : 0, $rows));
    }
}

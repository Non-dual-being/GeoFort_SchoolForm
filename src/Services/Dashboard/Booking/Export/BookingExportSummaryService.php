<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\BookingExportSqlRepository;

final readonly class BookingExportSummaryService
{
    public function __construct(private BookingExportSqlRepository $repository) {}

    public function bounds(): BookingExportDateBounds
    {
        return $this->repository->findDateBounds();
    }

    /** @return array<string, mixed> */
    public function summarize(BookingExportCriteria $criteria): array
    {
        $values = $this->repository->summarize($criteria);
        $composition = $this->repository->summarizeComposition($criteria);
        $moduleRows = $this->repository->summarizeChoiceModules($criteria);
        $activeBookings = (int) $values['active_bookings'];
        $eligibleModuleBookings = (int) $values['day_program_bookings'];
        return [
            'requestStats' => [
                'total' => $values['total_bookings'],
                'status' => [
                    'confirmed' => $values['confirmed_bookings'],
                    'option' => $values['option_bookings'],
                    'rejected' => $values['rejected_bookings'],
                    'other' => $values['other_bookings'],
                ],
                'uniqueSchools' => $values['unique_schools'],
                'visitDays' => $values['visit_days'],
            ],
            'studentStats' => [
                'totalInSelection' => $values['total_students'],
                'planned' => $values['planned_students'],
                'confirmed' => $values['confirmed_students'],
                'option' => $values['option_students'],
                'averagePerActiveBooking' => $values['average_active_students'],
                'sectors' => [
                    $this->sector('primairOnderwijs', (int) $values['primary_students']),
                    $this->sector('voortgezetOnderbouw', (int) $values['lower_secondary_students']),
                    $this->sector('voortgezetBovenbouw', (int) $values['upper_secondary_students']),
                ],
            ],
            'programStats' => [
                'activeBookings' => $activeBookings,
                'day' => $this->program(
                    BookingPolicy::PROGRAM_DAY,
                    (int) $values['day_program_bookings'],
                    (int) $values['day_program_students'],
                    (float) $values['average_day_students'],
                    $activeBookings,
                ),
                'morning' => $this->program(
                    BookingPolicy::PROGRAM_MORNING,
                    (int) $values['morning_program_bookings'],
                    (int) $values['morning_program_students'],
                    (float) $values['average_morning_students'],
                    $activeBookings,
                ),
                'maximumStudentsOneVisitDay' => $values['maximum_students_one_day'],
                'choiceModules' => [
                    'eligibleBookings' => $eligibleModuleBookings,
                    'recordedChoices' => array_sum(array_column($moduleRows, 'bookings')),
                    'distribution' => $this->moduleDistribution($moduleRows, $eligibleModuleBookings),
                ],
            ],
            'compositionStats' => [
                'selectionBookings' => $composition['composition_bookings'],
                'voBookings' => $composition['vo_composition_bookings'],
                'levels' => [
                    'one' => $this->distributionItem(
                        (int) $composition['vo_one_level'],
                        (int) $composition['vo_composition_bookings'],
                    ),
                    'multiple' => $this->distributionItem(
                        (int) $composition['vo_multiple_levels'],
                        (int) $composition['vo_composition_bookings'],
                    ),
                ],
                'groups' => [
                    'one' => $this->distributionItem(
                        (int) $composition['one_group'],
                        (int) $composition['composition_bookings'],
                    ),
                    'two' => $this->distributionItem(
                        (int) $composition['two_groups'],
                        (int) $composition['composition_bookings'],
                    ),
                    'threeOrMore' => $this->distributionItem(
                        (int) $composition['three_or_more_groups'],
                        (int) $composition['composition_bookings'],
                    ),
                    'averagePerBooking' => $composition['average_groups'],
                ],
            ],
            'activeStatusLabels' => array_values(BookingPolicy::ACTIVE_STATUSES),
        ];
    }

    /** @return array{key: string, label: string, students: int} */
    private function sector(string $key, int $students): array
    {
        return [
            'key' => $key,
            'label' => (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$key]['label'],
            'students' => $students,
        ];
    }

    /** @return array{key: string, label: string, bookings: int, percentage: int, students: int, averageStudents: float} */
    private function program(string $key, int $bookings, int $students, float $average, int $denominator): array
    {
        return [
            'key' => $key,
            'label' => BookingPolicy::PROGRAM_LABELS[$key],
            'bookings' => $bookings,
            'percentage' => $this->percentage($bookings, $denominator),
            'students' => $students,
            'averageStudents' => $average,
        ];
    }

    /** @return array{bookings: int, percentage: int} */
    private function distributionItem(int $bookings, int $denominator): array
    {
        return ['bookings' => $bookings, 'percentage' => $this->percentage($bookings, $denominator)];
    }

    /**
     * @param list<array{module_key: string, bookings: int}> $rows
     * @return list<array{label: string, bookings: int, percentage: int}>
     */
    private function moduleDistribution(array $rows, int $denominator): array
    {
        $top = array_slice($rows, 0, 3);
        $result = array_map(fn (array $row): array => [
            'label' => BookingProgramConfig::MODULE_LABELS[$row['module_key']] ?? 'Onbekende module',
            'bookings' => $row['bookings'],
            'percentage' => $this->percentage($row['bookings'], $denominator),
        ], $top);
        $remaining = array_sum(array_column(array_slice($rows, 3), 'bookings'));
        if ($remaining > 0) {
            $result[] = [
                'label' => 'Overige modules',
                'bookings' => $remaining,
                'percentage' => $this->percentage($remaining, $denominator),
            ];
        }
        return $result;
    }

    private function percentage(int $value, int $denominator): int
    {
        return $denominator > 0 ? (int) round(($value / $denominator) * 100) : 0;
    }
}

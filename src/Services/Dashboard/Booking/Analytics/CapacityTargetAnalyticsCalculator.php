<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use GeoFort\Dashboard\CapacityTarget\CapacityTarget;
use GeoFort\Services\Sql\CapacityTargetSqlRepository;
use PDOException;
use Throwable;

final readonly class CapacityTargetAnalyticsCalculator
{
    private const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(private CapacityTargetSqlRepository $repository) {}

    /**
     * @param list<array<string, mixed>> $capacityRows
     * @param list<array<string, mixed>> $daySnapshots
     * @return array{capacityTargetByMonth:list<array<string,mixed>>,capacityTargetContext:array<string,mixed>}
     */
    public function calculate(array $capacityRows, array $daySnapshots, string $today): array
    {
        try {
            $targets = $this->repository->findEffectiveThrough('9999-12-31');
        } catch (Throwable $exception) {
            $this->logReadFailure($exception);

            return [
                'capacityTargetByMonth' => [],
                'capacityTargetContext' => $this->context('unavailable', $today, [], null, $daySnapshots),
            ];
        }

        $months = [];
        foreach ($capacityRows as $row) {
            $month = (string) $row['month'];
            $months[$month] = [
                'row' => $row,
                'periodState' => $this->monthRelation($month, $today),
                'evaluatedAvailableDays' => 0,
                'evaluatedStudentCapacity' => 0,
                'evaluatedBookingCapacity' => 0,
                'targetAvailableDays' => 0,
                'studentTarget' => 0,
                'bookingTarget' => 0.0,
                'targetActualStudents' => 0,
                'targetActualBookings' => 0,
            ];
        }

        $enrichedSnapshots = [];
        foreach ($daySnapshots as $snapshot) {
            $date = (string) $snapshot['date'];
            $month = (string) $snapshot['month'];
            $target = $this->targetOn($targets, $date);
            $enrichedSnapshots[] = $snapshot + ['officialTarget' => $target?->toArray()];
            if (!isset($months[$month])) continue;

            $evaluationIncluded = (bool) $snapshot['evaluationIncluded'];
            if ($evaluationIncluded && (bool) $snapshot['available']) {
                $months[$month]['evaluatedAvailableDays']++;
                $months[$month]['evaluatedStudentCapacity'] += (int) $snapshot['studentsCapacity'];
                $months[$month]['evaluatedBookingCapacity'] += (int) $snapshot['bookingsCapacity'];
            }
            if (!$evaluationIncluded || $target === null) continue;

            $months[$month]['targetActualStudents'] += (int) $snapshot['studentsActual'];
            $months[$month]['targetActualBookings'] += (int) $snapshot['bookingsActual'];
            if ((bool) $snapshot['available']) {
                $months[$month]['targetAvailableDays']++;
                $months[$month]['studentTarget'] += $target->studentsPerAvailableDay;
                $months[$month]['bookingTarget'] += $target->bookingsPerAvailableDay;
            }
        }

        $rows = array_map(function (array $month): array {
            $hasTarget = $month['targetAvailableDays'] > 0;
            $relation = $month['periodState'];
            $studentComparison = $this->comparison($month['targetActualStudents'], $month['studentTarget'], $hasTarget, $relation);
            $bookingComparison = $this->comparison($month['targetActualBookings'], $month['bookingTarget'], $hasTarget, $relation);
            $actualAverage = $month['targetActualBookings'] > 0
                ? $month['targetActualStudents'] / $month['targetActualBookings']
                : null;
            $targetAverage = $month['bookingTarget'] > 0
                ? $month['studentTarget'] / $month['bookingTarget']
                : null;

            return [
                ...$month['row'],
                'periodState' => $relation,
                'assessmentAvailable' => $relation !== 'future' && $hasTarget,
                'evaluatedAvailableDays' => $month['evaluatedAvailableDays'],
                'technicalCapacity' => [
                    'students' => $month['evaluatedStudentCapacity'],
                    'bookings' => $month['evaluatedBookingCapacity'],
                ],
                'targetAvailableDays' => $month['targetAvailableDays'],
                'studentsTargetComparison' => $studentComparison + [
                    'aboveTechnicalCapacity' => $studentComparison['actual'] !== null
                        && $studentComparison['actual'] > $month['evaluatedStudentCapacity'],
                ],
                'bookingsTargetComparison' => $bookingComparison + [
                    'aboveTechnicalCapacity' => $bookingComparison['actual'] !== null
                        && $bookingComparison['actual'] > $month['evaluatedBookingCapacity'],
                ],
                'averageBookingSizeComparison' => $this->averageComparison($actualAverage, $targetAverage, $hasTarget, $relation),
                'targetAboveTechnicalCapacity' => [
                    'students' => $hasTarget && $month['studentTarget'] > $month['evaluatedStudentCapacity'],
                    'bookings' => $hasTarget && $month['bookingTarget'] > $month['evaluatedBookingCapacity'],
                ],
            ];
        }, array_values($months));

        $current = $this->targetOn($targets, $today);

        return [
            'capacityTargetByMonth' => $rows,
            'capacityTargetContext' => $this->context('available', $today, $targets, $current, $enrichedSnapshots),
        ];
    }

    /** @param list<CapacityTarget> $targets */
    private function targetOn(array $targets, string $date): ?CapacityTarget
    {
        $effective = null;
        foreach ($targets as $target) {
            if ($target->effectiveDate > $date) break;
            $effective = $target;
        }
        return $effective;
    }

    private function monthRelation(string $month, string $today): string
    {
        $todayMonth = substr($today, 0, 7);
        return $month < $todayMonth ? 'closed' : ($month > $todayMonth ? 'future' : 'current');
    }

    /** @return array<string, int|float|string|bool|null> */
    private function comparison(int $actual, int|float $target, bool $hasTarget, string $relation): array
    {
        $difference = $hasTarget ? $actual - $target : null;

        return [
            'actual' => $hasTarget ? $actual : null,
            'target' => $hasTarget ? $target : null,
            'difference' => $difference,
            'percentageOfTarget' => $hasTarget && $target > 0 ? ($actual / $target) * 100 : null,
            'differencePercentage' => $hasTarget && $target > 0 ? ($difference / $target) * 100 : null,
            'assessment' => !$hasTarget
                ? 'missingTarget'
                : ($relation === 'future' ? 'future' : ($difference < 0 ? 'below' : ($difference > 0 ? 'above' : 'onTarget'))),
        ];
    }

    /** @return array<string, int|float|string|bool|null> */
    private function averageComparison(?float $actual, ?float $target, bool $hasTarget, string $relation): array
    {
        $difference = $actual !== null && $target !== null ? $actual - $target : null;

        return [
            'actual' => $hasTarget ? $actual : null,
            'target' => $hasTarget ? $target : null,
            'difference' => $difference,
            'percentageOfTarget' => $actual !== null && $target !== null && $target > 0 ? ($actual / $target) * 100 : null,
            'differencePercentage' => $difference !== null && $target !== null && $target > 0 ? ($difference / $target) * 100 : null,
            'assessment' => !$hasTarget
                ? 'missingTarget'
                : ($target === null || $actual === null
                    ? 'unavailable'
                    : ($relation === 'future' ? 'future' : ($difference < 0 ? 'below' : ($difference > 0 ? 'above' : 'onTarget')))),
            'aboveTechnicalCapacity' => false,
        ];
    }

    /**
     * @param list<CapacityTarget> $history
     * @param list<array<string,mixed>> $daySnapshots
     * @return array<string,mixed>
     */
    private function context(string $status, string $today, array $history, ?CapacityTarget $current, array $daySnapshots): array
    {
        return [
            'status' => $status,
            'today' => $today,
            'timezone' => self::TIMEZONE,
            'canManage' => false,
            'currentOfficialTarget' => $current?->toArray(),
            'history' => array_map(static fn (CapacityTarget $target): array => $target->toArray(), $history),
            'daySnapshots' => $daySnapshots,
        ];
    }

    private function logReadFailure(Throwable $exception): void
    {
        $sqlState = $exception instanceof PDOException
            ? (string) ($exception->errorInfo[0] ?? $exception->getCode())
            : 'n/a';
        error_log(sprintf(
            '[CapacityTargetAnalyticsCalculator] action=load_capacity_targets result=database_error exception=%s sqlstate=%s message=%s',
            $exception::class,
            $sqlState !== '' ? $sqlState : 'n/a',
            'Targetanalytics konden niet uit de database worden gelezen.',
        ));
    }
}

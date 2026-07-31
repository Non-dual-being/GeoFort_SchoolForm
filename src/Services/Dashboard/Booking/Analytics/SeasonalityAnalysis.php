<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class SeasonalityAnalysis
{
    /** @param list<MonthlyBucket> $monthlyBuckets @param list<WeekdayBucket> $weekdayBuckets @param list<VisitDateBucket> $visitDateBuckets @param array<string, array<string, string>> $availableMetricsByView @param array<string, int> $summary @param list<TopDay> $topDays */
    public function __construct(public array $monthlyBuckets, public array $weekdayBuckets, public array $visitDateBuckets, public array $availableMetricsByView, public array $summary, public array $topDays, public SchoolOccupancyAnalysis $schoolOccupancy) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return [
        'monthlyBuckets' => array_map(static fn (MonthlyBucket $bucket): array => $bucket->toArray(), $this->monthlyBuckets),
        'weekdayBuckets' => array_map(static fn (WeekdayBucket $bucket): array => $bucket->toArray(), $this->weekdayBuckets),
        'visitDateBuckets' => array_map(static fn (VisitDateBucket $bucket): array => $bucket->toArray(), $this->visitDateBuckets),
        'availableMetricsByView' => $this->availableMetricsByView,
        'summary' => $this->summary,
        'topDays' => array_map(static fn (TopDay $day): array => $day->toArray(), $this->topDays),
        'schoolOccupancy' => $this->schoolOccupancy->toArray(),
    ]; }
}

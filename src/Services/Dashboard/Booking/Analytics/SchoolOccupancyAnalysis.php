<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class SchoolOccupancyAnalysis
{
    /** @param list<SchoolOccupancyCategory> $categories @param array<string, string> $context */
    public function __construct(public int $totalBookedVisitDates, public float $averageSchoolsPerVisitDate, public array $categories, public array $context, public int $overCapacityVisitDateCount) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return ['totalBookedVisitDates' => $this->totalBookedVisitDates, 'averageSchoolsPerVisitDate' => $this->averageSchoolsPerVisitDate, 'categories' => array_map(static fn (SchoolOccupancyCategory $category): array => $category->toArray(), $this->categories), 'context' => $this->context, 'overCapacityVisitDateCount' => $this->overCapacityVisitDateCount]; }
}

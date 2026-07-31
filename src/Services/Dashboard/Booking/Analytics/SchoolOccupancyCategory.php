<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class SchoolOccupancyCategory
{
    public function __construct(public string $key, public string $label, public ?int $schoolCountMinimum, public ?int $schoolCountMaximum, public int $visitDateCount, public float $percentageOfBookedVisitDates, public int $bookingCount, public int $studentCount, public float $averageStudentsPerVisitDate, public float $averageBookingsPerVisitDate, public bool $isDataQualityCategory) {}
    /** @return array<string, int|float|string|bool|null> */
    public function toArray(): array { return get_object_vars($this); }
}

<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class MonthlyBucket
{
    public function __construct(public int $year, public int $month, public string $label, public int $bookings, public int $uniqueVisitDates, public int $uniqueSchools, public int $students, public float $averageStudentsPerVisitDate) {}
    /** @return array<string, int|float|string> */
    public function toArray(): array { return get_object_vars($this); }
}

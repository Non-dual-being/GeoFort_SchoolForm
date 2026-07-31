<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class VisitDateBucket
{
    /** @param list<string> $programs */
    public function __construct(public string $date, public int $weekdayNumber, public string $weekdayLabel, public int $bookings, public int $definitiveBookings, public int $uniqueSchools, public int $students, public float $averageStudentsPerBooking, public int $dayProgramBookings, public int $morningProgramBookings, public array $programs) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return get_object_vars($this); }
}

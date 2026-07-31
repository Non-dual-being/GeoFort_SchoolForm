<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class WeekdayBucket
{
    public function __construct(public int $weekdayNumber, public string $weekdayKey, public string $weekdayLabel, public int $bookings, public int $definitiveBookings, public int $uniqueVisitDates, public int $uniqueSchools, public int $students, public float $averageStudentsPerVisitDate, public float $averageStudentsPerBooking, public float $averageBookingsPerVisitDate, public float $averageSchoolsPerVisitDate, public int $dayProgramBookings, public int $morningProgramBookings, public string $programLabel) {}
    /** @return array<string, int|float|string> */
    public function toArray(): array { return get_object_vars($this); }
}

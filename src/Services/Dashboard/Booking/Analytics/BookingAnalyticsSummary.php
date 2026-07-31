<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class BookingAnalyticsSummary
{
    public function __construct(
        public int $activeBookings,
        public int $confirmedBookings,
        public int $optionBookings,
        public int $rejectedBookings,
        public int $plannedStudents,
        public int $uniqueSchools,
        public int $uniqueVisitDays,
        public float $averageStudentsPerActiveBooking,
        public float $averageStudentsPerVisitDay,
        public int $rejectionPercentage,
    ) {}

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

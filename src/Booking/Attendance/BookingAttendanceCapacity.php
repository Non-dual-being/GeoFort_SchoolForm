<?php
declare(strict_types=1);
namespace GeoFort\Booking\Attendance;

final readonly class BookingAttendanceCapacity
{
    public function __construct(
        public int $confirmedSchoolsExcludingBooking,
        public int $confirmedStudentsExcludingBooking,
        public int $previousBookingStudents,
        public int $proposedBookingStudents,
        public int $projectedSchools,
        public int $projectedStudents,
        public int $maximumSchools,
        public int $maximumStudents,
    ) {}
}

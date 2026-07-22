<?php
declare(strict_types=1);
namespace GeoFort\Booking\Attendance;

use GeoFort\Booking\Rules\BookingRuleOverrideRequest;

final readonly class BookingAttendanceChangeCommand
{
    /** @param list<BookingRuleOverrideRequest> $requestedOverrides */
    public function __construct(
        public int $bookingId,
        public int $expectedStudentCount,
        public int $expectedSupervisorCount,
        public int $newStudentCount,
        public int $newSupervisorCount,
        public int $actingAdminId,
        public array $requestedOverrides = [],
    ) {}
}

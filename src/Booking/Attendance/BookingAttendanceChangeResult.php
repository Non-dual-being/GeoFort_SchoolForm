<?php
declare(strict_types=1);
namespace GeoFort\Booking\Attendance;

use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use GeoFort\Booking\Validation\StoredBookingIssue;

final readonly class BookingAttendanceChangeResult
{
    /** @param list<StoredBookingIssue> $validationIssues @param list<UsedBookingRuleOverride> $overriddenRules */
    public function __construct(
        public BookingAttendanceChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?int $previousStudentCount = null,
        public ?int $currentStudentCount = null,
        public ?int $previousSupervisorCount = null,
        public ?int $currentSupervisorCount = null,
        public array $validationIssues = [],
        public ?BookingAttendanceCapacity $capacity = null,
        public array $overriddenRules = [],
        public ?int $changeHistoryId = null,
    ) {}
}

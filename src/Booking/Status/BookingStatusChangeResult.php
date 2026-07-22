<?php

declare(strict_types=1);

namespace GeoFort\Booking\Status;

use GeoFort\Booking\Capacity\CapacityValidationResult;
use GeoFort\Booking\Validation\StoredBookingIssue;
use GeoFort\Booking\Rules\UsedBookingRuleOverride;

final readonly class BookingStatusChangeResult
{
    /** @param list<StoredBookingIssue> $validationIssues */
    public function __construct(
        public BookingStatusChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?string $previousStatus,
        public ?string $currentStatus,
        public array $validationIssues = [],
        public ?CapacityValidationResult $capacityResult = null,
        public BookingStatusMailMode $mailMode = BookingStatusMailMode::None,
        public bool $mailSent = false,
        /** @var list<UsedBookingRuleOverride> */
        public array $overriddenRules = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

final readonly class BookingValidationContext
{
    /**
     * @param list<StoredBookingIssue> $baseIssues
     */
    public function __construct(
        public array $baseIssues = [],
        public ?StoredBookingIssue $capacityIssue = null,
    ) {}
}

<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

use GeoFort\Booking\Rules\BookingRuleSeverity;

final readonly class StoredBookingIssue
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        public string $code,
        public StoredBookingIssueCategory $category,
        public string $field,
        public BookingRuleSeverity $severity = BookingRuleSeverity::Error,
        public bool $overridable = false,
        public string $title = '',
        public string $description = '',
        public array $metadata = [],
    ) {}
}

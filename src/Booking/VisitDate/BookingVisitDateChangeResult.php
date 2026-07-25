<?php
declare(strict_types=1);
namespace GeoFort\Booking\VisitDate;

use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use GeoFort\Booking\Validation\StoredBookingIssue;

final readonly class BookingVisitDateChangeResult
{
    /** @param list<StoredBookingIssue> $validationIssues @param list<UsedBookingRuleOverride> $overriddenRules */
    public function __construct(
        public BookingVisitDateChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?string $previousVisitDate=null,
        public ?string $currentVisitDate=null,
        public array $validationIssues=[],
        public array $overriddenRules=[],
        public ?int $changeHistoryId=null,
    ) {}
}

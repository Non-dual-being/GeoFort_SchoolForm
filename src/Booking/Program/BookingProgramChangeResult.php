<?php
declare(strict_types=1);
namespace GeoFort\Booking\Program;

use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use GeoFort\Booking\Validation\StoredBookingIssue;

final readonly class BookingProgramChangeResult
{
    /** @param list<StoredBookingIssue> $validationIssues @param list<UsedBookingRuleOverride> $overriddenRules */
    public function __construct(
        public BookingProgramChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?string $previousProgram=null,
        public ?string $currentProgram=null,
        public array $validationIssues=[],
        public array $overriddenRules=[],
        public ?int $changeHistoryId=null,
    ) {}
}

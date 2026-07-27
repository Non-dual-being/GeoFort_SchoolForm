<?php
declare(strict_types=1);
namespace GeoFort\Booking\ProgramConfiguration;

use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use GeoFort\Booking\Validation\StoredBookingIssue;

final readonly class BookingProgramConfigurationResult
{
    /** @param list<StoredBookingIssue> $issues @param list<UsedBookingRuleOverride> $overrides */
    public function __construct(
        public BookingProgramConfigurationCode $code,
        public bool $success,
        public int $bookingId,
        public ?BookingProgramConfigurationSnapshot $current = null,
        public array $issues = [],
        public array $overrides = [],
        public ?int $changeHistoryId = null,
    ) {}
}

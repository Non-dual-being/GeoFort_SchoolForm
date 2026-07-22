<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

final readonly class UsedBookingRuleOverride
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        public string $ruleCode,
        public string $reason,
        public string $contextFingerprint,
        public array $metadata,
    ) {}
}

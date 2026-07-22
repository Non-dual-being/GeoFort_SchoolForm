<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

final readonly class BookingRuleDefinition
{
    public function __construct(
        public string $code,
        public BookingRuleSeverity $severity,
        public bool $overridable,
        public ?string $requiredPermission,
        public string $title,
        public string $description,
    ) {}
}

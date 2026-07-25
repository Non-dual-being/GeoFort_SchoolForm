<?php
declare(strict_types=1);
namespace GeoFort\Booking\VisitDate;

use GeoFort\Booking\Rules\BookingRuleOverrideRequest;

final readonly class BookingVisitDateChangeCommand
{
    /** @param list<BookingRuleOverrideRequest> $requestedOverrides */
    public function __construct(
        public int $bookingId,
        public string $expectedVisitDate,
        public string $proposedVisitDate,
        public int $actingAdminId,
        public array $requestedOverrides=[],
    ) {}
}

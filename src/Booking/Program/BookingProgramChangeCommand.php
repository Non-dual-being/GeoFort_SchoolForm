<?php
declare(strict_types=1);
namespace GeoFort\Booking\Program;

use GeoFort\Booking\Rules\BookingRuleOverrideRequest;

final readonly class BookingProgramChangeCommand
{
    /** @param list<BookingRuleOverrideRequest> $requestedOverrides */
    public function __construct(
        public int $bookingId,
        public string $expectedProgram,
        public string $proposedProgram,
        public int $actingAdminId,
        public array $requestedOverrides=[],
    ) {}
}

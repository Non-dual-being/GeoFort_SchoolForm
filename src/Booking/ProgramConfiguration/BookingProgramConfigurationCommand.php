<?php
declare(strict_types=1);
namespace GeoFort\Booking\ProgramConfiguration;

use GeoFort\Booking\Rules\BookingRuleOverrideRequest;

final readonly class BookingProgramConfigurationCommand
{
    /** @param list<BookingRuleOverrideRequest> $requestedOverrides */
    public function __construct(
        public int $bookingId,
        public BookingProgramConfigurationSnapshot $expected,
        public BookingProgramConfigurationSnapshot $proposed,
        public int $actingAdminId,
        public array $requestedOverrides = [],
    ) {}
}

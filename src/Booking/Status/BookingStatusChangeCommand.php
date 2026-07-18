<?php

declare(strict_types=1);

namespace GeoFort\Booking\Status;

final readonly class BookingStatusChangeCommand
{
    public function __construct(
        public int $bookingId,
        public string $expectedCurrentStatus,
        public string $targetStatus,
        public int $actingAdminId,
    ) {}
}

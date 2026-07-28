<?php

declare(strict_types=1);

namespace GeoFort\Booking\Cjp;

final readonly class BookingCjpChangeCommand
{
    public function __construct(
        public int $bookingId,
        public BookingCjpDetails $expected,
        public BookingCjpDetails $proposed,
        public int $actingAdminId,
    ) {}
}

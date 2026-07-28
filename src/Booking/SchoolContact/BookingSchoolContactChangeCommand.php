<?php

declare(strict_types=1);

namespace GeoFort\Booking\SchoolContact;

final readonly class BookingSchoolContactChangeCommand
{
    public function __construct(
        public int $bookingId,
        public BookingSchoolContactDetails $expected,
        public BookingSchoolContactDetails $proposed,
        public int $actingAdminId,
    ) {}
}

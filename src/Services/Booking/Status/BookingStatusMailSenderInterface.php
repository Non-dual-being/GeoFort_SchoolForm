<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Status;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;

interface BookingStatusMailSenderInterface
{
    public function sendConfirmation(StoredBooking $booking, BookingPriceQuote $quote): void;

    public function sendRejection(StoredBooking $booking): void;
}

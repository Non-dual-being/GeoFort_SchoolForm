<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Status;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Mail\Attachment;

interface BookingPlannerStatusMailSenderInterface extends BookingStatusMailSenderInterface
{
    public function sendPlannerConfirmation(StoredBooking $booking, BookingPriceQuote $quote, Attachment $roster): void;
}

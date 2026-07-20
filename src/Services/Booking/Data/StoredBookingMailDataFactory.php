<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Data;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Utils\DateParser;

final class StoredBookingMailDataFactory
{
    public function fromStoredBooking(StoredBooking $booking): BookingRequestData
    {
        return new BookingRequestData(
            $booking->schoolName, $booking->country, $booking->address, $booking->postalCode,
            $booking->city, $booking->schoolPhone, $booking->contactPhone,
            $booking->contactFirstName, $booking->contactLastName, $booking->email,
            $booking->visitDate, DateParser::getLongDutchDateFromString($booking->visitDate),
            $booking->discoverySource ?? '', $booking->cjpPassUse, $booking->cjpContactName,
            $booking->cjpPassNumber, $booking->schoolSector, $booking->program,
            $booking->choiceModuleKey, $booking->studentCount ?? 0, $booking->supervisorCount ?? 0,
            $booking->educationSelection, $booking->foodAndDrinkSelection, $booking->comments,
            $booking->termsAccepted,
        );
    }
}

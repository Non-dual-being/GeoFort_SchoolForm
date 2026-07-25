<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

enum BookingCateringChangeCode: string
{
    case Success = 'SUCCESS';
    case NoCateringChange = 'NO_CATERING_CHANGE';
    case BookingNotFound = 'BOOKING_NOT_FOUND';
    case InvalidRequest = 'INVALID_REQUEST';
    case InvalidCateringSelection = 'INVALID_CATERING_SELECTION';
    case InvalidStoredBooking = 'INVALID_STORED_BOOKING';
    case CateringConflict = 'CATERING_CONFLICT';
    case DatabaseError = 'DATABASE_ERROR';
}

<?php

declare(strict_types=1);

namespace GeoFort\Booking\Cjp;

enum BookingCjpChangeCode: string
{
    case Success = 'SUCCESS';
    case NoChange = 'NO_CJP_CHANGE';
    case BookingNotFound = 'BOOKING_NOT_FOUND';
    case InvalidDetails = 'INVALID_CJP_DETAILS';
    case Conflict = 'CJP_CONFLICT';
    case DatabaseError = 'DATABASE_ERROR';
}

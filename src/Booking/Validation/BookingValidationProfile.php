<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

enum BookingValidationProfile
{
    case ConfirmBooking;
    case ChangeAttendanceDraft;
    case ChangeAttendanceConfirmed;
}

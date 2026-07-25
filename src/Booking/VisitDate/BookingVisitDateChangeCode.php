<?php
declare(strict_types=1);
namespace GeoFort\Booking\VisitDate;

enum BookingVisitDateChangeCode: string
{
    case Success='SUCCESS';
    case NoVisitDateChange='NO_VISIT_DATE_CHANGE';
    case BookingNotFound='BOOKING_NOT_FOUND';
    case InvalidRequest='INVALID_REQUEST';
    case InvalidVisitDate='INVALID_VISIT_DATE';
    case InvalidStoredBooking='INVALID_STORED_BOOKING';
    case OverrideRequired='OVERRIDE_REQUIRED';
    case InvalidOverrideRequest='INVALID_OVERRIDE_REQUEST';
    case OverrideNotAllowed='OVERRIDE_NOT_ALLOWED';
    case OverridePermissionDenied='OVERRIDE_PERMISSION_DENIED';
    case VisitDateConflict='VISIT_DATE_CONFLICT';
    case DatabaseError='DATABASE_ERROR';
}

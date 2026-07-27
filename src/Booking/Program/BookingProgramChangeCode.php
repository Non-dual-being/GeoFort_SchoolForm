<?php
declare(strict_types=1);
namespace GeoFort\Booking\Program;

enum BookingProgramChangeCode:string
{
    case Success='SUCCESS';
    case NoProgramChange='NO_PROGRAM_CHANGE';
    case BookingNotFound='BOOKING_NOT_FOUND';
    case InvalidRequest='INVALID_REQUEST';
    case InvalidProgramSelection='INVALID_PROGRAM_SELECTION';
    case InvalidStoredBooking='INVALID_STORED_BOOKING';
    case OverrideRequired='OVERRIDE_REQUIRED';
    case InvalidOverrideRequest='INVALID_OVERRIDE_REQUEST';
    case OverrideNotAllowed='OVERRIDE_NOT_ALLOWED';
    case OverridePermissionDenied='OVERRIDE_PERMISSION_DENIED';
    case ProgramConflict='PROGRAM_CONFLICT';
    case DatabaseError='DATABASE_ERROR';
}

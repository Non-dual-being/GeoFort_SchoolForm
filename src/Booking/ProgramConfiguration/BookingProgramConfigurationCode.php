<?php
declare(strict_types=1);
namespace GeoFort\Booking\ProgramConfiguration;

enum BookingProgramConfigurationCode: string
{
    case Success = 'SUCCESS';
    case NoChange = 'NO_PROGRAM_CONFIGURATION_CHANGE';
    case NotFound = 'BOOKING_NOT_FOUND';
    case Conflict = 'PROGRAM_CONFIGURATION_CONFLICT';
    case InvalidRequest = 'INVALID_REQUEST';
    case InvalidConfiguration = 'INVALID_PROGRAM_CONFIGURATION';
    case OverrideRequired = 'OVERRIDE_REQUIRED';
    case InvalidOverrideRequest = 'INVALID_OVERRIDE_REQUEST';
    case OverrideNotAllowed = 'OVERRIDE_NOT_ALLOWED';
    case OverridePermissionDenied = 'OVERRIDE_PERMISSION_DENIED';
    case DatabaseError = 'DATABASE_ERROR';
}

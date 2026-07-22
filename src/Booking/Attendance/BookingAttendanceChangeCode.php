<?php
declare(strict_types=1);
namespace GeoFort\Booking\Attendance;

enum BookingAttendanceChangeCode: string
{
    case Success = 'SUCCESS';
    case NoChanges = 'NO_CHANGES';
    case BookingNotFound = 'BOOKING_NOT_FOUND';
    case AttendanceConflict = 'ATTENDANCE_CONFLICT';
    case InvalidRequest = 'INVALID_REQUEST';
    case InvalidStoredBooking = 'INVALID_STORED_BOOKING';
    case OverrideRequired = 'OVERRIDE_REQUIRED';
    case InvalidOverrideRequest = 'INVALID_OVERRIDE_REQUEST';
    case OverrideNotAllowed = 'OVERRIDE_NOT_ALLOWED';
    case OverridePermissionDenied = 'OVERRIDE_PERMISSION_DENIED';
    case DatabaseError = 'DATABASE_ERROR';
}

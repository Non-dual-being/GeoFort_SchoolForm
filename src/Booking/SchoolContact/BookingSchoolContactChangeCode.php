<?php

declare(strict_types=1);

namespace GeoFort\Booking\SchoolContact;

enum BookingSchoolContactChangeCode: string
{
    case Success = 'SUCCESS';
    case NoChange = 'NO_SCHOOL_CONTACT_CHANGE';
    case BookingNotFound = 'BOOKING_NOT_FOUND';
    case InvalidRequest = 'INVALID_REQUEST';
    case InvalidDetails = 'INVALID_SCHOOL_CONTACT_DETAILS';
    case Conflict = 'SCHOOL_CONTACT_CONFLICT';
    case DatabaseError = 'DATABASE_ERROR';
}

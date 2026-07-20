<?php

declare(strict_types=1);

namespace GeoFort\Booking\Status;

enum BookingStatusChangeCode: string
{
    case Success = 'SUCCESS';
    case BookingNotFound = 'BOOKING_NOT_FOUND';
    case StatusConflict = 'STATUS_CONFLICT';
    case NoStatusChange = 'NO_STATUS_CHANGE';
    case InvalidCurrentStatus = 'INVALID_CURRENT_STATUS';
    case InvalidTargetStatus = 'INVALID_TARGET_STATUS';
    case InvalidStoredBooking = 'INVALID_STORED_BOOKING';
    case HistoricalDate = 'HISTORICAL_DATE';
    case DisabledDate = 'DISABLED_DATE';
    case SchoolLimitExceeded = 'SCHOOL_LIMIT_EXCEEDED';
    case StudentLimitExceeded = 'STUDENT_LIMIT_EXCEEDED';
    case InvalidStudentCount = 'INVALID_STUDENT_COUNT';
    case MailNotSupportedForTargetStatus = 'MAIL_NOT_SUPPORTED_FOR_TARGET_STATUS';
    case MailSendFailed = 'MAIL_SEND_FAILED';
    case DatabaseError = 'DATABASE_ERROR';
}

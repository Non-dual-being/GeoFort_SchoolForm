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
    case OverrideRequired = 'OVERRIDE_REQUIRED';
    case InvalidOverrideRequest = 'INVALID_OVERRIDE_REQUEST';
    case OverrideNotAllowed = 'OVERRIDE_NOT_ALLOWED';
    case OverrideReasonRequired = 'OVERRIDE_REASON_REQUIRED';
    case OverridePermissionDenied = 'OVERRIDE_PERMISSION_DENIED';
    case MailNotSupportedForTargetStatus = 'MAIL_NOT_SUPPORTED_FOR_TARGET_STATUS';
    case MailSendFailed = 'MAIL_SEND_FAILED';
    case MailStatusRecordingFailed = 'MAIL_STATUS_RECORDING_FAILED';
    case LegacyPriceAcceptanceRequired = 'LEGACY_PRICE_ACCEPTANCE_REQUIRED';
    case PriceSnapshotRequired = 'PRICE_SNAPSHOT_REQUIRED';
    case DatabaseError = 'DATABASE_ERROR';
}

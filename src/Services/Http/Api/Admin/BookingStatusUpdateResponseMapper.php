<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeResult;

final class BookingStatusUpdateResponseMapper
{
    /** @return array{status: int, payload: array<string, mixed>} */
    public function map(BookingStatusChangeResult $result): array
    {
        return ['status' => $this->httpStatus($result->code), 'payload' => [
            'ok' => $result->success,
            'code' => $result->code->value,
            'bookingId' => $result->bookingId,
            'previousStatus' => $result->previousStatus,
            'currentStatus' => $result->currentStatus,
            'mailMode' => $result->mailMode->value,
            'mailSent' => $result->mailSent,
            'validationIssues' => array_map(static fn ($issue): array => [
                'code' => $issue->code, 'category' => $issue->category->value, 'field' => $issue->field,
            ], $result->validationIssues),
            'capacity' => $result->capacityResult === null ? null : [
                'code' => $result->capacityResult->code->value,
                'allowed' => $result->capacityResult->allowed,
                'projectedSchools' => $result->capacityResult->projectedSchools,
                'projectedStudents' => $result->capacityResult->projectedStudents,
            ],
        ]];
    }

    public function httpStatus(BookingStatusChangeCode $code): int
    {
        return match ($code) {
            BookingStatusChangeCode::Success => 200,
            BookingStatusChangeCode::BookingNotFound => 404,
            BookingStatusChangeCode::StatusConflict, BookingStatusChangeCode::NoStatusChange => 409,
            BookingStatusChangeCode::InvalidCurrentStatus, BookingStatusChangeCode::InvalidTargetStatus,
            BookingStatusChangeCode::InvalidStoredBooking, BookingStatusChangeCode::HistoricalDate,
            BookingStatusChangeCode::DisabledDate, BookingStatusChangeCode::SchoolLimitExceeded,
            BookingStatusChangeCode::StudentLimitExceeded, BookingStatusChangeCode::InvalidStudentCount => 422,
            BookingStatusChangeCode::MailNotSupportedForTargetStatus => 422,
            BookingStatusChangeCode::MailSendFailed => 502,
            BookingStatusChangeCode::DatabaseError => 500,
        };
    }
}

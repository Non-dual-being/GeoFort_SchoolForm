<?php
declare(strict_types=1);

use GeoFort\Booking\Capacity\CapacityValidationCode;
use GeoFort\Booking\Capacity\CapacityValidationResult;
use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeResult;
use GeoFort\Booking\Validation\StoredBookingIssue;
use GeoFort\Booking\Validation\StoredBookingIssueCategory;
use GeoFort\Services\Http\Api\Admin\BookingStatusUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingStatusUpdateRequestException;
use GeoFort\Services\Http\Api\Admin\BookingStatusUpdateResponseMapper;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (string $json, string $code) use ($assert): void {
    try { BookingStatusUpdateRequest::fromJson($json); }
    catch (BookingStatusUpdateRequestException $e) { $assert($e->publicCode === $code, "Expected $code"); return; }
    throw new RuntimeException("Request should be rejected as $code");
};

$valid = BookingStatusUpdateRequest::fromJson('{"bookingId":123,"expectedCurrentStatus":"In optie","targetStatus":"Definitief"}');
$assert($valid->bookingId === 123 && $valid->targetStatus === 'Definitief', 'Valid request parsing failed');
$rejects('{', 'MALFORMED_JSON');
$rejects('{"expectedCurrentStatus":"In optie","targetStatus":"Definitief"}', 'INVALID_REQUEST');
$rejects('{"bookingId":0,"expectedCurrentStatus":"In optie","targetStatus":"Definitief"}', 'INVALID_REQUEST');
$rejects('{"bookingId":1,"targetStatus":"Definitief"}', 'INVALID_REQUEST');
$rejects('{"bookingId":1,"expectedCurrentStatus":"In optie"}', 'INVALID_REQUEST');
$rejects('{"bookingId":1,"expectedCurrentStatus":"Onbekend","targetStatus":"Definitief"}', 'INVALID_CURRENT_STATUS');
$rejects('{"bookingId":1,"expectedCurrentStatus":"In optie","targetStatus":"Onbekend"}', 'INVALID_TARGET_STATUS');
$rejects('{"bookingId":1,"expectedCurrentStatus":"In optie","targetStatus":"Definitief","actingAdminId":999}', 'INVALID_REQUEST');

$mapper = new BookingStatusUpdateResponseMapper();
$statuses = [
    [BookingStatusChangeCode::Success, 200], [BookingStatusChangeCode::BookingNotFound, 404],
    [BookingStatusChangeCode::StatusConflict, 409], [BookingStatusChangeCode::NoStatusChange, 409],
    [BookingStatusChangeCode::InvalidCurrentStatus, 422], [BookingStatusChangeCode::InvalidTargetStatus, 422],
    [BookingStatusChangeCode::InvalidStoredBooking, 422], [BookingStatusChangeCode::HistoricalDate, 422],
    [BookingStatusChangeCode::DisabledDate, 422], [BookingStatusChangeCode::SchoolLimitExceeded, 422],
    [BookingStatusChangeCode::StudentLimitExceeded, 422], [BookingStatusChangeCode::InvalidStudentCount, 422],
    [BookingStatusChangeCode::DatabaseError, 500],
];
foreach ($statuses as [$code, $httpStatus]) $assert($mapper->httpStatus($code) === $httpStatus, "Wrong status for {$code->value}");

$mapped = $mapper->map(new BookingStatusChangeResult(
    BookingStatusChangeCode::StudentLimitExceeded, false, 123, 'In optie', 'In optie',
    [new StoredBookingIssue('SAFE_CODE', StoredBookingIssueCategory::Policy, 'aantalLeerlingen')],
    new CapacityValidationResult(CapacityValidationCode::StudentLimitExceeded, false, 2, 130),
));
$assert(array_keys($mapped['payload']['validationIssues'][0]) === ['code', 'category', 'field'], 'Issue leaks unsafe fields');
$assert(array_keys($mapped['payload']['capacity']) === ['code', 'allowed', 'projectedSchools', 'projectedStudents'], 'Capacity contract mismatch');

echo "Booking status update HTTP contract tests passed.\n";

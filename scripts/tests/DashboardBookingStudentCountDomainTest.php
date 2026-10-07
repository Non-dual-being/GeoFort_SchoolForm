<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Validation\StoredBookingStudentCountValidator;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequestException;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\FieldValidationException;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$admin = new StoredBookingStudentCountValidator(); $public = new StudentCountValidator();
foreach (BookingProgramConfig::SCHOOL_TYPES_BY_KEY as $sector => $_) foreach (BookingProgramConfig::PROGRAMS as $program => $config) {
    if (!in_array($sector, $config['allowedSchoolTypes'], true)) continue;
    foreach ([1, 28, 39, 40, 80, 160, 161, 250, 1001] as $count) {
        $assert($admin->validate($count, $sector, $program) === $count, 'Admin count rejected.');
        $dto = BookingAttendanceUpdateRequest::fromJson(json_encode(['bookingId' => 1, 'expectedStudentCount' => 40, 'expectedSupervisorCount' => 3, 'studentCount' => $count, 'supervisorCount' => 3], JSON_THROW_ON_ERROR));
        $assert($dto->studentCount === $count, 'Attendance DTO changed count.');
        $allowed = $count >= 40 && $count <= ($sector === 'primairOnderwijs' && $program === 'ochtend' ? 80 : 160);
        try { $public->validate($count, $sector, $program); $assert($allowed, 'PUBLIC LIMIT WEAKENED.'); }
        catch (FieldValidationException) { $assert(!$allowed, 'Public normal range rejected.'); }
    }
}
foreach ([0, -1, 12.5, '28', 'wrong', null, true, [], new stdClass()] as $invalid) {
    try { $admin->validate($invalid, 'primairOnderwijs', 'dag'); throw new RuntimeException('Invalid admin count accepted.'); } catch (FieldValidationException) {}
    try { BookingAttendanceUpdateRequest::fromJson(json_encode(['bookingId' => 1, 'expectedStudentCount' => 40, 'expectedSupervisorCount' => 3, 'studentCount' => $invalid, 'supervisorCount' => 3], JSON_THROW_ON_ERROR)); throw new RuntimeException('Invalid attendance DTO accepted.'); } catch (BookingAttendanceUpdateRequestException) {}
}
echo "OK: admin accepts positive integers; public PO morning 40..80 and all other available programs 40..160 remain hard limits.\n";
